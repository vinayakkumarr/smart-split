<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Request;
use App\Repositories\GroupRepository;
use App\Repositories\MemberRepository;
use App\Repositories\SettlementRepository;
use App\Services\BalanceService;
use App\Utils\Money;
use InvalidArgumentException;
use RuntimeException;

/**
 * Controller handling Debt Settlement tracking, verification lifecycle, and reversals.
 */
class SettlementController extends BaseController
{
    private GroupRepository $groupRepo;
    private MemberRepository $memberRepo;
    private SettlementRepository $settlementRepo;
    private BalanceService $balanceService;

    public function __construct(
        ?GroupRepository $groupRepo = null,
        ?MemberRepository $memberRepo = null,
        ?SettlementRepository $settlementRepo = null,
        ?BalanceService $balanceService = null
    ) {
        $this->groupRepo = $groupRepo ?? new GroupRepository();
        $this->memberRepo = $memberRepo ?? new MemberRepository();
        $this->settlementRepo = $settlementRepo ?? new SettlementRepository();
        $this->balanceService = $balanceService ?? new BalanceService();
    }

    /**
     * POST /api/groups/{token}/settlements
     * Record a direct settlement payment between two members.
     */
    public function create(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $body = $request->getBody();

        $idempotencyKey = $request->getHeader('X-Idempotency-Key') ?? ($body['idempotency_key'] ?? null);
        if ($idempotencyKey !== null) {
            $idempotencyKey = trim((string) $idempotencyKey);
            if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
                $idempotencyKey = null;
            }
        }

        $payerId = (int) ($body['payer_id'] ?? $body['payer_member_id'] ?? 0);
        $payeeId = (int) ($body['payee_id'] ?? $body['payee_member_id'] ?? 0);

        if ($payerId <= 0 || $payeeId <= 0) {
            throw new InvalidArgumentException("Valid payer_id and payee_id are required.", 422);
        }

        if ($payerId === $payeeId) {
            throw new InvalidArgumentException("Payer and payee cannot be the same member.", 422);
        }

        // Validate members exist in this group
        $groupMembers = $this->memberRepo->findByGroupId((int) $group['id']);
        $validMemberIds = array_column($groupMembers, 'id');

        if (!in_array($payerId, $validMemberIds, true) || !in_array($payeeId, $validMemberIds, true)) {
            throw new InvalidArgumentException("One or both members do not belong to this group.", 422);
        }

        // Parse amount
        if (!isset($body['amount']) && !isset($body['amount_cents'])) {
            throw new InvalidArgumentException("Settlement amount is required.", 422);
        }

        $amountCents = isset($body['amount_cents'])
            ? (int) $body['amount_cents']
            : Money::toCents($body['amount']);

        if ($amountCents <= 0) {
            throw new InvalidArgumentException("Settlement amount must be greater than zero.", 422);
        }

        // Payment method validation
        $rawMethod = strtoupper(trim((string) ($body['payment_method'] ?? 'OTHER')));
        $validMethods = ['UPI', 'CASH', 'BANK_TRANSFER', 'OTHER'];
        $paymentMethod = in_array($rawMethod, $validMethods, true) ? $rawMethod : 'OTHER';

        $referenceId = isset($body['reference_id']) && trim((string) $body['reference_id']) !== ''
            ? $this->sanitizeString((string) $body['reference_id'], 100)
            : null;

        $notes = isset($body['notes']) ? $this->sanitizeString((string) $body['notes'], 255) : null;
        $settledDate = isset($body['settled_date']) && !empty($body['settled_date'])
            ? (string) $body['settled_date']
            : null;

        // Resolve acting member for creation (restricted strictly to creation actor keys)
        $actingMemberId = $this->resolveActingMemberId(
            $request,
            (int) $group['id'],
            $groupMembers,
            $body,
            ['actor_member_id', 'recorded_by_member_id'],
            $payerId
        );

