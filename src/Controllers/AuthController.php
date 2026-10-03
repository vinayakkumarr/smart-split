<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\ActivityLogRepository;
use App\Repositories\GroupRepository;
use App\Repositories\MemberRepository;
use App\Services\BalanceService;
use App\Services\RateLimiterService;
use InvalidArgumentException;
use PDO;

/**
 * Controller handling user registration, authentication, sessions, progressive member claiming, and workspace portfolios.
 */
class AuthController extends BaseController
{
    private PDO $pdo;
    private GroupRepository $groupRepo;
    private MemberRepository $memberRepo;
    private ActivityLogRepository $activityLogRepo;
    private BalanceService $balanceService;

    public function __construct(
        ?PDO $pdo = null,
        ?GroupRepository $groupRepo = null,
        ?MemberRepository $memberRepo = null,
        ?ActivityLogRepository $activityLogRepo = null,
        ?BalanceService $balanceService = null
    ) {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->groupRepo = $groupRepo ?? new GroupRepository($this->pdo);
        $this->memberRepo = $memberRepo ?? new MemberRepository($this->pdo);
        $this->activityLogRepo = $activityLogRepo ?? new ActivityLogRepository($this->pdo);
        $this->balanceService = $balanceService ?? new BalanceService($this->pdo);
    }

    /**
     * POST /api/auth/register
     * Register a new user account, generate recovery key, and issue session cookie.
     */
    public function register(Request $request): void
    {
        // SEC-07: IP Rate Limiting on User Registration (10 attempts / 60s)
        $rateLimiter = new RateLimiterService($this->pdo);
        $clientIp = $request->getClientIp();
        $maxAttempts = (int) \App\Core\Env::get('RATE_LIMIT_REGISTER_MAX', 10);
        $window = (int) \App\Core\Env::get('RATE_LIMIT_WINDOW_SECONDS', 60);
        $rateCheck = $rateLimiter->check('auth_register', $clientIp, $maxAttempts, $window);

        if (!$rateCheck['allowed']) {
            if (!headers_sent()) {
                header("Retry-After: {$rateCheck['retry_after']}");
            }
            $this->error(
                "Too many registration attempts. Please try again in {$rateCheck['retry_after']} seconds.",
                'RATE_LIMITED',
                ['retry_after' => $rateCheck['retry_after']],
                429
            );
            return;
        }

        $body = $request->getBody();
        $this->validateRequired($body, ['email', 'password']);

        $email = strtolower(trim((string) $body['email']));
        $password = (string) $body['password'];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Please enter a valid email address.", 422);
        }

        if (strlen($password) < 8) {
            throw new InvalidArgumentException("Password must be at least 8 characters long.", 422);
        }

        // Check if email already exists
        $checkStmt = $this->pdo->prepare("SELECT `id` FROM `users` WHERE `email` = :email LIMIT 1");
        $checkStmt->execute([':email' => $email]);
        if ($checkStmt->fetch()) {
            $this->error("An account with this email address already exists.", 'EMAIL_EXISTS', null, 409);
            return;
        }

        $displayName = isset($body['display_name']) && trim((string) $body['display_name']) !== ''
            ? $this->sanitizeString((string) $body['display_name'], 80)
            : explode('@', $email)[0];

        $avatarEmoji = isset($body['avatar_emoji']) && trim((string) $body['avatar_emoji']) !== ''
            ? $this->sanitizeString((string) $body['avatar_emoji'], 10)
            : '👤';

        $avatarColor = isset($body['avatar_color']) && trim((string) $body['avatar_color']) !== ''
            ? $this->sanitizeString((string) $body['avatar_color'], 7)
            : '#2563eb';

