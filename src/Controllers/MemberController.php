<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Request;
use App\Repositories\ActivityLogRepository;
use App\Repositories\GroupRepository;
use App\Repositories\MemberRepository;
use App\Services\BalanceService;
use InvalidArgumentException;

/**
 * Controller handling Member roster operations.
 */
class MemberController extends BaseController
{
    private GroupRepository $groupRepo;
    private MemberRepository $memberRepo;
    private ActivityLogRepository $activityLogRepo;
    private BalanceService $balanceService;

    public function __construct(
        ?GroupRepository $groupRepo = null,
        ?MemberRepository $memberRepo = null,
        ?ActivityLogRepository $activityLogRepo = null,
        ?BalanceService $balanceService = null
    ) {
        $this->groupRepo = $groupRepo ?? new GroupRepository();
        $this->memberRepo = $memberRepo ?? new MemberRepository();
        $this->activityLogRepo = $activityLogRepo ?? new ActivityLogRepository();
        $this->balanceService = $balanceService ?? new BalanceService();
    }

    /**
     * POST /api/groups/{token}/members
     * Add a new member to an existing group.
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
        $this->validateRequired($body, ['name']);

        $name = $this->sanitizeString($body['name'] ?? '', 60);
        if (mb_strlen($name) < 1) {
            throw new InvalidArgumentException("Member name cannot be empty.", 422);
        }

        // Check for duplicate member name in the same group
        $existing = $this->memberRepo->findByName((int) $group['id'], $name);
        if ($existing) {
            $this->error(
                "A member with the name '{$name}' already exists in this group.",
                'MEMBER_EXISTS',
                ['name' => $name],
                422
            );
            return;
        }

        $member = $this->memberRepo->create((int) $group['id'], $name);
        \App\Services\EventService::broadcast((int) $group['id'], 'member.created', (int) $member['id']);

        // Record Activity Log
        $this->activityLogRepo->record(
            (int) $group['id'],
            null,
            'MEMBER_ADDED',
            'members',
            (int) $member['id'],
            [
                'name' => $name,
                'member_id' => (int) $member['id'],
            ]
        );

        $this->json([
            'member' => [
                'id' => (int) $member['id'],
                'group_id' => (int) $group['id'],
                'user_id' => isset($member['user_id']) && $member['user_id'] !== null ? (int) $member['user_id'] : null,
                'name' => $member['name'],
                'member_token' => $member['member_token'],
                'is_active' => true,
                'created_at' => $member['created_at'],
            ],
        ], 201);
    }

    /**
     * GET /api/groups/{token}/members
     * List all active members in a group.
     */
    public function index(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $members = $this->memberRepo->findByGroupId((int) $group['id']);

        $this->json([
            'members' => array_map(function (array $m) {
                return [
                    'id' => (int) $m['id'],
                    'group_id' => (int) $m['group_id'],
                    'user_id' => isset($m['user_id']) && $m['user_id'] !== null ? (int) $m['user_id'] : null,
                    'name' => $m['name'],
                    'upi_id' => $m['upi_id'] ?? null,
                    'user_upi_id' => $m['user_upi_id'] ?? null,
                    'member_upi_id' => $m['member_upi_id'] ?? null,
                    'color_hex' => $m['color_hex'] ?? null,
                    'is_active' => (bool) $m['is_active'],
                    'created_at' => $m['created_at'],
                ];
            }, $members),
            'total_count' => count($members),
        ]);
    }

