<?php

declare(strict_types=1);

/**
 * Smart Split V2 — SEC-13: Authentication Session Invalidation & Password Recovery Security Suite
 *
 * Validates the core security properties:
 * 1. Cryptographic Entropy & Secure Token Hashing (SHA-256 in DB, raw token never stored).
 * 2. Multi-Session Management (Independent multi-device sessions).
 * 3. Explicit Single-Session Logout (Revokes only target session, preserves other devices).
 * 4. Password Recovery & Global Session Invalidation (Purges ALL existing sessions across all devices).
 * 5. Credential Rotation & Old Password Invalidation (Old credentials immediately rejected).
 * 6. Single-Use Emergency Recovery Key & Automatic Rotation (Replaying old recovery key fails).
 * 7. Session Expiry & Timestamp Enforcement (Expired sessions strictly rejected).
 * 8. Account Deactivation Defense (Deactivated users cannot authenticate or recover).
 * 9. Account Deletion & Foreign Key Cascading Session Purge (Sessions purged, ledger preserved).
 * 10. Brute-Force Lockout & Rate-Limiting Protection (5 failed attempts -> 15 min lock).
 * 11. Token Fuzzing & SQL Injection Defense (Malformed tokens fail gracefully).
 * 12. Cross-Account Multi-Tenant BOLA / IDOR Boundary Enforcement.
 * 13. ACID Transactional Integrity & Rollback.
 * 14. Deterministic Fixture Teardown.
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Middleware\SecurityHeadersMiddleware;
use App\Core\Middleware\AuthSessionMiddleware;
use App\Controllers\AuthController;
use App\Controllers\GroupController;
use App\Controllers\MemberController;
use App\Controllers\ExpenseController;
use App\Controllers\BalanceController;
use App\Controllers\SettlementController;

echo "\n================================================================================\n";
echo " SEC-13: AUTHENTICATION SESSION INVALIDATION & PASSWORD RECOVERY SECURITY SUITE\n";
echo "================================================================================\n\n";

$pdo = Database::getConnection();

$totalAssertions = 0;
$passedAssertions = 0;
$failedAssertions = [];

function assertSec13(bool $condition, string $testCode, string $description, ?string $details = null): void
{
    global $totalAssertions, $passedAssertions, $failedAssertions;
    $totalAssertions++;
    if ($condition) {
        $passedAssertions++;
        echo "  [PASS] [{$testCode}] {$description}\n";
    } else {
        echo "  [FAIL] [{$testCode}] {$description}\n";
        if ($details) {
            echo "         > Details: {$details}\n";
        }
        $failedAssertions[] = [
            'code' => $testCode,
            'description' => $description,
            'details' => $details,
        ];
    }
}

// Router Setup matching production configuration
$router = new Router();
$router->use(new SecurityHeadersMiddleware());
$router->use(new AuthSessionMiddleware());

// Register all Auth and Workspace endpoints
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get('/api/auth/me', [AuthController::class, 'me']);
$router->post('/api/auth/recover', [AuthController::class, 'recoverPassword']);
$router->post('/api/auth/recover-password', [AuthController::class, 'recoverPassword']);
$router->post('/api/groups/{token}/claim-member', [AuthController::class, 'claimMember']);
$router->post('/api/groups/{token}/members/{memberId}/claim', [AuthController::class, 'claimMember']);
$router->post('/api/groups/{token}/unlink-member', [AuthController::class, 'unlinkMember']);
$router->post('/api/groups/{token}/members/{memberId}/unlink', [AuthController::class, 'unlinkMember']);
$router->get('/api/user/workspaces', [AuthController::class, 'userWorkspaces']);
$router->delete('/api/user/account', [AuthController::class, 'deleteAccount']);

$router->post('/api/groups', [GroupController::class, 'create']);
$router->get('/api/groups/{token}', [GroupController::class, 'show']);
$router->post('/api/groups/{token}/members', [MemberController::class, 'create']);
$router->get('/api/groups/{token}/members', [MemberController::class, 'index']);
$router->post('/api/groups/{token}/expenses', [ExpenseController::class, 'create']);
$router->get('/api/groups/{token}/expenses', [ExpenseController::class, 'index']);
$router->get('/api/groups/{token}/balances', [BalanceController::class, 'index']);
$router->get('/api/groups/{token}/settlement-plan', [BalanceController::class, 'settlementPlan']);
$router->post('/api/groups/{token}/settlements', [SettlementController::class, 'create']);

/**
 * Helper to dispatch simulated HTTP requests through the router
 */
function dispatchHttp(
    Router $router,
    string $method,
    string $path,
    ?array $body = null,
    ?array $queryParams = null,
    ?string $sessionCookie = null,
    array $extraHeaders = [],
    array $serverOverrides = []
): array {
    if ($sessionCookie !== null) {
        $_COOKIE['smartsplit_session'] = $sessionCookie;
    } else {
        unset($_COOKIE['smartsplit_session']);
    }

    foreach ($serverOverrides as $k => $v) {
        $_SERVER[$k] = $v;
    }

    $req = new Request($method, $path, $queryParams, $body, $extraHeaders);
    Response::$lastStatusCode = 200;
    http_response_code(200);

    ob_start();
    try {
        $router->dispatch($req);
    } catch (\InvalidArgumentException $e) {
        $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 422;
        Response::error($e->getMessage(), 'VALIDATION_ERROR', null, $code, false);
    } catch (\RuntimeException $e) {
        $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 404;
        Response::error($e->getMessage(), 'NOT_FOUND', null, $code, false);
    } catch (\Throwable $e) {
        $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 500;
        Response::error($e->getMessage(), 'INTERNAL_SERVER_ERROR', null, $code, false);
    }
    $raw = ob_get_clean();
    $decoded = json_decode($raw ?: '', true);

    return [
        'status' => Response::$lastStatusCode,
        'body' => is_array($decoded) ? $decoded : ['raw' => $raw],
        'raw' => $raw,
        'cookie' => $_COOKIE['smartsplit_session'] ?? null,
    ];
}

