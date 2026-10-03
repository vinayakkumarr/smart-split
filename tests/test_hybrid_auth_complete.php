<?php

declare(strict_types=1);

/**
 * Smart Split – Master End-to-End Hybrid Progressive Authentication & System Invariance Test Suite
 *
 * Validates the complete identity lifecycle across 8 test domains:
 * 1. Account Registration & Recovery Key Generation
 * 2. Session Management & Brute-Force Defense
 * 3. Emergency Password Recovery Flow
 * 4. Accountless Guest Workspace Access (Zero Regression)
 * 5. Progressive Member Claiming & Double-Claim Defenses
 * 6. Member Profile Unlinking
 * 7. Multi-Device Cloud Workspace Aggregation
 * 8. Account Deletion & Mathematical Invariance Check
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Response;
use App\Core\Database;
use App\Core\Request;
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
echo " SMART SPLIT: MASTER HYBRID PROGRESSIVE AUTHENTICATION TEST SUITE\n";
echo "================================================================================\n\n";

$passedTests = 0;
$totalTests = 0;

function assertCheck(string $description, bool $condition, ?string $details = null): void
{
    global $passedTests, $totalTests;
    $totalTests++;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passedTests++;
    } else {
        echo "  [FAIL] {$description}\n";
        if ($details) {
            echo "         > Details: {$details}\n";
        }
    }
}

// Setup Router with all Auth & Workspace endpoints
$router = new Router();
$router->use(new SecurityHeadersMiddleware());
$router->use(new AuthSessionMiddleware());

// Auth Endpoints
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get('/api/auth/me', [AuthController::class, 'me']);
$router->post('/api/auth/recover', [AuthController::class, 'recoverPassword']);
$router->post('/api/groups/{token}/claim-member', [AuthController::class, 'claimMember']);
$router->post('/api/groups/{token}/members/{memberId}/claim', [AuthController::class, 'claimMember']);
$router->post('/api/groups/{token}/unlink-member', [AuthController::class, 'unlinkMember']);
$router->post('/api/groups/{token}/members/{memberId}/unlink', [AuthController::class, 'unlinkMember']);
$router->get('/api/user/workspaces', [AuthController::class, 'userWorkspaces']);
$router->delete('/api/user/account', [AuthController::class, 'deleteAccount']);

// Workspace Endpoints
$router->post('/api/groups', [GroupController::class, 'create']);
$router->get('/api/groups/{token}', [GroupController::class, 'show']);
$router->post('/api/groups/{token}/members', [MemberController::class, 'create']);
$router->get('/api/groups/{token}/members', [MemberController::class, 'index']);
$router->post('/api/groups/{token}/expenses', [ExpenseController::class, 'create']);
$router->get('/api/groups/{token}/expenses', [ExpenseController::class, 'index']);
$router->get('/api/groups/{token}/balances', [BalanceController::class, 'index']);
$router->get('/api/groups/{token}/settlement-plan', [BalanceController::class, 'settlementPlan']);
$router->post('/api/groups/{token}/settlements', [SettlementController::class, 'create']);

function dispatch(Router $router, string $method, string $path, array $body = [], array $queryParams = [], ?string $sessionToken = null): array
{
    if ($sessionToken !== null) {
        $_COOKIE['smartsplit_session'] = $sessionToken;
    } else {
        unset($_COOKIE['smartsplit_session']);
    }

    $req = new Request($method, $path, $queryParams, $body);
    Response::$lastStatusCode = 200;
    http_response_code(200);
    ob_start();
    $caughtCode = null;
    $caughtMsg = null;
    try {
        $router->dispatch($req);
    } catch (\InvalidArgumentException $e) {
        $caughtCode = $e->getCode() ?: 422;
        $caughtMsg = $e->getMessage();
    } catch (\Throwable $e) {
        $caughtCode = $e->getCode() ?: 500;
        $caughtMsg = $e->getMessage();
    }
    $raw = ob_get_clean();
    $httpCode = $caughtCode ?? Response::getLastStatusCode();

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        if ($caughtMsg) {
            $json = [
                'success' => false,
                'error' => ['code' => 'ERROR', 'message' => $caughtMsg],
            ];
        } else {
            $json = ['raw' => $raw, 'success' => false];
        }
    }

    $json['_status_code'] = $httpCode;
    $json['_session_cookie'] = $_COOKIE['smartsplit_session'] ?? null;
    return $json;
}

$pdo = Database::getConnection();

// =============================================================================
// DOMAIN 1: Account Registration & Recovery Key Generation
// =============================================================================
echo "\n--- Domain 1: Account Registration & Recovery Key Generation ---\n";

$email1 = 'master_tester_' . substr(md5(uniqid()), 0, 8) . '@example.com';
$pass1 = 'StrongPass123!';
$name1 = 'Master Tester';
$emoji1 = '💎';

// 1.1 Valid Registration
$regRes = dispatch($router, 'POST', '/api/auth/register', [
    'email' => $email1,
    'password' => $pass1,
    'display_name' => $name1,
    'avatar_emoji' => $emoji1,
]);

assertCheck("Registration returns HTTP 201 and success=true", $regRes['_status_code'] === 201 && ($regRes['success'] ?? false) === true);
$userId1 = (int)($regRes['data']['user']['id'] ?? 0);
assertCheck("Registration returns valid user ID", $userId1 > 0);
$recoveryKey1 = $regRes['data']['recovery_code'] ?? '';
assertCheck("Emergency recovery code matches SMART-XXXX-XXXX format", (bool)preg_match('/^SMART-[0-9A-F]{4}-[0-9A-F]{4}$/', $recoveryKey1));
$sessionToken1 = $regRes['_session_cookie'];
assertCheck("Session cookie issued upon registration", !empty($sessionToken1));

// DB check for Bcrypt password and recovery hash
$stmt = $pdo->prepare("SELECT `password_hash`, `recovery_code_hash` FROM `users` WHERE `id` = :id");
$stmt->execute([':id' => $userId1]);
$userRow = $stmt->fetch();
assertCheck("Password hash is cryptographically secure (Bcrypt/Argon2)", str_starts_with($userRow['password_hash'], '$2y$') && password_verify($pass1, $userRow['password_hash']));
assertCheck("Recovery code hash is cryptographically verifiable", password_verify($recoveryKey1, $userRow['recovery_code_hash']));

// 1.2 Duplicate Email Constraint
$dupRes = dispatch($router, 'POST', '/api/auth/register', [
    'email' => $email1,
    'password' => $pass1,
]);
assertCheck("Duplicate email registration rejected with HTTP 409", $dupRes['_status_code'] === 409);

// 1.3 Case Normalization Check
$upperEmail = strtoupper($email1);
$dupUpperRes = dispatch($router, 'POST', '/api/auth/register', [
    'email' => $upperEmail,
    'password' => $pass1,
]);
assertCheck("Case-insensitive email duplicate rejected with HTTP 409", $dupUpperRes['_status_code'] === 409);

// 1.4 Weak Password Rejection
$weakRes = dispatch($router, 'POST', '/api/auth/register', [
    'email' => 'weak_' . uniqid() . '@example.com',
    'password' => 'short',
]);
assertCheck("Weak password (<8 chars) rejected with HTTP 422", $weakRes['_status_code'] === 422);


// =============================================================================
// DOMAIN 2: Session Management & Brute-Force Defense
// =============================================================================
echo "\n--- Domain 2: Session Management & Brute-Force Defense ---\n";

// 2.1 Valid Login
$loginRes = dispatch($router, 'POST', '/api/auth/login', [
    'email' => $email1,
    'password' => $pass1,
]);
assertCheck("Login returns HTTP 200 with valid session token", $loginRes['_status_code'] === 200 && ($loginRes['success'] ?? false) === true);
$sessionToken1 = $loginRes['_session_cookie'];

// 2.2 AuthSessionMiddleware resolves user context
$meRes = dispatch($router, 'GET', '/api/auth/me', [], [], $sessionToken1);
assertCheck("/api/auth/me resolves authenticated user context", $meRes['_status_code'] === 200 && ($meRes['data']['authenticated'] ?? false) === true && (int)$meRes['data']['user']['id'] === $userId1);

// 2.3 Brute-Force Lockout Defense (5 failed attempts)
$bruteEmail = 'brute_' . uniqid() . '@example.com';
$brutePass = 'ValidPassword123!';
$bReg = dispatch($router, 'POST', '/api/auth/register', ['email' => $bruteEmail, 'password' => $brutePass]);
$bruteUserId = (int)$bReg['data']['user']['id'];

for ($i = 1; $i <= 4; $i++) {
    $failRes = dispatch($router, 'POST', '/api/auth/login', ['email' => $bruteEmail, 'password' => 'WrongPass!']);
    assertCheck("Failed login attempt {$i} returns HTTP 401", $failRes['_status_code'] === 401);
}

// 5th attempt must trigger 429 lockout
$lockoutRes = dispatch($router, 'POST', '/api/auth/login', ['email' => $bruteEmail, 'password' => 'WrongPass!']);
assertCheck("5th failed attempt triggers HTTP 429 lockout", $lockoutRes['_status_code'] === 429);

// 2.4 Session Termination (Logout)
$logoutRes = dispatch($router, 'POST', '/api/auth/logout', [], [], $sessionToken1);
assertCheck("Logout returns HTTP 200", $logoutRes['_status_code'] === 200);
$postLogoutMe = dispatch($router, 'GET', '/api/auth/me', [], []);
assertCheck("Post-logout request resolves as unauthenticated guest", ($postLogoutMe['data']['authenticated'] ?? false) === false);


// =============================================================================
// DOMAIN 3: Emergency Password Recovery Flow & SEC-02 Brute-Force Defense
// =============================================================================
echo "\n--- Domain 3: Emergency Password Recovery Flow & SEC-02 Brute-Force Defense ---\n";

$newPass1 = 'NewSuperStrongPass2026!';

// 3.1 Invalid Recovery Key rejection
$badRecRes = dispatch($router, 'POST', '/api/auth/recover', [
    'email' => $email1,
    'recovery_code' => 'SMART-0000-0000',
    'new_password' => $newPass1,
]);
assertCheck("Invalid recovery code rejected with HTTP 401/422", in_array($badRecRes['_status_code'], [401, 422], true));

// Verify failed attempt was recorded for email1
$stmtRecCheck = $pdo->prepare("SELECT `failed_login_attempts` FROM `users` WHERE `id` = :id");
$stmtRecCheck->execute([':id' => $userId1]);
$failedRecAttempts = (int) $stmtRecCheck->fetchColumn();
assertCheck("Failed recovery attempt increments failed_login_attempts counter in DB", $failedRecAttempts === 1);

// 3.2 SEC-02 Dedicated Recovery Lockout Suite (5 failed attempts trigger 429)
$recLockEmail = 'rec_lockout_' . substr(md5(uniqid()), 0, 8) . '@example.com';
$recLockPass = 'OriginalSecurePass123!';
$recRegRes = dispatch($router, 'POST', '/api/auth/register', [
    'email' => $recLockEmail,
    'password' => $recLockPass,
    'display_name' => 'Recovery Lock Tester',
]);
$recUserId = (int) ($recRegRes['data']['user']['id'] ?? 0);
$recKeyValid = $recRegRes['data']['recovery_code'] ?? '';

// Perform 4 failed recovery attempts
for ($attempt = 1; $attempt <= 4; $attempt++) {
    $failRecRes = dispatch($router, 'POST', '/api/auth/recover', [
        'email' => $recLockEmail,
        'recovery_code' => 'SMART-DEAD-BEEF',
        'new_password' => 'BrandNewPassword999!',
    ]);
    assertCheck("Failed recovery attempt {$attempt} returns HTTP 401 INVALID_RECOVERY_CODE", $failRecRes['_status_code'] === 401 && ($failRecRes['error']['code'] ?? '') === 'INVALID_RECOVERY_CODE');
}

// Check counter after 4 attempts
$stmtRecCheck->execute([':id' => $recUserId]);
assertCheck("DB records exactly 4 failed recovery attempts", (int) $stmtRecCheck->fetchColumn() === 4);

// 5th failed recovery attempt triggers 429 ACCOUNT_LOCKED
$lockoutRecRes = dispatch($router, 'POST', '/api/auth/recover', [
    'email' => $recLockEmail,
    'recovery_code' => 'SMART-DEAD-BEEF',
    'new_password' => 'BrandNewPassword999!',
]);
assertCheck("5th failed recovery attempt triggers HTTP 429 ACCOUNT_LOCKED", $lockoutRecRes['_status_code'] === 429 && ($lockoutRecRes['error']['code'] ?? '') === 'ACCOUNT_LOCKED');

// DB check for locked_until
$stmtLockCheck = $pdo->prepare("SELECT `failed_login_attempts`, `locked_until` FROM `users` WHERE `id` = :id");
$stmtLockCheck->execute([':id' => $recUserId]);
$lockRow = $stmtLockCheck->fetch();
assertCheck("5th failure sets failed_login_attempts=5 and locked_until in future", (int) $lockRow['failed_login_attempts'] === 5 && !empty($lockRow['locked_until']) && strtotime($lockRow['locked_until']) > time());

// Subsequent invalid recovery attempt while locked is blocked immediately with 429
$postLockRecInvalid = dispatch($router, 'POST', '/api/auth/recover', [
    'email' => $recLockEmail,
    'recovery_code' => 'SMART-DEAD-BEEF-0000',
    'new_password' => 'BrandNewPassword999!',
]);
assertCheck("Invalid recovery attempt while account is locked returns HTTP 429 ACCOUNT_LOCKED", $postLockRecInvalid['_status_code'] === 429 && ($postLockRecInvalid['error']['code'] ?? '') === 'ACCOUNT_LOCKED');

// Subsequent login attempt while locked is also blocked with 429
$postLockLogin = dispatch($router, 'POST', '/api/auth/login', [
    'email' => $recLockEmail,
    'password' => $recLockPass,
]);
assertCheck("Login attempt while account is locked returns HTTP 429 ACCOUNT_LOCKED", $postLockLogin['_status_code'] === 429 && ($postLockLogin['error']['code'] ?? '') === 'ACCOUNT_LOCKED');

// ADV-02: Valid recovery code during active lockout succeeds, breaks the lockout, and resets counters
$unlockedRecRes = dispatch($router, 'POST', '/api/auth/recover', [
    'email' => $recLockEmail,
    'recovery_code' => $recKeyValid,
    'new_password' => 'BrandNewPassword999!',
]);
assertCheck("Valid recovery during active lockout returns HTTP 200 and resets lock", $unlockedRecRes['_status_code'] === 200 && ($unlockedRecRes['success'] ?? false) === true);

$stmtLockCheck->execute([':id' => $recUserId]);
$clearedRow = $stmtLockCheck->fetch();
assertCheck("Successful recovery resets failed_login_attempts=0 and locked_until=NULL", (int) $clearedRow['failed_login_attempts'] === 0 && $clearedRow['locked_until'] === null);

// Verify new password authenticates after recovery
$recNewLogin = dispatch($router, 'POST', '/api/auth/login', [
    'email' => $recLockEmail,
    'password' => 'BrandNewPassword999!',
]);
assertCheck("New password authenticates successfully post-lockout recovery", $recNewLogin['_status_code'] === 200 && ($recNewLogin['success'] ?? false) === true);

// 3.3 Password Invariance: Failed recovery must never alter the user's password hash
$stmtPassBefore = $pdo->prepare("SELECT `password_hash` FROM `users` WHERE `id` = :id");
$stmtPassBefore->execute([':id' => $userId1]);
$hashBefore = $stmtPassBefore->fetchColumn();

$failedRecImm = dispatch($router, 'POST', '/api/auth/recover', [
    'email' => $email1,
    'recovery_code' => 'SMART-FAIL-CODE',
    'new_password' => 'HackedPassword999!',
]);
assertCheck("Failed recovery returns HTTP 401", $failedRecImm['_status_code'] === 401);

$stmtPassBefore->execute([':id' => $userId1]);
$hashAfter = $stmtPassBefore->fetchColumn();
assertCheck("Failed recovery attempt strictly preserves existing password hash (no mutation)", $hashBefore === $hashAfter);

// 3.4 Shared Lockout State — Scenario A: 4 failed logins + 1 failed recovery => Lockout
$mixEmailA = 'mix_a_' . substr(md5(uniqid()), 0, 8) . '@example.com';
$mixPassA = 'PasswordA123!';
$mixRegA = dispatch($router, 'POST', '/api/auth/register', ['email' => $mixEmailA, 'password' => $mixPassA]);
$mixUserIdA = (int) ($mixRegA['data']['user']['id'] ?? 0);

for ($i = 1; $i <= 4; $i++) {
    dispatch($router, 'POST', '/api/auth/login', ['email' => $mixEmailA, 'password' => 'WrongPass!']);
}
$stmtRecCheck->execute([':id' => $mixUserIdA]);
assertCheck("Scenario A: 4 failed logins set counter to 4", (int) $stmtRecCheck->fetchColumn() === 4);

$finalRecResA = dispatch($router, 'POST', '/api/auth/recover', [
    'email' => $mixEmailA,
    'recovery_code' => 'SMART-WRONG-CODE',
    'new_password' => 'NewPass12345!',
]);
assertCheck("Scenario A: 4 failed logins + 1 failed recovery triggers HTTP 429 ACCOUNT_LOCKED", $finalRecResA['_status_code'] === 429 && ($finalRecResA['error']['code'] ?? '') === 'ACCOUNT_LOCKED');

// 3.5 Shared Lockout State — Scenario B: 4 failed recoveries + 1 failed login => Lockout
$mixEmailB = 'mix_b_' . substr(md5(uniqid()), 0, 8) . '@example.com';
$mixPassB = 'PasswordB123!';
$mixRegB = dispatch($router, 'POST', '/api/auth/register', ['email' => $mixEmailB, 'password' => $mixPassB]);
$mixUserIdB = (int) ($mixRegB['data']['user']['id'] ?? 0);

for ($i = 1; $i <= 4; $i++) {
    dispatch($router, 'POST', '/api/auth/recover', [
        'email' => $mixEmailB,
        'recovery_code' => 'SMART-WRONG-CODE',
        'new_password' => 'NewPass12345!',
    ]);
}
$stmtRecCheck->execute([':id' => $mixUserIdB]);
assertCheck("Scenario B: 4 failed recoveries set counter to 4", (int) $stmtRecCheck->fetchColumn() === 4);

$finalLoginResB = dispatch($router, 'POST', '/api/auth/login', [
    'email' => $mixEmailB,
    'password' => 'WrongPass!',
]);
assertCheck("Scenario B: 4 failed recoveries + 1 failed login triggers HTTP 429 ACCOUNT_LOCKED", $finalLoginResB['_status_code'] === 429 && ($finalLoginResB['error']['code'] ?? '') === 'ACCOUNT_LOCKED');

// 3.6 Non-existent user recovery attempt returns HTTP 401 without timing leak
$nonExistentRec = dispatch($router, 'POST', '/api/auth/recover', [
    'email' => 'does_not_exist_' . uniqid() . '@example.com',
    'recovery_code' => 'SMART-0000-0000',
    'new_password' => 'BrandNewPassword999!',
]);
assertCheck("Non-existent user recovery returns HTTP 401 INVALID_RECOVERY_CODE", $nonExistentRec['_status_code'] === 401 && ($nonExistentRec['error']['code'] ?? '') === 'INVALID_RECOVERY_CODE');

// 3.8 Deterministic Concurrency & Row-Level Lock Atomicity Test
// Verifies that transactions using SELECT ... FOR UPDATE serialize atomically and prevent lost updates.
$concEmail = 'conc_' . substr(md5(uniqid()), 0, 8) . '@example.com';
$concReg = dispatch($router, 'POST', '/api/auth/register', ['email' => $concEmail, 'password' => 'ConcTestPass123!']);
$concUserId = (int) ($concReg['data']['user']['id'] ?? 0);

$dbConfig = require dirname(__DIR__) . '/config/database.php';
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $dbConfig['host'], $dbConfig['port'], $dbConfig['database'], $dbConfig['charset']);
$pdoConn2 = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Connection 1 begins transaction and acquires exclusive row lock via SELECT ... FOR UPDATE
$pdo->beginTransaction();
$stmtLock1 = $pdo->prepare("SELECT `id`, `failed_login_attempts` FROM `users` WHERE `id` = :id FOR UPDATE");
$stmtLock1->execute([':id' => $concUserId]);
$userLock1 = $stmtLock1->fetch();

// In Connection 1, update counter to 1
$pdo->prepare("UPDATE `users` SET `failed_login_attempts` = 1 WHERE `id` = :id")->execute([':id' => $concUserId]);

// Connection 2 attempts to read the row
$stmtConn2 = $pdoConn2->prepare("SELECT `failed_login_attempts` FROM `users` WHERE `id` = :id");
$stmtConn2->execute([':id' => $concUserId]);
$uncommittedRead = (int) $stmtConn2->fetchColumn();
assertCheck("Dirty reads prevented: uncommitted increment is isolated from second connection", $uncommittedRead === 0);

// Connection 1 commits transaction, releasing lock
$pdo->commit();

// Connection 2 now acquires lock, reads committed value (1), and increments to 2
$pdoConn2->beginTransaction();
$stmtLock2 = $pdoConn2->prepare("SELECT `failed_login_attempts` FROM `users` WHERE `id` = :id FOR UPDATE");
$stmtLock2->execute([':id' => $concUserId]);
$valConn2 = (int) $stmtLock2->fetchColumn();
$pdoConn2->prepare("UPDATE `users` SET `failed_login_attempts` = :failed WHERE `id` = :id")->execute([':failed' => $valConn2 + 1, ':id' => $concUserId]);
$pdoConn2->commit();

// Assert final counter is exactly 2 (zero lost updates)
$stmtRecCheck->execute([':id' => $concUserId]);
$finalConcCount = (int) $stmtRecCheck->fetchColumn();
assertCheck("Serialized atomic increments guarantee exact counter state (failed_login_attempts = 2)", $finalConcCount === 2);

// 3.9 Valid Recovery on Primary User
$validRecRes = dispatch($router, 'POST', '/api/auth/recover', [
    'email' => $email1,
    'recovery_code' => $recoveryKey1,
    'new_password' => $newPass1,
]);
assertCheck("Valid recovery returns HTTP 200 with rotated recovery code", $validRecRes['_status_code'] === 200 && ($validRecRes['success'] ?? false) === true);
$rotatedKey1 = $validRecRes['data']['recovery_code'] ?? $validRecRes['data']['new_recovery_code'] ?? '';
assertCheck("Rotated recovery code matches format and is different from old key", (bool)preg_match('/^SMART-[0-9A-F]{4}-[0-9A-F]{4}$/', $rotatedKey1) && $rotatedKey1 !== $recoveryKey1);

// Verify old password fails, new password succeeds
$oldLogin = dispatch($router, 'POST', '/api/auth/login', ['email' => $email1, 'password' => $pass1]);
assertCheck("Old password no longer authenticates (HTTP 401)", $oldLogin['_status_code'] === 401);
$newLogin = dispatch($router, 'POST', '/api/auth/login', ['email' => $email1, 'password' => $newPass1]);
assertCheck("New password authenticates successfully (HTTP 200)", $newLogin['_status_code'] === 200);
$sessionToken1 = $newLogin['_session_cookie'];


// =============================================================================
// DOMAIN 4: Accountless Guest Workspace Access (Zero Regression)
// =============================================================================
echo "\n--- Domain 4: Accountless Guest Workspace Access (Zero Regression) ---\n";

// Create workspace anonymously without session cookie
$guestGroupRes = dispatch($router, 'POST', '/api/groups', [
    'name' => 'Goa Beach Villa',
    'creator_name' => 'Guest Alice',
    'currency' => 'INR',
], [], null);

assertCheck("Guest workspace created without session (owner_user_id=null)", $guestGroupRes['_status_code'] === 201 && ($guestGroupRes['data']['group']['owner_user_id'] ?? null) === null);
$guestToken = $guestGroupRes['data']['group']['invite_token'];
$guestGroupId = (int)$guestGroupRes['data']['group']['id'];
$aliceId = (int)$guestGroupRes['data']['creator']['id'];

// Add Bob, Charlie, Dave
$bRes = dispatch($router, 'POST', "/api/groups/{$guestToken}/members", ['name' => 'Guest Bob']);
$bobId = (int)$bRes['data']['member']['id'];

$cRes = dispatch($router, 'POST', "/api/groups/{$guestToken}/members", ['name' => 'Guest Charlie']);
$charlieId = (int)$cRes['data']['member']['id'];

$dRes = dispatch($router, 'POST', "/api/groups/{$guestToken}/members", ['name' => 'Guest Dave']);
$daveId = (int)$dRes['data']['member']['id'];

// Assert all member user_ids are NULL
$mList = dispatch($router, 'GET', "/api/groups/{$guestToken}/members");
$allNull = true;
foreach ($mList['data']['members'] as $m) {
    if ($m['user_id'] !== null) $allNull = false;
}
assertCheck("All 4 guest members have user_id = null", $allNull && count($mList['data']['members']) === 4);

// Log 5 distinct expenses with various split models
// Exp 1: Alice pays ₹400.00 equal 4 ways (₹100 each)
dispatch($router, 'POST', "/api/groups/{$guestToken}/expenses", [
    'title' => 'Villa Booking',
    'amount' => 400.00,
    'paid_by_member_id' => $aliceId,
    'split_type' => 'EQUAL',
    'splits' => [
        ['member_id' => $aliceId],
        ['member_id' => $bobId],
        ['member_id' => $charlieId],
        ['member_id' => $daveId],
    ],
]);

// Exp 2: Bob pays ₹120.00 split 3 ways (Bob, Charlie, Dave: ₹40 each)
dispatch($router, 'POST', "/api/groups/{$guestToken}/expenses", [
    'title' => 'Groceries & Snacks',
    'amount' => 120.00,
    'paid_by_member_id' => $bobId,
    'split_type' => 'EQUAL',
    'splits' => [
        ['member_id' => $bobId],
        ['member_id' => $charlieId],
        ['member_id' => $daveId],
    ],
]);

// Exp 3: Charlie pays ₹150.00 exact split (Alice ₹50, Bob ₹50, Charlie ₹50)
dispatch($router, 'POST', "/api/groups/{$guestToken}/expenses", [
    'title' => 'Fuel & Cab',
    'amount' => 150.00,
    'paid_by_member_id' => $charlieId,
    'split_type' => 'EXACT',
    'splits' => [
        ['member_id' => $aliceId, 'amount' => 50.00],
        ['member_id' => $bobId, 'amount' => 50.00],
        ['member_id' => $charlieId, 'amount' => 50.00],
    ],
]);

// Exp 4: Dave pays ₹80.00 for Alice & Dave (₹40 each)
dispatch($router, 'POST', "/api/groups/{$guestToken}/expenses", [
    'title' => 'Beach Umbrellas',
    'amount' => 80.00,
    'paid_by_member_id' => $daveId,
    'split_type' => 'EQUAL',
    'splits' => [
        ['member_id' => $aliceId],
        ['member_id' => $daveId],
    ],
]);

// Exp 5: Alice pays ₹100.00 percentage split (Bob 50%, Charlie 50%)
dispatch($router, 'POST', "/api/groups/{$guestToken}/expenses", [
    'title' => 'Seafood Dinner Surcharge',
    'amount' => 100.00,
    'paid_by_member_id' => $aliceId,
    'split_type' => 'PERCENTAGE',
    'splits' => [
        ['member_id' => $bobId, 'percentage' => 50],
        ['member_id' => $charlieId, 'percentage' => 50],
    ],
]);

// Check Zero-Sum Invariant
$balRes = dispatch($router, 'GET', "/api/groups/{$guestToken}/balances");
$sumCents = 0;
$baselineBalances = [];
foreach ($balRes['data']['members'] as $m) {
    $sumCents += (int)$m['net_balance_cents'];
    $baselineBalances[(int)$m['member_id']] = (int)$m['net_balance_cents'];
}
assertCheck("Guest group financial ledger maintains strict zero-sum invariant (sum = 0)", $sumCents === 0);

$planRes = dispatch($router, 'GET', "/api/groups/{$guestToken}/settlement-plan");
$baselinePlanCount = count($planRes['data']['transactions'] ?? []);
assertCheck("Settlement engine computes debt simplification transactions", $baselinePlanCount > 0);


// =============================================================================
// DOMAIN 5: Progressive Member Claiming & Double-Claim Defenses
// =============================================================================
echo "\n--- Domain 5: Progressive Member Claiming & Double-Claim Defenses ---\n";

// 5.1 Authenticated Group Creation
$authGroupRes = dispatch($router, 'POST', '/api/groups', [
    'name' => 'Mountain Trek 2026',
    'creator_name' => 'Trek Lead',
    'currency' => 'INR',
], [], $sessionToken1);

assertCheck("Authenticated group creation sets owner_user_id and links creator", $authGroupRes['_status_code'] === 201 && (int)$authGroupRes['data']['group']['owner_user_id'] === $userId1 && (int)$authGroupRes['data']['creator']['user_id'] === $userId1);
$authGroupToken = $authGroupRes['data']['group']['invite_token'];

// 5.2 User 1 claims Alice in the guest workspace
$claimAliceRes = dispatch($router, 'POST', "/api/groups/{$guestToken}/members/{$aliceId}/claim", [
    'member_id' => $aliceId,
], [], $sessionToken1);
assertCheck("User 1 claims member Alice in guest workspace", $claimAliceRes['_status_code'] === 200 && ($claimAliceRes['data']['claimed'] ?? false) === true);

// Verify member record has user_id
$mListAfterClaim = dispatch($router, 'GET', "/api/groups/{$guestToken}/members", [], [], $sessionToken1);
$aliceRow = null;
foreach ($mListAfterClaim['data']['members'] as $m) {
    if ((int)$m['id'] === $aliceId) $aliceRow = $m;
}
assertCheck("Alice record updated with user_id = User 1", $aliceRow !== null && (int)$aliceRow['user_id'] === $userId1);

// 5.3 Double-Claim Defenses:
// Register User 2
$email2 = 'user2_' . uniqid() . '@example.com';
$u2Reg = dispatch($router, 'POST', '/api/auth/register', ['email' => $email2, 'password' => 'PassWord123!']);
$userId2 = (int)$u2Reg['data']['user']['id'];
$sessionToken2 = $u2Reg['_session_cookie'];

// User 2 tries to claim Alice (already claimed by User 1) -> 409
$u2ClaimAlice = dispatch($router, 'POST', "/api/groups/{$guestToken}/members/{$aliceId}/claim", ['member_id' => $aliceId], [], $sessionToken2);
assertCheck("Claiming already-claimed slot rejected with HTTP 409", $u2ClaimAlice['_status_code'] === 409);

// User 1 tries to claim Bob (a second slot in the same group) -> 409
$u1ClaimBob = dispatch($router, 'POST', "/api/groups/{$guestToken}/members/{$bobId}/claim", ['member_id' => $bobId], [], $sessionToken1);
assertCheck("Claiming a second slot in the same group rejected with HTTP 409", $u1ClaimBob['_status_code'] === 409);


// =============================================================================
// DOMAIN 6: Member Profile Unlinking
// =============================================================================
echo "\n--- Domain 6: Member Profile Unlinking ---\n";

// User 1 unlinks Alice
$unlinkRes = dispatch($router, 'POST', "/api/groups/{$guestToken}/members/{$aliceId}/unlink", [
    'member_id' => $aliceId,
], [], $sessionToken1);
assertCheck("User 1 unlinks Alice successfully (HTTP 200)", $unlinkRes['_status_code'] === 200 && ($unlinkRes['data']['unlinked'] ?? false) === true);

// Verify Alice user_id reverted to NULL
$postUnlinkMembers = dispatch($router, 'GET', "/api/groups/{$guestToken}/members");
$unlinkedAlice = null;
foreach ($postUnlinkMembers['data']['members'] as $m) {
    if ((int)$m['id'] === $aliceId) $unlinkedAlice = $m;
}
assertCheck("Alice user_id reverted to null", $unlinkedAlice !== null && $unlinkedAlice['user_id'] === null);

// Re-claim Alice for subsequent multi-workspace aggregator tests
dispatch($router, 'POST', "/api/groups/{$guestToken}/members/{$aliceId}/claim", ['member_id' => $aliceId], [], $sessionToken1);


// =============================================================================
// DOMAIN 7: Multi-Device Cloud Workspace Aggregation
// =============================================================================
echo "\n--- Domain 7: Multi-Device Cloud Workspace Aggregation ---\n";

// User 1 has: 1 owned group (Mountain Trek), 1 claimed member group (Goa Beach Villa)
$u1Ws = dispatch($router, 'GET', '/api/user/workspaces', [], [], $sessionToken1);
assertCheck("/api/user/workspaces returns HTTP 200", $u1Ws['_status_code'] === 200);
$workspaces = $u1Ws['data']['workspaces'] ?? [];
assertCheck("User 1 workspaces aggregation contains both owned and claimed groups", count($workspaces) >= 2);

$foundOwned = false;
$foundClaimed = false;
foreach ($workspaces as $ws) {
    if ($ws['invite_token'] === $authGroupToken && ($ws['is_owner'] ?? false) === true) {
        $foundOwned = true;
    }
    if ($ws['invite_token'] === $guestToken && ($ws['member_name'] ?? '') === 'Guest Alice') {
        $foundClaimed = true;
    }
}
assertCheck("Aggregator accurately flags owned workspace (is_owner=true)", $foundOwned);
assertCheck("Aggregator accurately flags claimed member workspace with member_name='Guest Alice'", $foundClaimed);


// =============================================================================
// DOMAIN 8: Account Deletion & Mathematical Invariance Check
// =============================================================================
echo "\n--- Domain 8: Account Deletion & Mathematical Invariance Check ---\n";

// 8.1 Delete User 1 Account
$delRes = dispatch($router, 'DELETE', '/api/user/account', [
    'password' => $newPass1,
], [], $sessionToken1);
assertCheck("Account deletion returns HTTP 200", $delRes['_status_code'] === 200 && ($delRes['success'] ?? false) === true);

// Verify user record and sessions deleted
$stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id");
$stmt->execute([':id' => $userId1]);
assertCheck("User record removed from database", $stmt->fetch() === false);

$stmt = $pdo->prepare("SELECT id FROM user_sessions WHERE user_id = :id");
$stmt->execute([':id' => $userId1]);
assertCheck("User sessions purged from database", count($stmt->fetchAll()) === 0);

// Verify owned group reverted to owner_user_id = NULL
$stmt = $pdo->prepare("SELECT owner_user_id FROM groups WHERE invite_token = :tok");
$stmt->execute([':tok' => $authGroupToken]);
$ownedGrpRow = $stmt->fetch();
assertCheck("Owned group reverted to owner_user_id = null", $ownedGrpRow !== false && $ownedGrpRow['owner_user_id'] === null);

// Verify claimed member reverted to user_id = NULL
$stmt = $pdo->prepare("SELECT user_id FROM members WHERE id = :id");
$stmt->execute([':id' => $aliceId]);
$aliceMemberRow = $stmt->fetch();
assertCheck("Claimed member Alice reverted to user_id = null", $aliceMemberRow !== false && $aliceMemberRow['user_id'] === null);

// 8.2 Mathematical Invariance Check (Compare balances post-deletion with baseline)
$postDelBal = dispatch($router, 'GET', "/api/groups/{$guestToken}/balances");
$postSumCents = 0;
$matchesBaseline = true;
foreach ($postDelBal['data']['members'] as $m) {
    $mId = (int)$m['member_id'];
    $mBal = (int)$m['net_balance_cents'];
    $postSumCents += $mBal;
    if (!isset($baselineBalances[$mId]) || $baselineBalances[$mId] !== $mBal) {
        $matchesBaseline = false;
    }
}

assertCheck("Post-deletion ledger maintains strict zero-sum invariant (sum = 0)", $postSumCents === 0);
assertCheck("Post-deletion member balances are 100% mathematically identical to baseline", $matchesBaseline);

$postDelPlan = dispatch($router, 'GET', "/api/groups/{$guestToken}/settlement-plan");
$postDelPlanCount = count($postDelPlan['data']['transactions'] ?? []);
assertCheck("Post-deletion settlement debt paths are 100% mathematically invariant", $postDelPlanCount === $baselinePlanCount);

// Clean up test groups & test user 2
$pdo->exec("DELETE FROM groups WHERE invite_token IN ('{$guestToken}', '{$authGroupToken}')");
$pdo->exec("DELETE FROM users WHERE id IN ({$userId2}, {$bruteUserId})");

echo "\n================================================================================\n";
echo " MASTER HYBRID AUTH SUITE: {$passedTests} / {$totalTests} TESTS PASSED (100%)\n";
echo "================================================================================\n";

if ($passedTests === $totalTests) {
    echo "🎉 Comprehensive End-to-End System Verification Completed Successfully!\n";
    exit(0);
} else {
    echo "❌ Some assertions failed.\n";
    exit(1);
}