    /**
     * PUT /api/groups/{token}/members/{id}
     * Update an existing member (name and/or UPI ID).
     */
    public function update(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $memberId = (int) $request->getParam('id');
        $member = $this->memberRepo->findById($memberId);
        if (!$member || (int) $member['group_id'] !== (int) $group['id']) {
            $this->error("Member not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $body = $request->getBody();

        // 1. Process Name Update
        $newName = isset($body['name']) ? $this->sanitizeString((string) $body['name'], 60) : (string) $member['name'];
        if (mb_strlen($newName) < 1) {
            $this->error("Member name cannot be empty.", 'INVALID_NAME', null, 422);
            return;
        }

        // If name changed, check duplicate
        if (mb_strtolower(trim($newName)) !== mb_strtolower(trim((string) $member['name']))) {
            $existing = $this->memberRepo->findByName((int) $group['id'], $newName);
            if ($existing && (int) $existing['id'] !== $memberId) {
                $this->error(
                    "A member with the name '{$newName}' already exists in this group.",
                    'MEMBER_EXISTS',
                    ['name' => $newName],
                    422
                );
                return;
            }
        }

        // 2. Process UPI ID Update
        $upiId = $member['upi_id'] ?? null;
        if (array_key_exists('upi_id', $body)) {
            $rawUpi = $body['upi_id'];
            if ($rawUpi === null || trim((string) $rawUpi) === '') {
                $upiId = null;
            } else {
                $trimmedUpi = trim((string) $rawUpi);
                if (mb_strlen($trimmedUpi) < 5 || mb_strlen($trimmedUpi) > 80 || !preg_match('/^[a-zA-Z0-9._-]{2,77}@[a-zA-Z]{2,64}$/', $trimmedUpi)) {
                    $this->error(
                        "Invalid UPI ID format. Please enter a valid VPA (maximum 80 characters, e.g. name@okaxis).",
                        'INVALID_UPI_ID',
                        ['upi_id' => $rawUpi],
                        422
                    );
                    return;
                }
                $upiId = $trimmedUpi;
            }
        }

        $this->memberRepo->updateMemberDetails($memberId, (int) $group['id'], $newName, $upiId);
        \App\Services\EventService::broadcast((int) $group['id'], 'member.updated', $memberId);

        // Record Activity Log
        $this->activityLogRepo->record(
            (int) $group['id'],
            null,
            'MEMBER_UPDATED',
            'members',
            $memberId,
            [
                'old_name' => $member['name'],
                'new_name' => $newName,
                'upi_id' => $upiId,
                'member_id' => $memberId,
            ]
        );

        $this->json([
            'message' => 'Member updated successfully.',
            'member' => [
                'id' => $memberId,
                'group_id' => (int) $group['id'],
                'user_id' => isset($member['user_id']) && $member['user_id'] !== null ? (int) $member['user_id'] : null,
                'name' => $newName,
                'upi_id' => $upiId,
                'is_active' => (bool) $member['is_active'],
            ],
        ], 200);
    }

    /**
     * DELETE /api/groups/{token}/members/{id}
     * Remove a member if they have zero net balance, with hard delete for unused and soft deactivate for historical.
     */
    public function delete(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $memberId = (int) $request->getParam('id');
        $member = $this->memberRepo->findById($memberId);
        if (!$member || (int) $member['group_id'] !== (int) $group['id']) {
            $this->error("Member not found.", 'NOT_FOUND', null, 404);
            return;
        }

        // Rule 1: Check active member count
        $activeMembers = $this->memberRepo->findByGroupId((int) $group['id'], true);
        if (count($activeMembers) <= 1) {
            $this->error("Cannot remove the only member of a workspace. You can delete the workspace instead.", 'MIN_MEMBER_LIMIT', null, 422);
            return;
        }

        // Rule 2: Check net balance
        $balanceReport = $this->balanceService->calculateGroupBalances((int) $group['id']);
        $memberBalance = 0;
        foreach ($balanceReport['members'] as $bm) {
            if ((int) $bm['member_id'] === $memberId) {
                $memberBalance = (int) $bm['net_balance_cents'];
                break;
            }
        }

        if ($memberBalance !== 0) {
            $formattedBalance = number_format(abs($memberBalance) / 100, 2);
            $currencyCode = $group['currency_code'] ?? 'INR';
            $direction = $memberBalance > 0 ? "is owed {$currencyCode} {$formattedBalance}" : "owes {$currencyCode} {$formattedBalance}";
            $this->error(
                "Cannot remove member '{$member['name']}' who {$direction}. Settle all balances before removing this member.",
                'MEMBER_HAS_BALANCE',
                [
                    'member_id' => $memberId,
                    'net_balance_cents' => $memberBalance,
                ],
                422
            );
            return;
        }

        // Rule 3: Check transactions (hard delete if none, soft deactivate if historical)
        $hasTx = $this->memberRepo->hasTransactions($memberId);
        if ($hasTx) {
            $this->memberRepo->deactivate($memberId, (int) $group['id']);
        } else {
            $this->memberRepo->delete($memberId, (int) $group['id']);
        }
        \App\Services\EventService::broadcast((int) $group['id'], 'member.deleted', $memberId);

        // Record Activity Log
        $this->activityLogRepo->record(
            (int) $group['id'],
            null,
            'MEMBER_REMOVED',
            'members',
            $memberId,
            [
                'name' => $member['name'],
                'member_id' => $memberId,
                'was_deactivated' => $hasTx,
            ]
        );

        $this->json([
            'message' => "Member '{$member['name']}' removed successfully.",
            'deleted' => true,
            'member_id' => $memberId,
            'was_deactivated' => $hasTx,
        ], 200);
    }
}