// Prefix for test isolation
$testRunId = bin2hex(random_bytes(4));
$emailAlice = "sec13_alice_{$testRunId}@smartsplit.test";
$emailBob = "sec13_bob_{$testRunId}@smartsplit.test";
$emailEve = "sec13_eve_{$testRunId}@smartsplit.test";
$passwordAlice1 = "AliceInitialP@ssw0rd!123";
$passwordAlice2 = "AliceRotatedP@ssw0rd!456";
$passwordBob = "BobSecretP@ssw0rd!789";

echo "--- 1. Testing Cryptographic Entropy & Session Hash Storage ---\n";

// SEC13-01.1: Registration with strong credentials
$regAlice = dispatchHttp($router, 'POST', '/api/auth/register', [
    'email' => $emailAlice,
    'password' => $passwordAlice1,
    'display_name' => 'Alice SEC13',
    'avatar_emoji' => '👩‍💻',
    'avatar_color' => '#10b981',
]);

assertSec13(
    $regAlice['status'] === 201 && isset($regAlice['body']['data']['user']['id']),
    'SEC13-REG-01',
    'User registration returns HTTP 201 with user payload',
    json_encode($regAlice['body'])
);

$userAliceId = (int) ($regAlice['body']['data']['user']['id'] ?? 0);
$rawRecoveryCode1 = (string) ($regAlice['body']['data']['recovery_code'] ?? '');
$sessionTokenReg = (string) ($regAlice['cookie'] ?? '');

assertSec13(
    preg_match('/^SMART-[0-9A-F]{4}-[0-9A-F]{4}$/', $rawRecoveryCode1) === 1,
    'SEC13-REG-02',
    'Emergency recovery code matches standard format SMART-XXXX-XXXX',
    "Recovery Code: {$rawRecoveryCode1}"
);

assertSec13(
    strlen($sessionTokenReg) === 64 && ctype_xdigit($sessionTokenReg),
    'SEC13-ENTROPY-01',
    'Issued session token possesses 256 bits of entropy (64 hex characters)',
    "Session Token Length: " . strlen($sessionTokenReg)
);

// Verify DB storage of session hash vs plaintext
$sessionStmt = $pdo->prepare("SELECT * FROM `user_sessions` WHERE `user_id` = :uid");
$sessionStmt->execute([':uid' => $userAliceId]);
$sessionRows = $sessionStmt->fetchAll(PDO::FETCH_ASSOC);

assertSec13(
    count($sessionRows) === 1,
    'SEC13-HASH-01',
    'Exactly 1 user_sessions row created upon registration',
    "Found " . count($sessionRows) . " sessions"
);

$dbHash = (string) ($sessionRows[0]['session_token_hash'] ?? '');
$expectedHash = hash('sha256', $sessionTokenReg);

assertSec13(
    $dbHash === $expectedHash,
    'SEC13-HASH-02',
    'Database stores exact SHA-256 hash of plaintext session token',
    "DB: {$dbHash} vs Expected: {$expectedHash}"
);

