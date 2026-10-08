<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\GroupRepository;
use App\Repositories\MemberRepository;
use App\Services\RateLimiterService;
use InvalidArgumentException;
use Throwable;

/**
 * Controller handling Group lifecycle operations.
 */
class GroupController extends BaseController
{
    private GroupRepository $groupRepo;
    private MemberRepository $memberRepo;

    public function __construct(?GroupRepository $groupRepo = null, ?MemberRepository $memberRepo = null)
    {
        $this->groupRepo = $groupRepo ?? new GroupRepository();
        $this->memberRepo = $memberRepo ?? new MemberRepository();
    }

    /**
     * POST /api/groups
     * Create a new group and add the initial creator member atomically.
     * Automatically links owner_user_id and creator member user_id if logged in.
     */
    public function create(Request $request): void
    {
        // SEC-07: Rate Limiting on Workspace Creation (15 attempts / 60s)
        $rateLimiter = new RateLimiterService();
        $clientIp = $request->getClientIp();
        $currentUser = $request->getUser();
        $ownerUserId = $currentUser['id'] ?? null;
        $rateIdentity = $ownerUserId !== null ? "user_{$ownerUserId}_{$clientIp}" : $clientIp;

        $maxAttempts = (int) \App\Core\Env::get('RATE_LIMIT_GROUP_MAX', 15);
        $window = (int) \App\Core\Env::get('RATE_LIMIT_WINDOW_SECONDS', 60);
        $rateCheck = $rateLimiter->check('group_create', $rateIdentity, $maxAttempts, $window);

        if (!$rateCheck['allowed']) {
            if (!headers_sent()) {
                header("Retry-After: {$rateCheck['retry_after']}");
            }
            $this->error(
                "Too many workspace creation attempts. Please try again in {$rateCheck['retry_after']} seconds.",
                'RATE_LIMITED',
                ['retry_after' => $rateCheck['retry_after']],
                429
            );
            return;
        }

        $body = $request->getBody();
        $this->validateRequired($body, ['name']);

        $name = $this->sanitizeString($body['name'] ?? '', 100);
        $creatorName = $this->sanitizeString($body['creator_name'] ?? 'Organizer', 60);
        $currencyCode = $this->sanitizeString($body['currency'] ?? 'INR', 3);

        $currentUser = $request->getUser();
        $ownerUserId = $currentUser['id'] ?? null;

        if (mb_strlen($name) < 2) {
            throw new InvalidArgumentException("Group name must be at least 2 characters long.", 422);
        }

        if (mb_strlen($creatorName) < 1) {
            throw new InvalidArgumentException("Creator name cannot be empty.", 422);
        }

        Database::beginTransaction();
        try {
            // 1. Create Group (with optional ownerUserId)
            $group = $this->groupRepo->create($name, $currencyCode, $ownerUserId);

            // 2. Create Initial Member (Creator linked to ownerUserId if authenticated)
            $creator = $this->memberRepo->create((int) $group['id'], $creatorName, $ownerUserId);

            Database::commit();

            $this->json([
                'group' => [
                    'id' => $group['id'],
                    'uuid' => $group['uuid'],
                    'name' => $group['name'],
                    'currency_code' => $group['currency_code'],
                    'invite_token' => $group['invite_token'],
                    'owner_user_id' => $group['owner_user_id'],
                    'creator_member_id' => (int) $creator['id'],
                    'version' => $group['version'],
                    'created_at' => $group['created_at'],
                ],
                'creator' => [
                    'id' => $creator['id'],
                    'name' => $creator['name'],
                    'user_id' => $creator['user_id'],
                    'member_token' => $creator['member_token'],
                ],
                'members' => [
                    $creator
                ],
            ], 201, [
                'share_url' => "/#/g/{$group['invite_token']}",
            ]);
        } catch (Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * GET /api/groups/{token}/workspace
     * Retrieve consolidated workspace dataset (group, members, balances, settlement plan, expenses, settlements)
     * in a single atomic payload to eliminate network waterfalls.
     */
    public function workspace(Request $request): void
    {
        $token = $request->getParam('token');
        if (empty($token)) {
            $this->error("Group invite token is required.", 'BAD_REQUEST', null, 400);
            return;
        }

        $workspaceData = $this->groupRepo->getWorkspaceData((string) $token);
        if (!$workspaceData) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $this->json($workspaceData);
    }

    /**
     * GET /api/groups/{token}
     * Retrieve group metadata and the current member roster.
     */
    public function show(Request $request): void
    {
        $token = $request->getParam('token');
        if (empty($token)) {
            $this->error("Group invite token is required.", 'BAD_REQUEST', null, 400);
            return;
        }

        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $members = $this->memberRepo->findByGroupId((int) $group['id']);
        $creatorMember = $this->memberRepo->getCreatorMember((int) $group['id']);
        $creatorMemberId = $creatorMember ? (int) $creatorMember['id'] : (!empty($members) ? (int) $members[0]['id'] : null);

        $this->json([
            'group' => [
                'id' => (int) $group['id'],
                'uuid' => $group['uuid'],
                'name' => $group['name'],
                'currency_code' => $group['currency_code'],
                'invite_token' => $group['invite_token'],
                'owner_user_id' => $group['owner_user_id'] ? (int) $group['owner_user_id'] : null,
                'creator_member_id' => $creatorMemberId,
                'version' => (int) $group['version'],
                'created_at' => $group['created_at'],
            ],
            'members' => array_map(function (array $m) {
                return [
                    'id' => (int) $m['id'],
                    'name' => $m['name'],
                    'upi_id' => $m['upi_id'] ?? null,
                    'color_hex' => $m['color_hex'] ?? null,
                    'user_id' => $m['user_id'] ? (int) $m['user_id'] : null,
                    'is_active' => (bool) $m['is_active'],
                    'created_at' => $m['created_at'],
                ];
            }, $members),
        ]);
    }

    /**
     * DELETE /api/groups/{token}
     * Permanently delete a workspace, all associated records, and unlink from cloud portfolios.
     */
    public function delete(Request $request): void
    {
        $token = $request->getParam('token');
        if (empty($token)) {
            $this->error("Group invite token is required.", 'BAD_REQUEST', null, 400);
            return;
        }

        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        // Authorization Guard:
        // 1. If group has an owner_user_id, only authenticated owner can delete
        $currentUser = $request->getUser();
        if ($group['owner_user_id'] !== null) {
            if (!$currentUser || (int) $currentUser['id'] !== (int) $group['owner_user_id']) {
                $this->error("Only the workspace owner can delete this workspace.", 'FORBIDDEN', null, 403);
                return;
            }
        } else {
            // 2. Guest workspace: Mandatory creator token verification (fail closed)
            $creatorTokenHeader = $request->getHeader('X-Creator-Token');
            $creatorMember = $this->memberRepo->getCreatorMember((int) $group['id']);
            $expectedToken = $creatorMember['member_token'] ?? null;

            if (
                empty($creatorTokenHeader) ||
                empty($expectedToken) ||
                !hash_equals((string) $expectedToken, (string) $creatorTokenHeader)
            ) {
                $this->error("Only the original workspace creator can delete this workspace.", 'FORBIDDEN', null, 403);
                return;
            }
        }

        $groupId = (int) $group['id'];
        $this->groupRepo->delete($groupId);

        $this->json([
            'message' => 'Workspace deleted successfully.',
            'deleted' => true,
            'token' => $token,
        ], 200);
    }

    /**
     * POST /api/groups/{token}/creator-pairing
     * Generate a short-lived, single-use pairing code for transferring creator authority to another device.
     */
    public function createPairingCode(Request $request): void
    {
        $token = $request->getParam('token');
        if (empty($token)) {
            $this->error("Group invite token is required.", 'BAD_REQUEST', null, 400);
            return;
        }

        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        // Authorization Guard:
        $currentUser = $request->getUser();
        if ($group['owner_user_id'] !== null) {
            if (!$currentUser || (int) $currentUser['id'] !== (int) $group['owner_user_id']) {
                $this->error("Only the workspace owner can generate a device pairing code.", 'FORBIDDEN', null, 403);
                return;
            }
        } else {
            $creatorTokenHeader = $request->getHeader('X-Creator-Token');
            $creatorMember = $this->memberRepo->getCreatorMember((int) $group['id']);
            $expectedToken = $creatorMember['member_token'] ?? null;

            if (
                empty($creatorTokenHeader) ||
                empty($expectedToken) ||
                !hash_equals((string) $expectedToken, (string) $creatorTokenHeader)
            ) {
                $this->error("Only the original workspace creator can generate a device pairing code.", 'FORBIDDEN', null, 403);
                return;
            }
        }

        // Rate limiting (max 5 pairing codes / 60 seconds per IP)
        $rateLimiter = new RateLimiterService();
        $clientIp = $request->getClientIp();
        $rateCheck = $rateLimiter->check('creator_pairing_create', $clientIp . '_' . $group['id'], 5, 60);
        if (!$rateCheck['allowed']) {
            if (!headers_sent()) {
                header("Retry-After: {$rateCheck['retry_after']}");
            }
            $this->error(
                "Too many pairing code generation attempts. Please try again in {$rateCheck['retry_after']} seconds.",
                'RATE_LIMITED',
                ['retry_after' => $rateCheck['retry_after']],
                429
            );
            return;
        }

        // Generate 8-character human-readable pairing code: e.g. PAIR-7A3B-9F2D
        $rawHex = strtoupper(bin2hex(random_bytes(4)));
        $pairingCode = 'PAIR-' . substr($rawHex, 0, 4) . '-' . substr($rawHex, 4, 4);
        $codeHash = hash('sha256', $pairingCode);
        $expiresInSeconds = 600; // 10 minutes
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresInSeconds);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO `creator_pairing_codes` (`group_id`, `code_hash`, `expires_at`, `is_used`)
            VALUES (:group_id, :code_hash, :expires_at, 0)
        ");
        $stmt->execute([
            ':group_id' => $group['id'],
            ':code_hash' => $codeHash,
            ':expires_at' => $expiresAt,
        ]);

        $this->json([
            'pairing_code' => $pairingCode,
            'code' => $pairingCode,
            'expires_at' => $expiresAt,
            'expires_in_seconds' => $expiresInSeconds,
        ], 201);
    }

    /**
     * POST /api/groups/{token}/creator-pairing/claim
     * Claim a pairing code on a new device to securely establish creator authority.
     */
    public function claimPairingCode(Request $request): void
    {
        $token = $request->getParam('token');
        if (empty($token)) {
            $this->error("Group invite token is required.", 'BAD_REQUEST', null, 400);
            return;
        }

        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        // Rate limiting (max 5 claim attempts / 60 seconds per IP to prevent brute force)
        $rateLimiter = new RateLimiterService();
        $clientIp = $request->getClientIp();
        $rateCheck = $rateLimiter->check('creator_pairing_claim', $clientIp, 5, 60);
        if (!$rateCheck['allowed']) {
            if (!headers_sent()) {
                header("Retry-After: {$rateCheck['retry_after']}");
            }
            $this->error(
                "Too many pairing claim attempts. Please try again in {$rateCheck['retry_after']} seconds.",
                'RATE_LIMITED',
                ['retry_after' => $rateCheck['retry_after']],
                429
            );
            return;
        }

        $body = $request->getBody();
        $this->validateRequired($body, ['pairing_code']);

        $rawCode = strtoupper(trim((string) $body['pairing_code']));
        // Format if user typed without PAIR- prefix
        if (!str_starts_with($rawCode, 'PAIR-')) {
            $cleaned = str_replace('-', '', $rawCode);
            if (strlen($cleaned) === 8) {
                $rawCode = 'PAIR-' . substr($cleaned, 0, 4) . '-' . substr($cleaned, 4, 4);
            }
        }
        $codeHash = hash('sha256', $rawCode);

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("
                SELECT `id`, `group_id`, `expires_at`, `is_used`
                FROM `creator_pairing_codes`
                WHERE `code_hash` = :code_hash AND `group_id` = :group_id
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([
                ':code_hash' => $codeHash,
                ':group_id' => $group['id'],
            ]);
            $record = $stmt->fetch();

            if (!$record || (int) $record['is_used'] === 1) {
                $pdo->rollBack();
                $this->error("Invalid or already used pairing code.", 'INVALID_PAIRING_CODE', null, 400);
                return;
            }

            if (strtotime((string) $record['expires_at']) <= time()) {
                $pdo->rollBack();
                $this->error("Pairing code has expired. Please generate a new code on your creator device.", 'PAIRING_CODE_EXPIRED', null, 410);
                return;
            }

            // Mark code as used immediately (single-use replay protection)
            $updateStmt = $pdo->prepare("UPDATE `creator_pairing_codes` SET `is_used` = 1 WHERE `id` = :id");
            $updateStmt->execute([':id' => $record['id']]);

            // Retrieve creator member record
            $creatorMember = $this->memberRepo->getCreatorMember((int) $group['id']);
            if (!$creatorMember || empty($creatorMember['member_token'])) {
                $pdo->rollBack();
                $this->error("Workspace creator profile not found.", 'CREATOR_NOT_FOUND', null, 404);
                return;
            }

            $pdo->commit();

            $this->json([
                'message' => 'Creator authority transferred successfully.',
                'creator_token' => (string) $creatorMember['member_token'],
                'creator_member_id' => (int) $creatorMember['id'],
                'creator_name' => (string) $creatorMember['name'],
                'group' => [
                    'id' => (int) $group['id'],
                    'name' => (string) $group['name'],
                    'currency_code' => (string) $group['currency_code'],
                    'invite_token' => (string) $group['invite_token'],
                ],
            ], 200);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