        // Generate emergency recovery code: SMART-XXXX-XXXX
        $rawRecoveryCode = 'SMART-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
        $recoveryCodeHash = password_hash($rawRecoveryCode, PASSWORD_BCRYPT, ['cost' => 10]);
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $insertStmt = $this->pdo->prepare("
            INSERT INTO `users` (`email`, `password_hash`, `display_name`, `recovery_code_hash`, `avatar_emoji`, `avatar_color`, `is_active`)
            VALUES (:email, :password_hash, :display_name, :recovery_code_hash, :avatar_emoji, :avatar_color, 1)
        ");
        $insertStmt->execute([
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':display_name' => $displayName,
            ':recovery_code_hash' => $recoveryCodeHash,
            ':avatar_emoji' => $avatarEmoji,
            ':avatar_color' => $avatarColor,
        ]);

        $userId = (int) $this->pdo->lastInsertId();
        $this->issueSessionCookie($userId);

        $this->json([
            'user' => [
                'id' => $userId,
                'email' => $email,
                'display_name' => $displayName,
                'avatar_emoji' => $avatarEmoji,
                'avatar_color' => $avatarColor,
                'upi_id' => null,
            ],
            'recovery_code' => $rawRecoveryCode,
            'message' => 'Account registered successfully. Please save your recovery code.',
        ], 201);
    }

    /**
     * POST /api/auth/login
     * Authenticate user with password and brute-force lockout protection.
     */
    public function login(Request $request): void
    {
        $body = $request->getBody();
        $this->validateRequired($body, ['email', 'password']);

        $email = strtolower(trim((string) $body['email']));
        $password = (string) $body['password'];

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                SELECT `id`, `email`, `password_hash`, `display_name`, `avatar_emoji`, `avatar_color`, `upi_id`,
                       `failed_login_attempts`, `locked_until`, `is_active`
                FROM `users`
                WHERE `email` = :email
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if (!$user) {
                $this->pdo->rollBack();
                $this->error("Invalid email or password.", 'INVALID_CREDENTIALS', null, 401);
                return;
            }

            if ($user['locked_until'] !== null) {
                $lockedUntil = strtotime((string) $user['locked_until']);
                if ($lockedUntil > time()) {
                    $remainingMinutes = (int) ceil(($lockedUntil - time()) / 60);
                    $this->pdo->rollBack();
                    $this->error(
                        "Account is temporarily locked due to failed attempts. Please try again in {$remainingMinutes} minute(s) or use your recovery code.",
                        'ACCOUNT_LOCKED',
                        ['remaining_minutes' => $remainingMinutes],
                        429
                    );
                    return;
                }
            }

            if (!$user['is_active']) {
                $this->pdo->rollBack();
                $this->error("This account has been deactivated.", 'ACCOUNT_INACTIVE', null, 403);
                return;
            }

            if (!password_verify($password, (string) $user['password_hash'])) {
                $currentFailed = (int) $user['failed_login_attempts'];
                if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) <= time()) {
                    $currentFailed = 0;
                }

                $failedAttempts = $currentFailed + 1;
                $lockUntil = null;
                if ($failedAttempts >= 5) {
                    $lockUntil = date('Y-m-d H:i:s', time() + (15 * 60));
                }

                $updateStmt = $this->pdo->prepare("
                    UPDATE `users` 
                    SET `failed_login_attempts` = :failed,
                        `locked_until` = :locked_until
                    WHERE `id` = :id
                ");
                $updateStmt->execute([
                    ':failed' => $failedAttempts,
                    ':locked_until' => $lockUntil,
                    ':id' => $user['id'],
                ]);
                $this->pdo->commit();

                if ($failedAttempts >= 5) {
                    $this->error("Invalid email or password. Maximum failed attempts reached. Account locked for 15 minutes.", 'ACCOUNT_LOCKED', [
                        'remaining_minutes' => 15,
                    ], 429);
                } else {
                    $remaining = 5 - $failedAttempts;
                    $this->error("Invalid email or password. {$remaining} attempt(s) remaining before temporary lockout.", 'INVALID_CREDENTIALS', null, 401);
                }
                return;
            }