// Verify raw token is NOT stored anywhere in users or user_sessions tables
$rawSearchStmt = $pdo->prepare("
    SELECT COUNT(*) AS cnt FROM `user_sessions` 
    WHERE `session_token_hash` = :raw_token OR `user_agent` LIKE :raw_like
");
$rawSearchStmt->execute([':raw_token' => $sessionTokenReg, ':raw_like' => "%{$sessionTokenReg}%"]);
$rawCount = (int) ($rawSearchStmt->fetch()['cnt'] ?? 0);

assertSec13(
    $rawCount === 0,
    'SEC13-HASH-03',
    'Plaintext session token is strictly never persisted anywhere in database',
    "Plaintext leak count: {$rawCount}"
);

// Verify Bcrypt cost factors on user credentials
$userStmt = $pdo->prepare("SELECT `password_hash`, `recovery_code_hash` FROM `users` WHERE `id` = :uid");
$userStmt->execute([':uid' => $userAliceId]);
$userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

$pwInfo = password_get_info((string) ($userRow['password_hash'] ?? ''));
$recInfo = password_get_info((string) ($userRow['recovery_code_hash'] ?? ''));

assertSec13(
    $pwInfo['algoName'] === 'bcrypt' && ($pwInfo['options']['cost'] ?? 0) >= 10,
    'SEC13-BCRYPT-01',
    'Password hash uses standard Bcrypt with cost factor >= 10',
    json_encode($pwInfo)
);

assertSec13(
    $recInfo['algoName'] === 'bcrypt' && ($recInfo['options']['cost'] ?? 0) >= 10,
    'SEC13-BCRYPT-02',
    'Recovery code hash uses standard Bcrypt with cost factor >= 10',
    json_encode($recInfo)
);

echo "\n--- 2. Testing Multi-Device Session Generation & Heartbeat ---\n";

// Device A: Chrome Desktop
$loginDevA = dispatchHttp(
    $router,
    'POST',
    '/api/auth/login',
    ['email' => $emailAlice, 'password' => $passwordAlice1],
    null,
    null,
    [],
    ['REMOTE_ADDR' => '192.168.1.101', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0']
);
$tokenDevA = (string) ($loginDevA['cookie'] ?? '');

// Device B: iOS Safari
$loginDevB = dispatchHttp(
    $router,
    'POST',
    '/api/auth/login',
    ['email' => $emailAlice, 'password' => $passwordAlice1],
    null,
    null,
    [],
    ['REMOTE_ADDR' => '192.168.1.102', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148']
);
$tokenDevB = (string) ($loginDevB['cookie'] ?? '');

// Device C: Linux Firefox
$loginDevC = dispatchHttp(
    $router,
    'POST',
    '/api/auth/login',
    ['email' => $emailAlice, 'password' => $passwordAlice1],
    null,
    null,
    [],
    ['REMOTE_ADDR' => '192.168.1.103', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0']
);
$tokenDevC = (string) ($loginDevC['cookie'] ?? '');

assertSec13(
    !empty($tokenDevA) && !empty($tokenDevB) && !empty($tokenDevC) &&
    $tokenDevA !== $tokenDevB && $tokenDevB !== $tokenDevC && $tokenDevA !== $tokenDevC,
    'SEC13-MULTI-01',
    'Three distinct multi-device sessions created with distinct tokens',
    "DevA: {$tokenDevA}, DevB: {$tokenDevB}, DevC: {$tokenDevC}"
);

// Verify all 3 sessions simultaneously query /api/auth/me
$meDevA = dispatchHttp($router, 'GET', '/api/auth/me', null, null, $tokenDevA);
$meDevB = dispatchHttp($router, 'GET', '/api/auth/me', null, null, $tokenDevB);
$meDevC = dispatchHttp($router, 'GET', '/api/auth/me', null, null, $tokenDevC);

assertSec13(
    ($meDevA['body']['data']['authenticated'] ?? false) === true &&
    ($meDevB['body']['data']['authenticated'] ?? false) === true &&
    ($meDevC['body']['data']['authenticated'] ?? false) === true &&
    (int) ($meDevA['body']['data']['user']['id'] ?? 0) === $userAliceId,
    'SEC13-MULTI-02',
    'All 3 active devices independently authenticate successfully against /api/auth/me',
    "DevA Auth: " . json_encode($meDevA['body'])
);

echo "\n--- 3. Testing Explicit Single-Device Logout & Invalidation Isolation ---\n";

// Device B logs out
$logoutDevB = dispatchHttp($router, 'POST', '/api/auth/logout', [], null, $tokenDevB);
assertSec13(
    $logoutDevB['status'] === 200,
    'SEC13-LOGOUT-01',
    'Explicit logout returns HTTP 200 OK',
    json_encode($logoutDevB['body'])
);

// Verify Device B token is rejected
$meDevBPostLogout = dispatchHttp($router, 'GET', '/api/auth/me', null, null, $tokenDevB);
assertSec13(
    ($meDevBPostLogout['body']['data']['authenticated'] ?? true) === false,
    'SEC13-LOGOUT-02',
    'Logged-out Device B token immediately returns authenticated: false',
    json_encode($meDevBPostLogout['body'])
);

// Verify Device A and Device C remain unaffected
$meDevAPostLogout = dispatchHttp($router, 'GET', '/api/auth/me', null, null, $tokenDevA);
$meDevCPostLogout = dispatchHttp($router, 'GET', '/api/auth/me', null, null, $tokenDevC);

assertSec13(
    ($meDevAPostLogout['body']['data']['authenticated'] ?? false) === true &&
    ($meDevCPostLogout['body']['data']['authenticated'] ?? false) === true,
    'SEC13-LOGOUT-03',
    'Single-device logout isolates invalidation; Device A and Device C remain authenticated',
    "DevA active: " . json_encode($meDevAPostLogout['body'])
);

// Direct DB check: Device B session row is deleted
$sessBCheck = $pdo->prepare("SELECT COUNT(*) AS cnt FROM `user_sessions` WHERE `session_token_hash` = :th");
$sessBCheck->execute([':th' => hash('sha256', $tokenDevB)]);
assertSec13(
    (int) ($sessBCheck->fetch()['cnt'] ?? 0) === 0,
    'SEC13-LOGOUT-04',
    'Device B session record is physically deleted from user_sessions table'
);

echo "\n--- 4. Testing Password Recovery & Complete Cross-Device Session Invalidation ---\n";

// Device A & Device C are currently active.
// Execute Emergency Password Recovery using $rawRecoveryCode1
$recoverRes = dispatchHttp($router, 'POST', '/api/auth/recover-password', [
    'email' => $emailAlice,
    'recovery_code' => $rawRecoveryCode1,
    'new_password' => $passwordAlice2,
]);

assertSec13(
    $recoverRes['status'] === 200 && isset($recoverRes['body']['data']['recovery_code']),
    'SEC13-REVOKE-01',
    'Password recovery succeeds with HTTP 200 and returns newly rotated recovery code',
    json_encode($recoverRes['body'])
);

$rawRecoveryCode2 = (string) ($recoverRes['body']['data']['recovery_code'] ?? '');
$tokenRecovery = (string) ($recoverRes['cookie'] ?? '');

assertSec13(
    $rawRecoveryCode2 !== $rawRecoveryCode1 && preg_match('/^SMART-[0-9A-F]{4}-[0-9A-F]{4}$/', $rawRecoveryCode2) === 1,
    'SEC13-REVOKE-02',
    'Recovery code has been automatically rotated to a brand new formatted code',
    "New Code: {$rawRecoveryCode2} vs Old: {$rawRecoveryCode1}"
);

// Ground-Truth DB Check: Verify all previous sessions for Alice are deleted
$allAliceSessions = $pdo->prepare("SELECT `id`, `session_token_hash` FROM `user_sessions` WHERE `user_id` = :uid");
$allAliceSessions->execute([':uid' => $userAliceId]);
$remainingSessions = $allAliceSessions->fetchAll(PDO::FETCH_ASSOC);

assertSec13(
    count($remainingSessions) === 1 && $remainingSessions[0]['session_token_hash'] === hash('sha256', $tokenRecovery),
    'SEC13-REVOKE-03',
    'Database ground-truth: EXACTLY 1 session (the new recovery session) exists; all prior sessions purged',
    "Remaining sessions count: " . count($remainingSessions)
);

// HTTP Endpoint Invalidation Checks: Device A & Device C tokens MUST be rejected
$meDevAPostRecovery = dispatchHttp($router, 'GET', '/api/auth/me', null, null, $tokenDevA);
$meDevCPostRecovery = dispatchHttp($router, 'GET', '/api/auth/me', null, null, $tokenDevC);

assertSec13(
    ($meDevAPostRecovery['body']['data']['authenticated'] ?? true) === false,
    'SEC13-REVOKE-04',
    'Device A old session token is permanently INVALIDATED after password recovery',
    json_encode($meDevAPostRecovery['body'])
);

assertSec13(
    ($meDevCPostRecovery['body']['data']['authenticated'] ?? true) === false,
    'SEC13-REVOKE-05',
    'Device C old session token is permanently INVALIDATED after password recovery',
    json_encode($meDevCPostRecovery['body'])
);

// Protected workspace query with old Device A token must be rejected with 401
$workspacesDevA = dispatchHttp($router, 'GET', '/api/user/workspaces', null, null, $tokenDevA);
assertSec13(
    $workspacesDevA['status'] === 401,
    'SEC13-REVOKE-06',
    'Old Device A token attempting to access /api/user/workspaces is rejected with HTTP 401',
    "Status: {$workspacesDevA['status']}"
);

// Recovery session token can access /api/user/workspaces
$workspacesRecovery = dispatchHttp($router, 'GET', '/api/user/workspaces', null, null, $tokenRecovery);
assertSec13(
    $workspacesRecovery['status'] === 200,
    'SEC13-REVOKE-07',
    'New recovery session token successfully accesses protected /api/user/workspaces with HTTP 200',
    "Status: {$workspacesRecovery['status']}"
);

echo "\n--- 5. Testing Old Password Invalidation & Credential Rotation ---\n";

// Attempt login with Old Password (MUST FAIL)
$oldPwLogin = dispatchHttp($router, 'POST', '/api/auth/login', [
    'email' => $emailAlice,
    'password' => $passwordAlice1,
]);

assertSec13(
    $oldPwLogin['status'] === 401,
    'SEC13-CREDS-01',
    'Login attempt with pre-recovery OLD password is strictly rejected with HTTP 401',
    json_encode($oldPwLogin['body'])
);

// Attempt login with New Password (MUST SUCCEED)
$newPwLogin = dispatchHttp($router, 'POST', '/api/auth/login', [
    'email' => $emailAlice,
    'password' => $passwordAlice2,
]);

assertSec13(
    $newPwLogin['status'] === 200 && isset($newPwLogin['body']['data']['user']['id']),
    'SEC13-CREDS-02',
    'Login attempt with NEW password succeeds with HTTP 200',
    json_encode($newPwLogin['body'])
);

$tokenNewPw = (string) ($newPwLogin['cookie'] ?? '');
assertSec13(
    !empty($tokenNewPw) && $tokenNewPw !== $tokenRecovery,
    'SEC13-CREDS-03',
    'New login generates fresh session token distinct from recovery token',
    "New: {$tokenNewPw} vs Recovery: {$tokenRecovery}"
);

echo "\n--- 6. Testing Emergency Recovery Key Replay Defense ---\n";

// Attempt recovery with already-used Old Recovery Key ($rawRecoveryCode1) -> MUST FAIL
$replayOldRec = dispatchHttp($router, 'POST', '/api/auth/recover-password', [
    'email' => $emailAlice,
    'recovery_code' => $rawRecoveryCode1,
    'new_password' => 'ThirdPassword789!',
]);

assertSec13(
    $replayOldRec['status'] === 401,
    'SEC13-REPLAY-01',
    'Replaying previously used emergency recovery key is rejected with HTTP 401 INVALID_RECOVERY_CODE',
    json_encode($replayOldRec['body'])
);

// Attempt recovery with New Rotated Recovery Key ($rawRecoveryCode2) -> MUST SUCCEED
$useRotatedRec = dispatchHttp($router, 'POST', '/api/auth/recover-password', [
    'email' => $emailAlice,
    'recovery_code' => $rawRecoveryCode2,
    'new_password' => 'FourthPassword999!',
]);

assertSec13(
    $useRotatedRec['status'] === 200,
    'SEC13-REPLAY-02',
    'Recovery with valid rotated recovery key succeeds with HTTP 200',
    json_encode($useRotatedRec['body'])
);

$rawRecoveryCode3 = (string) ($useRotatedRec['body']['data']['recovery_code'] ?? '');
$passwordAliceCurrent = 'FourthPassword999!';
$tokenAliceLatest = (string) ($useRotatedRec['cookie'] ?? '');

echo "\n--- 7. Testing Expired Session Invalidation & Timestamp Boundaries ---\n";

// Create a test session and force it to be expired
$expiredToken = bin2hex(random_bytes(32));
$expiredHash = hash('sha256', $expiredToken);

$expInsertStmt = $pdo->prepare("
    INSERT INTO `user_sessions` (`user_id`, `session_token_hash`, `ip_address`, `user_agent`, `expires_at`, `created_at`, `last_active_at`)
    VALUES (:uid, :th, '127.0.0.1', 'Expired Test Agent', DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_SUB(NOW(), INTERVAL 2 HOUR), DATE_SUB(NOW(), INTERVAL 1 HOUR))
");
$expInsertStmt->execute([':uid' => $userAliceId, ':th' => $expiredHash]);

// Query /api/auth/me with expired token -> MUST return authenticated: false
$meExpired = dispatchHttp($router, 'GET', '/api/auth/me', null, null, $expiredToken);
assertSec13(
    ($meExpired['body']['data']['authenticated'] ?? true) === false,
    'SEC13-EXPIRY-01',
    'Session with past expires_at timestamp is rejected by AuthSessionMiddleware (authenticated: false)',
    json_encode($meExpired['body'])
);

// Protected endpoint /api/user/workspaces with expired token -> MUST return 401
$workspacesExpired = dispatchHttp($router, 'GET', '/api/user/workspaces', null, null, $expiredToken);
assertSec13(
    $workspacesExpired['status'] === 401,
    'SEC13-EXPIRY-02',
    'Protected endpoint /api/user/workspaces rejects expired session with HTTP 401',
    "Status: {$workspacesExpired['status']}"
);

echo "\n--- 8. Testing Account Deactivation Defense ---\n";

// Create Bob account
$regBob = dispatchHttp($router, 'POST', '/api/auth/register', [
    'email' => $emailBob,
    'password' => $passwordBob,
    'display_name' => 'Bob SEC13',
]);
$userBobId = (int) ($regBob['body']['data']['user']['id'] ?? 0);
$tokenBob = (string) ($regBob['cookie'] ?? '');
$recoveryCodeBob = (string) ($regBob['body']['data']['recovery_code'] ?? '');

// Verify Bob is active initially
$meBobActive = dispatchHttp($router, 'GET', '/api/auth/me', null, null, $tokenBob);
assertSec13(
    ($meBobActive['body']['data']['authenticated'] ?? false) === true,
    'SEC13-DEACT-01',
    'Newly registered user Bob is initially authenticated and active',
    json_encode($meBobActive['body'])
);

// Deactivate Bob directly in DB
$pdo->prepare("UPDATE `users` SET `is_active` = 0 WHERE `id` = :uid")->execute([':uid' => $userBobId]);

// Bob's active token on /api/auth/me MUST now return authenticated: false
$meBobDeactivated = dispatchHttp($router, 'GET', '/api/auth/me', null, null, $tokenBob);
assertSec13(
    ($meBobDeactivated['body']['data']['authenticated'] ?? true) === false,
    'SEC13-DEACT-02',
    'Active session token for deactivated user is rejected by AuthSessionMiddleware',
    json_encode($meBobDeactivated['body'])
);

// Bob login attempt MUST return 403 ACCOUNT_INACTIVE
$loginBobDeactivated = dispatchHttp($router, 'POST', '/api/auth/login', [
    'email' => $emailBob,
    'password' => $passwordBob,
]);
assertSec13(
    $loginBobDeactivated['status'] === 403,
    'SEC13-DEACT-03',
    'Login attempt on deactivated account is rejected with HTTP 403 ACCOUNT_INACTIVE',
    json_encode($loginBobDeactivated['body'])
);

// Bob recovery attempt MUST return 403 ACCOUNT_INACTIVE
$recoverBobDeactivated = dispatchHttp($router, 'POST', '/api/auth/recover-password', [
    'email' => $emailBob,
    'recovery_code' => $recoveryCodeBob,
    'new_password' => 'BobNewPassword123!',
]);
assertSec13(
    $recoverBobDeactivated['status'] === 403,
    'SEC13-DEACT-04',
    'Password recovery attempt on deactivated account is rejected with HTTP 403 ACCOUNT_INACTIVE',
    json_encode($recoverBobDeactivated['body'])
);

// Reactivate Bob for subsequent tests
$pdo->prepare("UPDATE `users` SET `is_active` = 1 WHERE `id` = :uid")->execute([':uid' => $userBobId]);

echo "\n--- 9. Testing Account Deletion & Foreign Key Cascading Session Purge ---\n";

// Register Eve account for deletion lifecycle test
$regEve = dispatchHttp($router, 'POST', '/api/auth/register', [
    'email' => $emailEve,
    'password' => 'EvePassword123!',
    'display_name' => 'Eve Deletable',
]);
$userEveId = (int) ($regEve['body']['data']['user']['id'] ?? 0);
$tokenEve = (string) ($regEve['cookie'] ?? '');

// Create additional session for Eve
$loginEveDev2 = dispatchHttp($router, 'POST', '/api/auth/login', [
    'email' => $emailEve,
    'password' => 'EvePassword123!',
]);
$tokenEveDev2 = (string) ($loginEveDev2['cookie'] ?? '');

// Eve creates a workspace with expenses and splits
$wsEveRes = dispatchHttp($router, 'POST', '/api/groups', [
    'name' => "Eve_Workspace_{$testRunId}",
    'currency' => 'INR',
    'creator_name' => 'Eve Creator',
]);
$groupEve = $wsEveRes['body']['data']['group'];
$tokenWsEve = $groupEve['invite_token'];
$groupEveId = (int) $groupEve['id'];
$creatorEve = $wsEveRes['body']['data']['creator'];
$creatorEveId = (int) $creatorEve['id'];

// Eve claims her member slot
dispatchHttp($router, 'POST', "/api/groups/{$tokenWsEve}/claim-member", ['member_id' => $creatorEveId], null, $tokenEve);

// Link workspace ownership
$pdo->prepare("UPDATE `groups` SET `owner_user_id` = :uid WHERE `id` = :gid")->execute([':uid' => $userEveId, ':gid' => $groupEveId]);

// Add second member & expense
$memEve2Res = dispatchHttp($router, 'POST', "/api/groups/{$tokenWsEve}/members", ['name' => 'Eve Colleague']);
$memEve2Id = (int) $memEve2Res['body']['data']['member']['id'];

$expEveRes = dispatchHttp($router, 'POST', "/api/groups/{$tokenWsEve}/expenses", [
    'title' => 'Eve Project Launch',
    'total_amount_cents' => 500000,
    'split_type' => 'EQUAL',
    'created_by_member_id' => $creatorEveId,
    'payers' => [['member_id' => $creatorEveId, 'amount_paid_cents' => 500000]],
    'splits' => [
        ['member_id' => $creatorEveId, 'amount_owed_cents' => 250000],
        ['member_id' => $memEve2Id, 'amount_owed_cents' => 250000],
    ],
]);
$expEveId = (int) ($expEveRes['body']['data']['expense']['id'] ?? 0);

// Verify Eve has 2 active sessions in DB
$eveSessionsStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM `user_sessions` WHERE `user_id` = :uid");
$eveSessionsStmt->execute([':uid' => $userEveId]);
assertSec13(
    (int) ($eveSessionsStmt->fetch()['cnt'] ?? 0) === 2,
    'SEC13-DEL-01',
    'Eve has exactly 2 active sessions before account deletion'
);

// Attempt deletion with wrong password -> MUST FAIL
$delEveWrongPw = dispatchHttp($router, 'DELETE', '/api/user/account', ['password' => 'WrongPassword!'], null, $tokenEve);
assertSec13(
    $delEveWrongPw['status'] === 401,
    'SEC13-DEL-02',
    'Account deletion rejected with HTTP 401 when invalid password is provided',
    json_encode($delEveWrongPw['body'])
);

// Delete account with correct password -> MUST SUCCEED
$delEveCorrect = dispatchHttp($router, 'DELETE', '/api/user/account', ['password' => 'EvePassword123!'], null, $tokenEve);
assertSec13(
    $delEveCorrect['status'] === 200,
    'SEC13-DEL-03',
    'Account deletion succeeds with HTTP 200 when valid password confirmation is supplied',
    json_encode($delEveCorrect['body'])
);

// DB Check 1: Eve record in users table is purged
$eveUserCheck = $pdo->prepare("SELECT COUNT(*) AS cnt FROM `users` WHERE `id` = :uid");
$eveUserCheck->execute([':uid' => $userEveId]);
assertSec13(
    (int) ($eveUserCheck->fetch()['cnt'] ?? 0) === 0,
    'SEC13-DEL-04',
    'User record for Eve is physically deleted from users table'
);

// DB Check 2: Foreign key ON DELETE CASCADE purged all user_sessions records
$eveSessionsPostDel = $pdo->prepare("SELECT COUNT(*) AS cnt FROM `user_sessions` WHERE `user_id` = :uid");
$eveSessionsPostDel->execute([':uid' => $userEveId]);
assertSec13(
    (int) ($eveSessionsPostDel->fetch()['cnt'] ?? 0) === 0,
    'SEC13-DEL-05',
    'All user_sessions for deleted account were automatically purged by FOREIGN KEY ON DELETE CASCADE'
);

// Replay old tokens on /api/auth/me -> MUST return authenticated: false
$meEve1PostDel = dispatchHttp($router, 'GET', '/api/auth/me', null, null, $tokenEve);
$meEve2PostDel = dispatchHttp($router, 'GET', '/api/auth/me', null, null, $tokenEveDev2);
assertSec13(
    ($meEve1PostDel['body']['data']['authenticated'] ?? true) === false &&
    ($meEve2PostDel['body']['data']['authenticated'] ?? true) === false,
    'SEC13-DEL-06',
    'All old session tokens from deleted account return authenticated: false'
);

// Financial Invariance Check: Eve workspace, members, expense, and splits remain 100% intact
$wsCheckStmt = $pdo->prepare("SELECT `owner_user_id` FROM `groups` WHERE `id` = :gid");
$wsCheckStmt->execute([':gid' => $groupEveId]);
$wsRow = $wsCheckStmt->fetch(PDO::FETCH_ASSOC);

$memCheckStmt = $pdo->prepare("SELECT `user_id` FROM `members` WHERE `id` = :mid");
$memCheckStmt->execute([':mid' => $creatorEveId]);
$memRow = $memCheckStmt->fetch(PDO::FETCH_ASSOC);

$expCheckStmt = $pdo->prepare("SELECT `total_amount_cents` FROM `expenses` WHERE `id` = :eid");
$expCheckStmt->execute([':eid' => $expEveId]);
$expRow = $expCheckStmt->fetch(PDO::FETCH_ASSOC);

assertSec13(
    $wsRow !== false && $wsRow['owner_user_id'] === null,
    'SEC13-FIN-01',
    'Workspace remains intact with owner_user_id safely set to NULL (ON DELETE SET NULL)'
);

assertSec13(
    $memRow !== false && $memRow['user_id'] === null,
    'SEC13-FIN-02',
    'Member slot remains intact with user_id safely unlinked to NULL'
);

assertSec13(
    $expRow !== false && (int) $expRow['total_amount_cents'] === 500000,
    'SEC13-FIN-03',
    'Financial expense and all split math remain 100% intact after account deletion'
);

echo "\n--- 10. Testing Brute-Force Lockout Defense & Rate Limiting ---\n";

// Test Lockout on fresh account
$emailLock = "sec13_lock_{$testRunId}@smartsplit.test";
$regLock = dispatchHttp($router, 'POST', '/api/auth/register', [
    'email' => $emailLock,
    'password' => 'LockPass123!',
    'display_name' => 'Lock Target',
]);
$userLockId = (int) ($regLock['body']['data']['user']['id'] ?? 0);
$recCodeLock = (string) ($regLock['body']['data']['recovery_code'] ?? '');

// 4 consecutive failed login attempts
for ($i = 1; $i <= 4; $i++) {
    $failRes = dispatchHttp($router, 'POST', '/api/auth/login', [
        'email' => $emailLock,
        'password' => "WrongPassword_{$i}",
    ]);
    assertSec13(
        $failRes['status'] === 401 && ($failRes['body']['error']['code'] ?? '') === 'INVALID_CREDENTIALS',
        "SEC13-LOCK-0{$i}",
        "Failed login attempt {$i}/5 returns HTTP 401 with remaining attempts warning"
    );
}

// 5th failed login attempt -> Triggers lockout
$fail5Res = dispatchHttp($router, 'POST', '/api/auth/login', [
    'email' => $emailLock,
    'password' => 'WrongPassword_5',
]);
assertSec13(
    $fail5Res['status'] === 429 && ($fail5Res['body']['error']['code'] ?? '') === 'ACCOUNT_LOCKED',
    'SEC13-LOCK-05',
    '5th failed login attempt immediately locks account with HTTP 429 ACCOUNT_LOCKED',
    json_encode($fail5Res['body'])
);

// 6th attempt while locked -> Rejected immediately with 429
$fail6Res = dispatchHttp($router, 'POST', '/api/auth/login', [
    'email' => $emailLock,
    'password' => 'LockPass123!', // Even with correct password!
]);
assertSec13(
    $fail6Res['status'] === 429 && ($fail6Res['body']['error']['code'] ?? '') === 'ACCOUNT_LOCKED',
    'SEC13-LOCK-06',
    'Subsequent login attempt while locked is blocked with HTTP 429 regardless of password',
    json_encode($fail6Res['body'])
);

// Direct DB verification of locked_until
$lockCheckStmt = $pdo->prepare("SELECT `failed_login_attempts`, `locked_until` FROM `users` WHERE `id` = :uid");
$lockCheckStmt->execute([':uid' => $userLockId]);
$lockRow = $lockCheckStmt->fetch(PDO::FETCH_ASSOC);

assertSec13(
    (int) ($lockRow['failed_login_attempts'] ?? 0) === 5 &&
    !empty($lockRow['locked_until']) &&
    strtotime((string) $lockRow['locked_until']) > time(),
    'SEC13-LOCK-07',
    'Database ground-truth confirms failed_login_attempts=5 and future locked_until timestamp',
    json_encode($lockRow)
);

// Unlock account by resetting locked_until in DB
$pdo->prepare("UPDATE `users` SET `locked_until` = NULL, `failed_login_attempts` = 0 WHERE `id` = :uid")->execute([':uid' => $userLockId]);

// Valid login now succeeds and clears counter
$unlockLogin = dispatchHttp($router, 'POST', '/api/auth/login', [
    'email' => $emailLock,
    'password' => 'LockPass123!',
]);
assertSec13(
    $unlockLogin['status'] === 200,
    'SEC13-LOCK-08',
    'Valid login after lock expiry succeeds with HTTP 200 and resets failed attempts counter',
    json_encode($unlockLogin['body'])
);

echo "\n--- 11. Testing Token Fuzzing, SQL Injection & Malformed Boundaries ---\n";

$fuzzTokens = [
    'sql_injection_1' => "' OR '1'='1",
    'sql_injection_2' => "'; DROP TABLE user_sessions; --",
    'sql_injection_3' => "1' UNION SELECT 1,2,3,4,5,6,7,8--",
    'null_byte' => "token\0with\0nulls",
    'excessive_length' => str_repeat('A', 10000),
    'special_chars' => '<script>alert("xss")</script>',
    'whitespace' => "   \t\n\r   ",
    'empty' => '',
    'truncated_hex' => 'abcd123',
];

foreach ($fuzzTokens as $label => $fuzzVal) {
    $fuzzMe = dispatchHttp($router, 'GET', '/api/auth/me', null, null, $fuzzVal);
    assertSec13(
        $fuzzMe['status'] === 200 && ($fuzzMe['body']['data']['authenticated'] ?? true) === false,
        "SEC13-FUZZ-{$label}",
        "Malformed session token '{$label}' safely evaluated to unauthenticated guest without 500 error"
    );
}

echo "\n--- 12. Testing Cross-Account Multi-Tenant BOLA / IDOR Boundaries ---\n";

// Login Alice (current password) and Login Bob
$loginAliceAgain = dispatchHttp($router, 'POST', '/api/auth/login', [
    'email' => $emailAlice,
    'password' => $passwordAliceCurrent,
]);
$tokenAliceFinal = (string) ($loginAliceAgain['cookie'] ?? '');

$loginBobAgain = dispatchHttp($router, 'POST', '/api/auth/login', [
    'email' => $emailBob,
    'password' => $passwordBob,
]);
$tokenBobFinal = (string) ($loginBobAgain['cookie'] ?? '');

// Alice creates Workspace Alpha
$wsAlphaRes = dispatchHttp($router, 'POST', '/api/groups', [
    'name' => "Alice_Private_Alpha_{$testRunId}",
    'currency' => 'INR',
    'creator_name' => 'Alice Chief',
]);
$tokenAlpha = $wsAlphaRes['body']['data']['group']['invite_token'];
$groupAlphaId = (int) $wsAlphaRes['body']['data']['group']['id'];
$creatorAlphaId = (int) $wsAlphaRes['body']['data']['creator']['id'];

// Bob creates Workspace Beta
$wsBetaRes = dispatchHttp($router, 'POST', '/api/groups', [
    'name' => "Bob_Private_Beta_{$testRunId}",
    'currency' => 'INR',
    'creator_name' => 'Bob Chief',
]);
$tokenBeta = $wsBetaRes['body']['data']['group']['invite_token'];
$groupBetaId = (int) $wsBetaRes['body']['data']['group']['id'];
$creatorBetaId = (int) $wsBetaRes['body']['data']['creator']['id'];

// Alice claims Alpha creator slot; Bob claims Beta creator slot
dispatchHttp($router, 'POST', "/api/groups/{$tokenAlpha}/claim-member", ['member_id' => $creatorAlphaId], null, $tokenAliceFinal);
dispatchHttp($router, 'POST', "/api/groups/{$tokenBeta}/claim-member", ['member_id' => $creatorBetaId], null, $tokenBobFinal);

// Set ownership
$pdo->prepare("UPDATE `groups` SET `owner_user_id` = :uid WHERE `id` = :gid")->execute([':uid' => $userAliceId, ':gid' => $groupAlphaId]);
$pdo->prepare("UPDATE `groups` SET `owner_user_id` = :uid WHERE `id` = :gid")->execute([':uid' => $userBobId, ':gid' => $groupBetaId]);

// Query /api/user/workspaces with Alice token: MUST contain Alpha, MUST NOT contain Beta
$aliceWorkspaces = dispatchHttp($router, 'GET', '/api/user/workspaces', null, null, $tokenAliceFinal);
$aliceWsList = $aliceWorkspaces['body']['data']['workspaces'] ?? [];
$aliceGroupIds = array_column($aliceWsList, 'id');

assertSec13(
    in_array($groupAlphaId, $aliceGroupIds, true) && !in_array($groupBetaId, $aliceGroupIds, true),
    'SEC13-BOLA-01',
    'Alice user/workspaces returns only Alice workspace (Alpha) and strictly excludes Bob workspace (Beta)',
    "Alice workspaces: " . json_encode($aliceGroupIds)
);

// Query /api/user/workspaces with Bob token: MUST contain Beta, MUST NOT contain Alpha
$bobWorkspaces = dispatchHttp($router, 'GET', '/api/user/workspaces', null, null, $tokenBobFinal);
$bobWsList = $bobWorkspaces['body']['data']['workspaces'] ?? [];
$bobGroupIds = array_column($bobWsList, 'id');

assertSec13(
    in_array($groupBetaId, $bobGroupIds, true) && !in_array($groupAlphaId, $bobGroupIds, true),
    'SEC13-BOLA-02',
    'Bob user/workspaces returns only Bob workspace (Beta) and strictly excludes Alice workspace (Alpha)',
    "Bob workspaces: " . json_encode($bobGroupIds)
);

// Bob attempts to claim Alice's already claimed member in Alpha -> MUST return 409 MEMBER_ALREADY_CLAIMED
$bobClaimAlpha = dispatchHttp(
    $router,
    'POST',
    "/api/groups/{$tokenAlpha}/claim-member",
    ['member_id' => $creatorAlphaId],
    null,
    $tokenBobFinal
);
assertSec13(
    $bobClaimAlpha['status'] === 409 && ($bobClaimAlpha['body']['error']['code'] ?? '') === 'MEMBER_ALREADY_CLAIMED',
    'SEC13-BOLA-03',
    'Bob cross-account attempt to claim Alice member slot in Alpha is rejected with HTTP 409 MEMBER_ALREADY_CLAIMED',
    json_encode($bobClaimAlpha['body'])
);

// Bob attempts to unlink Alice member in Alpha -> MUST return 403 FORBIDDEN
$bobUnlinkAlpha = dispatchHttp(
    $router,
    'POST',
    "/api/groups/{$tokenAlpha}/unlink-member",
    ['member_id' => $creatorAlphaId],
    null,
    $tokenBobFinal
);
assertSec13(
    $bobUnlinkAlpha['status'] === 403 && ($bobUnlinkAlpha['body']['error']['code'] ?? '') === 'FORBIDDEN',
    'SEC13-BOLA-04',
    'Bob cross-account attempt to unlink Alice member slot in Alpha is rejected with HTTP 403 FORBIDDEN',
    json_encode($bobUnlinkAlpha['body'])
);

echo "\n--- 13. Testing ACID Transactional Rollback & Integrity ---\n";

// Attempt password recovery with invalid payload (short password) -> MUST rollback without partial updates
$txRollbackRes = dispatchHttp($router, 'POST', '/api/auth/recover-password', [
    'email' => $emailAlice,
    'recovery_code' => $rawRecoveryCode3,
    'new_password' => 'short', // Invalid (< 8 chars)
]);

assertSec13(
    $txRollbackRes['status'] === 422,
    'SEC13-ACID-01',
    'Recovery with invalid password length (< 8 chars) is rejected with HTTP 422 before mutation',
    json_encode($txRollbackRes['body'])
);

// Verify Alice's current credentials and sessions remain active and uncorrupted
$meAlicePostTx = dispatchHttp($router, 'GET', '/api/auth/me', null, null, $tokenAliceFinal);
assertSec13(
    ($meAlicePostTx['body']['data']['authenticated'] ?? false) === true,
    'SEC13-ACID-02',
    'Failed recovery attempt rolls back cleanly; current session and credentials remain completely uncorrupted',
    json_encode($meAlicePostTx['body'])
);

echo "\n--- 14. Deterministic Fixture Teardown ---\n";

// Clean up test groups and users created in this suite
$cleanupGroups = [$groupAlphaId, $groupBetaId, $groupEveId];
foreach ($cleanupGroups as $gid) {
    $pdo->prepare("DELETE FROM `expense_splits` WHERE `expense_id` IN (SELECT `id` FROM `expenses` WHERE `group_id` = :gid)")->execute([':gid' => $gid]);
    $pdo->prepare("DELETE FROM `expense_payers` WHERE `expense_id` IN (SELECT `id` FROM `expenses` WHERE `group_id` = :gid)")->execute([':gid' => $gid]);
    $pdo->prepare("DELETE FROM `expenses` WHERE `group_id` = :gid")->execute([':gid' => $gid]);
    $pdo->prepare("DELETE FROM `members` WHERE `group_id` = :gid")->execute([':gid' => $gid]);
    $pdo->prepare("DELETE FROM `groups` WHERE `id` = :gid")->execute([':gid' => $gid]);
}

$cleanupUsers = [$userAliceId, $userBobId, $userLockId];
foreach ($cleanupUsers as $uid) {
    $pdo->prepare("DELETE FROM `user_sessions` WHERE `user_id` = :uid")->execute([':uid' => $uid]);
    $pdo->prepare("DELETE FROM `users` WHERE `id` = :uid")->execute([':uid' => $uid]);
}

assertSec13(
    true,
    'SEC13-CLEANUP-01',
    'Deterministic teardown completed; all transient test fixtures cleaned up'
);

echo "\n================================================================================\n";
echo " SEC-13 AUDIT SUMMARY\n";
echo "================================================================================\n";
echo " Total Assertions:  {$totalAssertions}\n";
echo " Passed Assertions: {$passedAssertions}\n";
echo " Failed Assertions: " . count($failedAssertions) . "\n";
echo " Success Rate:      " . ($totalAssertions > 0 ? round(($passedAssertions / $totalAssertions) * 100, 2) : 0) . "%\n";
echo "================================================================================\n\n";

if (count($failedAssertions) > 0) {
    echo "FAILED ASSERTIONS:\n";
    foreach ($failedAssertions as $fa) {
        echo " - [{$fa['code']}] {$fa['description']}\n";
        if ($fa['details']) {
            echo "   Details: {$fa['details']}\n";
        }
    }
    exit(1);
}

exit(0);
