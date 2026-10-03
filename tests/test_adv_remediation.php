<?php

declare(strict_types=1);

/**
 * Smart Split V2 — ADV-01 & ADV-02 Surgical Remediation & Re-Attack Suite
 *
 * Test Matrix:
 * 1. ADV-01: Category Deletion Semantic Integrity
 *    - Valid deletion in workspace (200)
 *    - Nonexistent category deletion (404)
 *    - Cross-workspace category deletion (404)
 *    - Cross-workspace category persistence verified (untouched in DB)
 *    - Already-deleted category repeated deletion (404)
 * 2. ADV-02: Emergency Password Recovery & Lockout Integrity
 *    - Password lockout threshold enforcement (5 attempts -> 429)
 *    - Correct password during active lockout (429)
 *    - Invalid recovery code during lockout (429, no bypass)
 *    - Valid emergency recovery code during lockout (200, unlocks account)
 *    - Post-recovery login with new password (200)
 *    - Post-recovery old password rejected (401)
 *    - Recovery code rotation verified (old code cannot be replayed)
 *    - Session invalidation verified (pre-existing sessions revoked)
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Middleware\SecurityHeadersMiddleware;
use App\Core\Middleware\AuthSessionMiddleware;
use App\Controllers\CategoryController;
use App\Controllers\AuthController;
use App\Repositories\GroupRepository;
use App\Repositories\CategoryRepository;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();
try {
    $pdo->exec("DELETE FROM `rate_limits` WHERE `key_hash` = '" . hash('sha256', 'auth_register:127.0.0.1') . "'");
} catch (\Throwable $e) {}

echo "\n================================================================================\n";
echo " ADV-01 & ADV-02 SURGICAL REMEDIATION & RE-ATTACK SUITE\n";
echo "================================================================================\n\n";

$passed = 0;
$total = 0;
$failures = [];

function assertRem(bool $condition, string $testId, string $description, ?string $details = null): void {
    global $passed, $total, $failures;
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$testId}: {$description}\n";
    } else {
        echo "  [FAIL] {$testId}: {$description}\n";
        if ($details) {
            echo "         > Details: {$details}\n";
        }
        $failures[] = ['id' => $testId, 'desc' => $description, 'details' => $details];
    }
}

$router = new Router();
$router->use(new SecurityHeadersMiddleware());
$router->use(new AuthSessionMiddleware($pdo));

$router->get('/api/groups/{token}/categories', [CategoryController::class, 'index']);
$router->post('/api/groups/{token}/categories', [CategoryController::class, 'create']);
$router->delete('/api/groups/{token}/categories/{id}', [CategoryController::class, 'delete']);

$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->post('/api/auth/recover-password', [AuthController::class, 'recoverPassword']);

$headers = ['Content-Type' => 'application/json', 'X-Requested-With' => 'fetch'];

function dispatchReq(Router $router, string $method, string $path, ?array $body = null): array {
    global $headers;
    $req = new Request($method, $path, null, $body, $headers);
    ob_start();
    try {
        $router->dispatch($req);
    } catch (\Throwable $e) {
        $code = ($e->getCode() >= 400 && $e->getCode() <= 599) ? (int)$e->getCode() : 500;
        Response::json(['success' => false, 'error' => ['message' => $e->getMessage()]], $code);
    }
    $out = ob_get_clean();
    return ['status' => Response::$lastStatusCode, 'data' => json_decode($out, true), 'raw' => $out];
}

// =============================================================================
// SECTION 1: ADV-01 CATEGORY DELETION INTEGRITY
// =============================================================================
echo "--- 1. ADV-01: Category Deletion Semantic Integrity ---\n";

$groupRepo = new GroupRepository($pdo);
$catRepo = new CategoryRepository($pdo);

$groupA = $groupRepo->create('Adv Category Group A ' . bin2hex(random_bytes(2)), 'INR');
$tokenA = (string) $groupA['invite_token'];
$gidA = (int) $groupA['id'];

$groupB = $groupRepo->create('Adv Category Group B ' . bin2hex(random_bytes(2)), 'INR');
$tokenB = (string) $groupB['invite_token'];
$gidB = (int) $groupB['id'];

// Create custom category in Group A and Group B
$catA = $catRepo->createCustom($gidA, 'Custom Category A', '🏷️', '#2563eb');
$catB = $catRepo->createCustom($gidB, 'Custom Category B', '🍕', '#16a34a');

// Test 1.1: Cross-workspace deletion attempt (Token A deletes Category B)
$resCross = dispatchReq($router, 'DELETE', "/api/groups/{$tokenA}/categories/{$catB}");
assertRem(
    $resCross['status'] === 404 && ($resCross['data']['error']['code'] ?? '') === 'NOT_FOUND',
    'ADV01.1',
    'Cross-workspace category deletion returns HTTP 404 NOT_FOUND'
);

// Test 1.2: Verify Category B is completely untouched in database
$catBRecord = $catRepo->findById($catB);
assertRem(
    $catBRecord !== null && (int)$catBRecord['group_id'] === $gidB && $catBRecord['name'] === 'Custom Category B',
    'ADV01.2',
    'Cross-workspace category remains unmodified in target workspace database'
);

// Test 1.3: Non-existent category deletion attempt
$resNonExistent = dispatchReq($router, 'DELETE', "/api/groups/{$tokenA}/categories/9999999");
assertRem(
    $resNonExistent['status'] === 404 && ($resNonExistent['data']['error']['code'] ?? '') === 'NOT_FOUND',
    'ADV01.3',
    'Non-existent category deletion returns HTTP 404 NOT_FOUND'
);

// Test 1.4: Valid category deletion in owning workspace
$resValid = dispatchReq($router, 'DELETE', "/api/groups/{$tokenA}/categories/{$catA}");
assertRem(
    $resValid['status'] === 200 && ($resValid['data']['data']['deleted'] ?? false) === true,
    'ADV01.4',
    'Valid category deletion in owning workspace returns HTTP 200 with deleted=true'
);

// Test 1.5: Repeated deletion of already-deleted category
$resRepeat = dispatchReq($router, 'DELETE', "/api/groups/{$tokenA}/categories/{$catA}");
assertRem(
    $resRepeat['status'] === 404 && ($resRepeat['data']['error']['code'] ?? '') === 'NOT_FOUND',
    'ADV01.5',
    'Repeated deletion of already-deleted category returns HTTP 404 NOT_FOUND'
);

// =============================================================================
// SECTION 2: ADV-02 EMERGENCY PASSWORD RECOVERY & LOCKOUT
// =============================================================================
echo "\n--- 2. ADV-02: Password Recovery & Lockout Integrity ---\n";

$email = 'adv_user_' . bin2hex(random_bytes(3)) . '@example.com';
$origPassword = 'OriginalSecurePassword123!';

$regRes = dispatchReq($router, 'POST', '/api/auth/register', [
    'email' => $email,
    'password' => $origPassword,
    'display_name' => 'Adv Remediation User',
]);
assertRem($regRes['status'] === 201, 'ADV02.1', 'User account registered with emergency recovery code');

$rawRecoveryCode = (string) ($regRes['data']['data']['recovery_code'] ?? '');

// Insert dummy active session token to verify session revocation
$dummyToken = bin2hex(random_bytes(32));
$dummyHash = hash('sha256', $dummyToken);
$userId = (int) ($regRes['data']['data']['user']['id'] ?? 0);
$pdo->exec("
    INSERT INTO `user_sessions` (`user_id`, `session_token_hash`, `ip_address`, `user_agent`, `expires_at`, `created_at`, `last_active_at`)
    VALUES ({$userId}, '{$dummyHash}', '127.0.0.1', 'AdvRunner', DATE_ADD(NOW(), INTERVAL 30 DAY), NOW(), NOW())
");

// Test 2.2: Normal login below failure threshold (4 attempts)
for ($i = 1; $i <= 4; $i++) {
    $failRes = dispatchReq($router, 'POST', '/api/auth/login', ['email' => $email, 'password' => 'WrongPassword!']);
    assertRem($failRes['status'] === 401, "ADV02.2.{$i}", "Failed password attempt {$i} returns 401");
}

// Test 2.3: 5th failed attempt triggers 15-min lockout
$lockRes = dispatchReq($router, 'POST', '/api/auth/login', ['email' => $email, 'password' => 'WrongPassword!']);
assertRem(
    $lockRes['status'] === 429 && ($lockRes['data']['error']['code'] ?? '') === 'ACCOUNT_LOCKED',
    'ADV02.3',
    '5th failed password attempt triggers HTTP 429 ACCOUNT_LOCKED'
);

// Test 2.4: Correct password during active lockout is still rejected
$correctDuringLock = dispatchReq($router, 'POST', '/api/auth/login', ['email' => $email, 'password' => $origPassword]);
assertRem(
    $correctDuringLock['status'] === 429,
    'ADV02.4',
    'Correct password submitted during active lockout is rejected with HTTP 429'
);

// Test 2.5: Invalid recovery code during active lockout cannot bypass lock
$invalidRecDuringLock = dispatchReq($router, 'POST', '/api/auth/recover-password', [
    'email' => $email,
    'recovery_code' => 'SMART-FAKE-CODE',
    'new_password' => 'HackedPassword999!',
]);
assertRem(
    $invalidRecDuringLock['status'] === 429,
    'ADV02.5',
    'Invalid recovery code during lockout is rejected with HTTP 429 (no lockout bypass)'
);

// Test 2.6: Valid emergency recovery code during lockout successfully resets password
$newPassword = 'NewSecurePassword456!';
$validRec = dispatchReq($router, 'POST', '/api/auth/recover-password', [
    'email' => $email,
    'recovery_code' => $rawRecoveryCode,
    'new_password' => $newPassword,
]);
assertRem(
    $validRec['status'] === 200 && ($validRec['data']['success'] ?? false) === true && isset($validRec['data']['data']['recovery_code']),
    'ADV02.6',
    'Valid recovery code during lockout resets password and unlocks account'
);

$newRotatedRecoveryCode = (string) ($validRec['data']['data']['recovery_code'] ?? '');

// Test 2.7: Login with newly recovered password succeeds
$newLogin = dispatchReq($router, 'POST', '/api/auth/login', ['email' => $email, 'password' => $newPassword]);
assertRem(
    $newLogin['status'] === 200 && ($newLogin['data']['success'] ?? false) === true,
    'ADV02.7',
    'Login with newly recovered password succeeds immediately'
);

// Test 2.8: Old password is no longer valid
$oldLogin = dispatchReq($router, 'POST', '/api/auth/login', ['email' => $email, 'password' => $origPassword]);
assertRem(
    $oldLogin['status'] === 401,
    'ADV02.8',
    'Old password is completely invalidated after recovery'
);

// Test 2.9: Replay of old recovery code is rejected
$replayRec = dispatchReq($router, 'POST', '/api/auth/recover-password', [
    'email' => $email,
    'recovery_code' => $rawRecoveryCode,
    'new_password' => 'AnotherPassword123!',
]);
assertRem(
    $replayRec['status'] === 401,
    'ADV02.9',
    'Replay of old recovery code is rejected after rotation'
);

// Test 2.10: Pre-existing session tokens are completely revoked
$sessStmt = $pdo->prepare("SELECT COUNT(*) FROM `user_sessions` WHERE `session_token_hash` = :hash");
$sessStmt->execute([':hash' => $dummyHash]);
$preExistingSessionCount = (int) $sessStmt->fetchColumn();
assertRem(
    $preExistingSessionCount === 0,
    'ADV02.10',
    'All pre-existing user sessions revoked upon password recovery'
);

// =============================================================================
// CLEANUP
// =============================================================================
try {
    $pdo->exec("DELETE FROM `categories` WHERE `id` IN ({$catA}, {$catB})");
    $pdo->exec("DELETE FROM `groups` WHERE `id` IN ({$gidA}, {$gidB})");
    $pdo->exec("DELETE FROM `user_sessions` WHERE `user_id` = {$userId}");
    $pdo->exec("DELETE FROM `users` WHERE `id` = {$userId}");
} catch (\Throwable $e) {}

echo "\n================================================================================\n";
echo " ADV-01 & ADV-02 REMEDIATION SUMMARY\n";
echo " Total Assertions  : {$total}\n";
echo " Passed Assertions : {$passed}\n";
echo " Failed Assertions : " . count($failures) . "\n";
echo " Pass Rate         : " . sprintf('%.2f%%', ($passed / max(1, $total)) * 100) . "\n";
echo "================================================================================\n\n";

if (!empty($failures)) {
    echo "FAILED ASSERTIONS:\n";
    foreach ($failures as $f) {
        echo " - [{$f['id']}] {$f['desc']}\n";
    }
    exit(1);
}

echo ">>> VERDICT: ADV-01 & ADV-02 SURGICAL REMEDIATION 100% PASS <<<\n";
exit(0);