            $resetStmt = $this->pdo->prepare("
                UPDATE `users` 
                SET `failed_login_attempts` = 0, `locked_until` = NULL 
                WHERE `id` = :id
            ");
            $resetStmt->execute([':id' => $user['id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->issueSessionCookie((int) $user['id']);

        $this->json([
            'user' => [
                'id' => (int) $user['id'],
                'email' => (string) $user['email'],
                'display_name' => (string) $user['display_name'],
                'avatar_emoji' => (string) $user['avatar_emoji'],
                'avatar_color' => (string) $user['avatar_color'],
                'upi_id' => isset($user['upi_id']) && $user['upi_id'] !== null ? (string) $user['upi_id'] : null,
            ],
            'message' => 'Logged in successfully.',
        ]);
    }

    /**
     * POST /api/auth/logout
     * Invalidate active session token and clear HTTP cookie.
     */
    public function logout(Request $request): void
    {
        $sessionCookie = $_COOKIE['smartsplit_session'] ?? null;
        if ($sessionCookie && is_string($sessionCookie) && trim($sessionCookie) !== '') {
            $tokenHash = hash('sha256', trim($sessionCookie));
            $delStmt = $this->pdo->prepare("DELETE FROM `user_sessions` WHERE `session_token_hash` = :token_hash");
            $delStmt->execute([':token_hash' => $tokenHash]);
        }

        $this->clearSessionCookie();

        $this->json(['message' => 'Logged out successfully.']);
    }

    /**
     * GET /api/auth/me
     * Return authenticated user identity or guest anonymous state.
     */
    public function me(Request $request): void
    {
        $user = $request->getUser();
        if (!$user) {
            $this->json([
                'user' => null,
                'authenticated' => false,
            ]);
            return;
        }

        $this->json([
            'user' => [
                'id' => (int) $user['id'],
                'email' => (string) $user['email'],
                'display_name' => (string) $user['display_name'],
                'avatar_emoji' => (string) $user['avatar_emoji'],
                'avatar_color' => (string) $user['avatar_color'],
                'upi_id' => isset($user['upi_id']) && $user['upi_id'] !== null ? (string) $user['upi_id'] : null,
            ],
            'authenticated' => true,
        ]);
    }

    /**
     * PUT /api/auth/profile
     * Update authenticated user profile details (display name, avatar emoji, avatar color, and personal UPI ID).
     */
    public function updateProfile(Request $request): void
    {
        $currentUser = $request->getUser();
        if (!$currentUser) {
            $this->error("Authentication required.", 'UNAUTHORIZED', null, 401);
            return;
        }

        $userId = (int) $currentUser['id'];
        $body = $request->getBody();

        // Fetch fresh user record
        $stmt = $this->pdo->prepare("
            SELECT `id`, `email`, `display_name`, `avatar_emoji`, `avatar_color`, `upi_id`, `is_active`
            FROM `users`
            WHERE `id` = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || (int) $user['is_active'] !== 1) {
            $this->error("User not found or account deactivated.", 'UNAUTHORIZED', null, 401);
            return;
        }

        $displayName = (string) $user['display_name'];
        if (array_key_exists('display_name', $body)) {
            $rawName = trim((string) $body['display_name']);
            if ($rawName === '') {
                $this->error("Display name cannot be empty.", 'INVALID_DISPLAY_NAME', null, 422);
                return;
            }
            $displayName = $this->sanitizeString($rawName, 80);
        }

        $avatarEmoji = (string) $user['avatar_emoji'];
        if (array_key_exists('avatar_emoji', $body)) {
            $rawEmoji = trim((string) $body['avatar_emoji']);
            $avatarEmoji = $rawEmoji !== '' ? $this->sanitizeString($rawEmoji, 10) : '👤';
        }

        $avatarColor = (string) $user['avatar_color'];
        if (array_key_exists('avatar_color', $body)) {
            $rawColor = trim((string) $body['avatar_color']);
            if ($rawColor !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $rawColor)) {
                $this->error("Invalid avatar color format. Expected hex code (e.g. #18352b).", 'INVALID_AVATAR_COLOR', null, 422);
                return;
            }
            $avatarColor = $rawColor !== '' ? strtolower($rawColor) : '#18352b';
        }

        $upiId = isset($user['upi_id']) && $user['upi_id'] !== null ? (string) $user['upi_id'] : null;
        if (array_key_exists('upi_id', $body)) {
            $rawUpi = $body['upi_id'];
            if ($rawUpi === null) {
                $upiId = null;
            } elseif (!is_string($rawUpi)) {
                $this->error(
                    "Invalid UPI ID format. Please enter a valid VPA (maximum 80 characters, e.g. name@okaxis).",
                    'INVALID_UPI_ID',
                    null,
                    422
                );
                return;
            } else {
                $trimmedUpi = trim($rawUpi);
                if ($trimmedUpi === '') {
                    $upiId = null;
                } elseif (mb_strlen($trimmedUpi) < 5 || mb_strlen($trimmedUpi) > 80 || !preg_match('/^[a-zA-Z0-9._-]{2,77}@[a-zA-Z]{2,64}$/', $trimmedUpi)) {
                    $this->error(
                        "Invalid UPI ID format. Please enter a valid VPA (maximum 80 characters, e.g. name@okaxis).",
                        'INVALID_UPI_ID',
                        ['upi_id' => $rawUpi],
                        422
                    );
                    return;
                } else {
                    $upiId = $trimmedUpi;
                }
            }
        }

        $updateStmt = $this->pdo->prepare("
            UPDATE `users`
            SET `display_name` = :display_name,
                `avatar_emoji` = :avatar_emoji,
                `avatar_color` = :avatar_color,
                `upi_id` = :upi_id
            WHERE `id` = :id
        ");
        $updateStmt->execute([
            ':display_name' => $displayName,
            ':avatar_emoji' => $avatarEmoji,
            ':avatar_color' => $avatarColor,
            ':upi_id' => $upiId,
            ':id' => $userId,
        ]);

        $this->json([
            'message' => 'Profile updated successfully.',
            'user' => [
                'id' => $userId,
                'email' => (string) $user['email'],
                'display_name' => $displayName,
                'avatar_emoji' => $avatarEmoji,
                'avatar_color' => $avatarColor,
                'upi_id' => $upiId,
            ],
        ]);
    }

    /**
     * POST /api/auth/recover-password
     * Reset password using emergency 10-character recovery code and rotate recovery code.
     */
    public function recoverPassword(Request $request): void
    {
        $body = $request->getBody();
        $this->validateRequired($body, ['email', 'recovery_code', 'new_password']);

        $email = strtolower(trim((string) $body['email']));
        $recoveryCode = strtoupper(trim((string) $body['recovery_code']));
        $newPassword = (string) $body['new_password'];

        if (strlen($newPassword) < 8) {
            throw new InvalidArgumentException("New password must be at least 8 characters long.", 422);
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                SELECT `id`, `email`, `display_name`, `avatar_emoji`, `avatar_color`, `upi_id`,
                       `recovery_code_hash`, `failed_login_attempts`, `locked_until`, `is_active`
                FROM `users`
                WHERE `email` = :email
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if (!$user) {
                $this->pdo->rollBack();
                $this->error("Invalid email or recovery code.", 'INVALID_RECOVERY_CODE', null, 401);
                return;
            }

            if ((int) $user['is_active'] !== 1) {
                $this->pdo->rollBack();
                $this->error("This account has been deactivated.", 'ACCOUNT_INACTIVE', null, 403);
                return;
            }

            if (!password_verify($recoveryCode, (string) $user['recovery_code_hash'])) {
                if ($user['locked_until'] !== null) {
                    $lockedUntil = strtotime((string) $user['locked_until']);
                    if ($lockedUntil > time()) {
                        $remainingMinutes = (int) ceil(($lockedUntil - time()) / 60);
                        $this->pdo->rollBack();
                        $this->error(
                            "Account is temporarily locked due to failed attempts. Please try again in {$remainingMinutes} minute(s).",
                            'ACCOUNT_LOCKED',
                            ['remaining_minutes' => $remainingMinutes],
                            429
                        );
                        return;
                    }
                }
                $currentFailed = (int) $user['failed_login_attempts'];
                if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) <= time()) {
                    $currentFailed = 0;
                }

                $failedAttempts = $currentFailed + 1;
                $lockUntil = null;
                if ($failedAttempts >= 5) {
                    $lockUntil = date('Y-m-d H:i:s', time() + (15 * 60));
                }

                $updateStmt = $this->pdo->prepare("
                    UPDATE `users` 
                    SET `failed_login_attempts` = :failed,
                        `locked_until` = :locked_until
                    WHERE `id` = :id
                ");
                $updateStmt->execute([
                    ':failed' => $failedAttempts,
                    ':locked_until' => $lockUntil,
                    ':id' => $user['id'],
                ]);
                $this->pdo->commit();

                if ($failedAttempts >= 5) {
                    $this->error("Invalid email or recovery code. Maximum failed attempts reached. Account locked for 15 minutes.", 'ACCOUNT_LOCKED', [
                        'remaining_minutes' => 15,
                    ], 429);
                } else {
                    $remaining = 5 - $failedAttempts;
                    $this->error("Invalid email or recovery code. {$remaining} attempt(s) remaining before temporary lockout.", 'INVALID_RECOVERY_CODE', null, 401);
                }
                return;
            }

            $rawRecoveryCode = 'SMART-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
            $newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $newRecoveryCodeHash = password_hash($rawRecoveryCode, PASSWORD_BCRYPT, ['cost' => 10]);

            $updateStmt = $this->pdo->prepare("
                UPDATE `users`
                SET `password_hash` = :password_hash,
                    `recovery_code_hash` = :recovery_code_hash,
                    `failed_login_attempts` = 0,
                    `locked_until` = NULL
                WHERE `id` = :id
            ");
            $updateStmt->execute([
                ':password_hash' => $newPasswordHash,
                ':recovery_code_hash' => $newRecoveryCodeHash,
                ':id' => $user['id'],
            ]);

            // Revoke all existing sessions on password recovery for security
            $delSessionsStmt = $this->pdo->prepare("DELETE FROM `user_sessions` WHERE `user_id` = :user_id");
            $delSessionsStmt->execute([':user_id' => $user['id']]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Issue fresh session cookie
        $this->issueSessionCookie((int) $user['id']);

        $this->json([
            'message' => 'Password reset successfully. You are now logged in.',
            'user' => [
                'id' => (int) $user['id'],
                'email' => (string) $user['email'],
                'display_name' => (string) $user['display_name'],
                'avatar_emoji' => (string) $user['avatar_emoji'],
                'avatar_color' => (string) $user['avatar_color'],
                'upi_id' => isset($user['upi_id']) && $user['upi_id'] !== null ? (string) $user['upi_id'] : null,
            ],
            'recovery_code' => $rawRecoveryCode,
        ]);
    }

    /**
     * POST /api/groups/{token}/claim-member
     * Link an existing anonymous member slot in a workspace to the authenticated user.
     */
    public function claimMember(Request $request): void
    {
        $currentUser = $request->getUser();
        if (!$currentUser) {
            $this->error("Authentication required to link member.", 'UNAUTHORIZED', null, 401);
            return;
        }

        $token = (string) $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken($token);
        if (!$group) {
            $this->error("Workspace not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $body = $request->getBody();
        $memberId = (int) ($request->getParam('memberId') ?? $body['member_id'] ?? 0);
        if ($memberId <= 0) {
            throw new InvalidArgumentException("Missing or empty required field: member_id", 422);
        }
        $userId = (int) $currentUser['id'];
        $groupId = (int) $group['id'];

        $member = $this->memberRepo->findById($memberId);
        if (!$member || (int) $member['group_id'] !== $groupId) {
            $this->error("Member not found in this workspace.", 'NOT_FOUND', null, 404);
            return;
        }

        // If already claimed by current user, return success idempotently
        if ($member['user_id'] !== null && (int) $member['user_id'] === $userId) {
            $this->json([
                'claimed' => true,
                'member_id' => $memberId,
                'member_name' => $member['name'],
                'message' => 'Member is already linked to your account.',
            ]);
            return;
        }

        // If claimed by another user, reject with 409 Conflict
        if ($member['user_id'] !== null && (int) $member['user_id'] !== $userId) {
            $this->error("This member slot is already linked to another user account.", 'MEMBER_ALREADY_CLAIMED', null, 409);
            return;
        }

        // Check if user has already claimed another member in this workspace (single claim per group policy)
        $existingClaimStmt = $this->pdo->prepare("
            SELECT `id`, `name` FROM `members`
            WHERE `group_id` = :group_id AND `user_id` = :user_id AND `id` != :member_id AND `is_active` = 1
            LIMIT 1
        ");
        $existingClaimStmt->execute([':group_id' => $groupId, ':user_id' => $userId, ':member_id' => $memberId]);
        $existingClaim = $existingClaimStmt->fetch();
        if ($existingClaim) {
            $this->error(
                "You have already claimed member '{$existingClaim['name']}' in this workspace. A user account can only represent one member per group.",
                'USER_ALREADY_CLAIMED_MEMBER',
                ['existing_member_id' => (int) $existingClaim['id'], 'existing_member_name' => $existingClaim['name']],
                409
            );
            return;
        }

        // Atomically claim member slot
        $claimStmt = $this->pdo->prepare("
            UPDATE `members`
            SET `user_id` = :user_id
            WHERE `id` = :id AND `group_id` = :group_id AND `user_id` IS NULL
        ");
        $claimStmt->execute([
            ':user_id' => $userId,
            ':id' => $memberId,
            ':group_id' => $groupId,
        ]);

        // Record Activity Log
        $this->activityLogRepo->record(
            $groupId,
            $memberId,
            'MEMBER_CLAIMED',
            'members',
            $memberId,
            [
                'member_name' => $member['name'],
                'user_id' => $userId,
                'user_name' => $currentUser['display_name'],
            ]
        );

        $this->json([
            'claimed' => true,
            'member_id' => $memberId,
            'member_name' => $member['name'],
            'message' => "Successfully linked member '{$member['name']}' to your account.",
        ]);
    }

    /**
     * POST /api/groups/{token}/unlink-member
     * Unlink authenticated user from a member slot.
     */
    public function unlinkMember(Request $request): void
    {
        $currentUser = $request->getUser();
        if (!$currentUser) {
            $this->error("Authentication required.", 'UNAUTHORIZED', null, 401);
            return;
        }

        $token = (string) $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken($token);
        if (!$group) {
            $this->error("Workspace not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $body = $request->getBody();
        $memberId = (int) ($request->getParam('memberId') ?? $body['member_id'] ?? 0);
        if ($memberId <= 0) {
            throw new InvalidArgumentException("Missing or empty required field: member_id", 422);
        }
        $userId = (int) $currentUser['id'];
        $groupId = (int) $group['id'];

        $member = $this->memberRepo->findById($memberId);
        if (!$member || (int) $member['group_id'] !== $groupId) {
            $this->error("Member not found.", 'NOT_FOUND', null, 404);
            return;
        }

        // Only the linked user or the workspace owner can unlink
        $isOwner = $group['owner_user_id'] !== null && (int) $group['owner_user_id'] === $userId;
        $isLinkedUser = $member['user_id'] !== null && (int) $member['user_id'] === $userId;

        if (!$isOwner && !$isLinkedUser) {
            $this->error("You do not have permission to unlink this member slot.", 'FORBIDDEN', null, 403);
            return;
        }

        $unlinkStmt = $this->pdo->prepare("UPDATE `members` SET `user_id` = NULL WHERE `id` = :id AND `group_id` = :group_id");
        $unlinkStmt->execute([':id' => $memberId, ':group_id' => $groupId]);

        $this->activityLogRepo->record(
            $groupId,
            $memberId,
            'MEMBER_UNLINKED',
            'members',
            $memberId,
            [
                'member_name' => $member['name'],
                'unlinked_by_user_id' => $userId,
            ]
        );

        $this->json([
            'unlinked' => true,
            'member_id' => $memberId,
            'message' => "Successfully unlinked member '{$member['name']}' from account.",
        ]);
    }

    /**
     * GET /api/user/workspaces
     * Retrieve all workspaces owned by or linked to the authenticated user with real-time net balances.
     */
    public function userWorkspaces(Request $request): void
    {
        $currentUser = $request->getUser();
        if (!$currentUser) {
            $this->error("Authentication required.", 'UNAUTHORIZED', null, 401);
            return;
        }

        $userId = (int) $currentUser['id'];

        $stmt = $this->pdo->prepare("
            SELECT DISTINCT g.`id`, g.`uuid`, g.`name`, g.`currency_code`, g.`invite_token`, g.`owner_user_id`, g.`created_at`,
                   m.`id` AS `member_id`, m.`name` AS `member_name`
            FROM `groups` g
            LEFT JOIN `members` m ON m.`group_id` = g.`id` AND m.`user_id` = :uid1 AND m.`is_active` = 1
            WHERE g.`owner_user_id` = :uid2 OR m.`user_id` = :uid3
            ORDER BY g.`updated_at` DESC
        ");
        $stmt->execute([':uid1' => $userId, ':uid2' => $userId, ':uid3' => $userId]);
        $rows = $stmt->fetchAll();

        $workspaces = [];
        foreach ($rows as $row) {
            $groupId = (int) $row['id'];
            $memberId = $row['member_id'] ? (int) $row['member_id'] : null;
            $isOwner = $row['owner_user_id'] !== null && (int) $row['owner_user_id'] === $userId;

            // Compute member net position in this workspace
            $balances = $this->balanceService->calculateGroupBalances($groupId);
            $totalSpendCents = $balances['total_spending_cents'] ?? 0;
            $memberCount = count($balances['members'] ?? []);
            
            $myNetBalanceCents = 0;
            $status = 'SETTLED';

            if ($memberId !== null) {
                foreach ($balances['members'] as $m) {
                    if ((int) $m['member_id'] === $memberId) {
                        $myNetBalanceCents = (int) ($m['net_balance_cents'] ?? 0);
                        $status = (string) ($m['status'] ?? 'SETTLED');
                        break;
                    }
                }
            }

            $workspaces[] = [
                'id' => $groupId,
                'uuid' => $row['uuid'],
                'name' => $row['name'],
                'currency' => $row['currency_code'],
                'invite_token' => $row['invite_token'],
                'is_owner' => $isOwner,
                'member_id' => $memberId,
                'member_name' => $row['member_name'],
                'net_balance_cents' => $myNetBalanceCents,
                'status' => $status,
                'member_count' => $memberCount,
                'total_spend_cents' => $totalSpendCents,
                'created_at' => $row['created_at'],
            ];
        }

        $this->json([
            'workspaces' => $workspaces,
            'total_count' => count($workspaces),
        ]);
    }

    /**
     * DELETE /api/user/account
     * Permanently delete user account while keeping financial ledgers 100% intact.
     */
    public function deleteAccount(Request $request): void
    {
        $currentUser = $request->getUser();
        if (!$currentUser) {
            $this->error("Authentication required.", 'UNAUTHORIZED', null, 401);
            return;
        }

        $body = $request->getBody();
        $password = (string) ($body['password'] ?? $body['confirm_password'] ?? '');
        if (trim($password) === '') {
            throw new InvalidArgumentException("Password confirmation is required.", 422);
        }
        $userId = (int) $currentUser['id'];

        $stmt = $this->pdo->prepare("SELECT `password_hash` FROM `users` WHERE `id` = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            $this->error("Incorrect password confirmation.", 'INVALID_PASSWORD', null, 401);
            return;
        }

        // Delete user (ON DELETE SET NULL automatically unlinks members and groups)
        $delStmt = $this->pdo->prepare("DELETE FROM `users` WHERE `id` = :id");
        $delStmt->execute([':id' => $userId]);

        $this->clearSessionCookie();

        $this->json([
            'message' => 'Account deleted successfully. Financial records remain intact.',
        ]);
    }



    /**
     * Generate secure random token, persist session in DB, and set 30-day HTTP-only cookie.
     */
    private function issueSessionCookie(int $userId): string
    {
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = date('Y-m-d H:i:s', time() + (30 * 86400));

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : 'Unknown';

        $stmt = $this->pdo->prepare("
            INSERT INTO `user_sessions` (`user_id`, `session_token_hash`, `ip_address`, `user_agent`, `expires_at`, `created_at`, `last_active_at`)
            VALUES (:user_id, :token_hash, :ip, :ua, :expires_at, NOW(), NOW())
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':token_hash' => $tokenHash,
            ':ip' => $ipAddress,
            ':ua' => $userAgent,
            ':expires_at' => $expiresAt,
        ]);

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

        if (!headers_sent()) {
            setcookie('smartsplit_session', $plainToken, [
                'expires' => time() + (30 * 86400),
                'path' => '/',
                'domain' => '',
                'secure' => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE['smartsplit_session'] = $plainToken;

        return $plainToken;
    }

    /**
     * Expire session cookie.
     */
    private function clearSessionCookie(): void
    {
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

        if (!headers_sent()) {
            setcookie('smartsplit_session', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => '',
                'secure' => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        unset($_COOKIE['smartsplit_session']);
    }
}