        // Determine initial status:
        // Creditor Fast-Path: If the resolved acting member is the payee (creditor recording receipt),
        // initialize as CONFIRMED. Otherwise (debtor recording payment submission), initialize as PENDING.
        if ($actingMemberId === $payeeId) {
            $status = 'CONFIRMED';
            $confirmedBy = $payeeId;
            $confirmedAt = date('Y-m-d H:i:s');
        } else {
            $status = 'PENDING';
            $confirmedBy = null;
            $confirmedAt = null;
        }

        $isDuplicate = false;
        $settlementId = $this->settlementRepo->create(
            (int) $group['id'],
            $payerId,
            $payeeId,
            $amountCents,
            $notes,
            $settledDate,
            $actingMemberId,
            $paymentMethod,
            $referenceId,
            $status,
            $confirmedBy,
            $confirmedAt,
            $idempotencyKey,
            $isDuplicate
        );

        if (!$isDuplicate) {
            \App\Services\EventService::broadcast((int) $group['id'], 'settlement.created', $settlementId);
        }

        $settlement = $this->settlementRepo->findById($settlementId);
        $updatedBalances = $this->balanceService->calculateGroupBalances((int) $group['id']);

        $this->json([
            'settlement' => $settlement,
            'updated_balances' => $updatedBalances['members'],
        ], 201);
    }

    /**
     * POST /api/groups/{token}/settlements/{id}/confirm
     * Creditor explicitly confirms receipt of a pending settlement.
     */
    public function confirm(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $settlementId = (int) $request->getParam('id');
        $settlement = $this->settlementRepo->findById($settlementId);

        if (!$settlement || $settlement['group_id'] !== (int) $group['id'] || $settlement['is_deleted']) {
            $this->error("Settlement not found.", 'NOT_FOUND', null, 404);
            return;
        }

        if ($settlement['status'] !== 'PENDING') {
            throw new InvalidArgumentException("Only PENDING settlements can be confirmed.", 422);
        }

        $groupMembers = $this->memberRepo->findByGroupId((int) $group['id']);
        $body = $request->getBody();
        $actingMemberId = $this->resolveActingMemberId(
            $request,
            (int) $group['id'],
            $groupMembers,
            $body,
            ['actor_member_id', 'confirmed_by_member_id'],
            (int) $settlement['payee']['id']
        );

        // Authorization rule: Only the Creditor (payee) or Group Owner can confirm receipt
        $isCreditor = ($actingMemberId === (int) $settlement['payee']['id']);
        $currentUser = $request->getUser();
        $isOwner = $currentUser && $group['owner_user_id'] && ((int) $group['owner_user_id'] === (int) $currentUser['id']);

        if (!$isCreditor && !$isOwner) {
            $this->error("Only the creditor ({$settlement['payee']['name']}) can confirm payment receipt.", 'FORBIDDEN', null, 403);
            return;
        }

        $this->settlementRepo->confirm($settlementId, $actingMemberId);
        \App\Services\EventService::broadcast((int) $group['id'], 'settlement.confirmed', $settlementId);

        $updatedSettlement = $this->settlementRepo->findById($settlementId);
        $updatedBalances = $this->balanceService->calculateGroupBalances((int) $group['id']);

        $this->json([
            'settlement' => $updatedSettlement,
            'updated_balances' => $updatedBalances['members'],
        ], 200);
    }

    /**
     * POST /api/groups/{token}/settlements/{id}/dispute
     * Creditor disputes a pending settlement payment.
     */
    public function dispute(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $settlementId = (int) $request->getParam('id');
        $settlement = $this->settlementRepo->findById($settlementId);

        if (!$settlement || $settlement['group_id'] !== (int) $group['id'] || $settlement['is_deleted']) {
            $this->error("Settlement not found.", 'NOT_FOUND', null, 404);
            return;
        }

        if ($settlement['status'] !== 'PENDING') {
            throw new InvalidArgumentException("Only PENDING settlements can be disputed.", 422);
        }

        $groupMembers = $this->memberRepo->findByGroupId((int) $group['id']);
        $body = $request->getBody();
        $actingMemberId = $this->resolveActingMemberId(
            $request,
            (int) $group['id'],
            $groupMembers,
            $body,
            ['actor_member_id', 'disputed_by_member_id'],
            (int) $settlement['payee']['id']
        );

        // Authorization rule: Only Creditor or Group Owner can dispute
        $isCreditor = ($actingMemberId === (int) $settlement['payee']['id']);
        $currentUser = $request->getUser();
        $isOwner = $currentUser && $group['owner_user_id'] && ((int) $group['owner_user_id'] === (int) $currentUser['id']);

        if (!$isCreditor && !$isOwner) {
            $this->error("Only the creditor ({$settlement['payee']['name']}) can dispute this payment.", 'FORBIDDEN', null, 403);
            return;
        }

        $reason = isset($body['reason']) ? $this->sanitizeString((string) $body['reason'], 255) : null;

        $this->settlementRepo->dispute($settlementId, $actingMemberId, $reason);
        \App\Services\EventService::broadcast((int) $group['id'], 'settlement.disputed', $settlementId);

        $updatedSettlement = $this->settlementRepo->findById($settlementId);
        $updatedBalances = $this->balanceService->calculateGroupBalances((int) $group['id']);

        $this->json([
            'settlement' => $updatedSettlement,
            'updated_balances' => $updatedBalances['members'],
        ], 200);
    }

    /**
     * POST /api/groups/{token}/settlements/{id}/reverse
     * Reverses a previously confirmed settlement.
     */
    public function reverse(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $settlementId = (int) $request->getParam('id');
        $settlement = $this->settlementRepo->findById($settlementId);

        if (!$settlement || $settlement['group_id'] !== (int) $group['id'] || $settlement['is_deleted']) {
            $this->error("Settlement not found.", 'NOT_FOUND', null, 404);
            return;
        }

        if ($settlement['status'] === 'REVERSED') {
            throw new InvalidArgumentException("Settlement is already reversed.", 422);
        }

        $groupMembers = $this->memberRepo->findByGroupId((int) $group['id']);
        $body = $request->getBody();
        $actingMemberId = $this->resolveActingMemberId(
            $request,
            (int) $group['id'],
            $groupMembers,
            $body,
            ['actor_member_id', 'reversed_by_member_id'],
            (int) $settlement['payee']['id']
        );

        // Authorization rule: Payer, Payee, or Group Owner can reverse
        $isParticipant = ($actingMemberId === (int) $settlement['payer']['id'] || $actingMemberId === (int) $settlement['payee']['id']);
        $currentUser = $request->getUser();
        $isOwner = $currentUser && $group['owner_user_id'] && ((int) $group['owner_user_id'] === (int) $currentUser['id']);

        if (!$isParticipant && !$isOwner) {
            $this->error("Only the settlement participants or workspace owner can reverse a settlement.", 'FORBIDDEN', null, 403);
            return;
        }

        $reason = isset($body['reason']) ? $this->sanitizeString((string) $body['reason'], 255) : null;

        $this->settlementRepo->reverse($settlementId, $actingMemberId, $reason);
        \App\Services\EventService::broadcast((int) $group['id'], 'settlement.reversed', $settlementId);

        $updatedSettlement = $this->settlementRepo->findById($settlementId);
        $updatedBalances = $this->balanceService->calculateGroupBalances((int) $group['id']);

        $this->json([
            'settlement' => $updatedSettlement,
            'updated_balances' => $updatedBalances['members'],
        ], 200);
    }

    /**
     * GET /api/groups/{token}/settlements
     * List all active settlements for the group.
     */
    public function index(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $settlements = $this->settlementRepo->findByGroupId((int) $group['id']);

        $this->json([
            'settlements' => $settlements,
            'total_count' => count($settlements),
        ]);
    }

    /**
     * DELETE /api/groups/{token}/settlements/{id}
     * Soft-delete / reverse a settlement (Backwards compatibility).
     */
    public function delete(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $settlementId = (int) $request->getParam('id');
        $settlement = $this->settlementRepo->findById($settlementId);

        if (!$settlement || $settlement['group_id'] !== (int) $group['id'] || $settlement['is_deleted']) {
            $this->error("Settlement not found or already deleted.", 'NOT_FOUND', null, 404);
            return;
        }

        $groupMembers = $this->memberRepo->findByGroupId((int) $group['id']);
        $body = $request->getBody();
        $actingMemberId = $this->resolveActingMemberId(
            $request,
            (int) $group['id'],
            $groupMembers,
            $body,
            ['actor_member_id', 'deleted_by_member_id'],
            (int) $settlement['payer']['id']
        );

        $isParticipant = ($actingMemberId === (int) $settlement['payer']['id'] || $actingMemberId === (int) $settlement['payee']['id']);
        $currentUser = $request->getUser();
        $isOwner = $currentUser && $group['owner_user_id'] && ((int) $group['owner_user_id'] === (int) $currentUser['id']);

        if (!$isParticipant && !$isOwner) {
            $this->error("Only the settlement participants or workspace owner can delete a settlement.", 'FORBIDDEN', null, 403);
            return;
        }

        $deleted = $this->settlementRepo->softDelete($settlementId, $actingMemberId);
        \App\Services\EventService::broadcast((int) $group['id'], 'settlement.deleted', $settlementId);
        $updatedBalances = $this->balanceService->calculateGroupBalances((int) $group['id']);

        $this->json([
            'deleted' => $deleted,
            'settlement_id' => $settlementId,
            'updated_balances' => $updatedBalances['members'],
        ]);
    }

    /**
     * Helper to resolve the authenticated / acting member ID.
     *
     * Invariants:
     * 1. If an authenticated user exists and belongs to the group, return their member ID (ignoring client payload claims).
     * 2. If an actor claim is supplied in the request body under an allowed candidate key for this operation,
     *    validate that it belongs to this group's active member roster.
     *    If valid, return it.
     *    If invalid / not in group roster, FAIL CLOSED with 422 InvalidArgumentException (NO SILENT FALLBACK).
     * 3. Otherwise, if no client claim is provided under allowed keys, return $defaultMemberId (provided it is valid in this group).
     *
     * @param Request $request
     * @param int $groupId
     * @param array<int, array<string, mixed>> $groupMembers
     * @param array<string, mixed> $body
     * @param array<string> $allowedCandidateKeys
     * @param int $defaultMemberId
     * @return int
     * @throws InvalidArgumentException If a declared acting member does not belong to the group.
     */
    private function resolveActingMemberId(
        Request $request,
        int $groupId,
        array $groupMembers,
        array $body,
        array $allowedCandidateKeys,
        int $defaultMemberId
    ): int {
        $currentUser = $request->getUser();
        if ($currentUser) {
            $userId = (int) $currentUser['id'];
            foreach ($groupMembers as $m) {
                if (isset($m['user_id']) && (int) $m['user_id'] === $userId) {
                    return (int) $m['id'];
                }
            }
        }

        $validMemberIds = array_map(fn($m) => (int) $m['id'], $groupMembers);

        foreach ($allowedCandidateKeys as $key) {
            if (isset($body[$key]) && $body[$key] !== '' && $body[$key] !== null) {
                $claimedId = (int) $body[$key];
                if (in_array($claimedId, $validMemberIds, true)) {
                    return $claimedId;
                }
                throw new InvalidArgumentException("Declared acting member does not belong to this group.", 422);
            }
        }

        return $defaultMemberId;
    }
}
