<?php

declare(strict_types=1);

/**
 * Smart Split V2 — Final Production Readiness: Independent Alias Route Dispatch Suite
 *
 * Closes the documented SEC-15 gap by explicitly dispatching and asserting on the 6 alias routes:
 * 1. POST /api/auth/recover-password
 * 2. POST /api/groups/{token}/members/{memberId}/claim
 * 3. POST /api/groups/{token}/members/{memberId}/unlink
 * 4. GET  /api/groups/{token}/categories
 * 5. GET  /api/groups/{token}/expenses/{id}/receipts/{receiptId}/view
 * 6. GET  /api/groups/{token}/expenses/{id}/receipts/{receiptId}
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
use App\Controllers\CategoryController;
use App\Controllers\ReceiptController;
use App\Repositories\GroupRepository;
use App\Repositories\MemberRepository;
use App\Repositories\ExpenseRepository;
use App\Repositories\ReceiptRepository;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

echo "\n================================================================================\n";
echo " FINAL PRODUCTION GATE: INDEPENDENT ALIAS ROUTE DISPATCH SUITE\n";
echo "================================================================================\n\n";

$passed = 0;
$total = 0;
$failures = [];

function assertAlias(bool $condition, string $testId, string $description, ?string $details = null): void {
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
        $failures[] = [
            'testId' => $testId,
            'description' => $description,
            'details' => $details,
        ];
    }
}

// Router Setup
$router = new Router();
$router->use(new SecurityHeadersMiddleware());
$router->use(new AuthSessionMiddleware($pdo));

// Register routes exactly matching public/index.php
$router->post('/api/auth/recover-password', [AuthController::class, 'recoverPassword']);
$router->post('/api/groups/{token}/members/{memberId}/claim', [AuthController::class, 'claimMember']);
$router->post('/api/groups/{token}/members/{memberId}/unlink', [AuthController::class, 'unlinkMember']);
$router->get('/api/groups/{token}/categories', [CategoryController::class, 'index']);
$router->get('/api/groups/{token}/expenses/{id}/receipts/{receiptId}/view', [ReceiptController::class, 'download']);
$router->get('/api/groups/{token}/expenses/{id}/receipts/{receiptId}', [ReceiptController::class, 'download']);

// -----------------------------------------------------------------------------
// FIXTURE SETUP
// -----------------------------------------------------------------------------
$groupRepo = new GroupRepository($pdo);
$memberRepo = new MemberRepository($pdo);
$expenseRepo = new ExpenseRepository($pdo);
$receiptRepo = new ReceiptRepository($pdo);

$testGroup = $groupRepo->create('Alias Route Test Group', 'INR');
$token = (string) $testGroup['invite_token'];
$groupId = (int) $testGroup['id'];

// Create members
$alice = $memberRepo->create($groupId, 'Alice');
$memberAliceId = (int) $alice['id'];
$bob = $memberRepo->create($groupId, 'Bob');
$memberBobId = (int) $bob['id'];

// Create test user for password recovery and claiming
$testEmail = 'alias_test_' . bin2hex(random_bytes(4)) . '@example.com';
$testPassword = 'InitialPassword123!';
$rawRecoveryCode = 'SMART-AAAA-BBBB';
$recoveryCodeHash = password_hash($rawRecoveryCode, PASSWORD_BCRYPT, ['cost' => 10]);
$passwordHash = password_hash($testPassword, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = $pdo->prepare("
    INSERT INTO `users` (`email`, `password_hash`, `display_name`, `recovery_code_hash`, `avatar_emoji`, `avatar_color`, `is_active`)
    VALUES (:email, :password_hash, 'Alias User', :recovery_code_hash, '👤', '#2563eb', 1)
");
$stmt->execute([
    ':email' => $testEmail,
    ':password_hash' => $passwordHash,
    ':recovery_code_hash' => $recoveryCodeHash,
]);
$userId = (int) $pdo->lastInsertId();

// Create session token for user
$sessionPlainToken = bin2hex(random_bytes(32));
$sessionHash = hash('sha256', $sessionPlainToken);
$sessStmt = $pdo->prepare("
    INSERT INTO `user_sessions` (`user_id`, `session_token_hash`, `ip_address`, `user_agent`, `expires_at`, `created_at`, `last_active_at`)
    VALUES (:user_id, :token_hash, '127.0.0.1', 'AliasTestRunner', DATE_ADD(NOW(), INTERVAL 30 DAY), NOW(), NOW())
");
$sessStmt->execute([':user_id' => $userId, ':token_hash' => $sessionHash]);

// Create expense and receipt attachment
$expenseId = $expenseRepo->createExpense(
    $groupId,
    'Team Dinner',
    10000,
    'EQUAL',
    date('Y-m-d'),
    $memberAliceId,
    [['member_id' => $memberAliceId, 'amount_paid_cents' => 10000]],
    [
        ['member_id' => $memberAliceId, 'amount_owed_cents' => 5000, 'split_value' => null],
        ['member_id' => $memberBobId, 'amount_owed_cents' => 5000, 'split_value' => null],
    ]
);

// Create temporary receipt file in storage/receipts
$receiptFilename = 'receipt_' . $expenseId . '_' . time() . '_alias.png';
$receiptFullPath = dirname(__DIR__) . '/storage/receipts/' . $receiptFilename;
$png1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
file_put_contents($receiptFullPath, $png1x1);

$receiptId = $receiptRepo->create(
    $expenseId,
    'dinner_bill.png',
    'storage/receipts/' . $receiptFilename,
    strlen($png1x1),
    'image/png'
);

$standardApiHeaders = [
    'Content-Type' => 'application/json',
    'X-Requested-With' => 'fetch',
];

// -----------------------------------------------------------------------------
// TEST 1: POST /api/auth/recover-password
// -----------------------------------------------------------------------------
echo "--- Alias 1: POST /api/auth/recover-password ---\n";

$newPassword = 'NewSecretPassword456!';
$req1 = new Request(
    method: 'POST',
    path: '/api/auth/recover-password',
    queryParams: null,
    body: [
        'email' => $testEmail,
        'recovery_code' => $rawRecoveryCode,
        'new_password' => $newPassword,
    ],
    headers: $standardApiHeaders
);
ob_start();
$router->dispatch($req1);
$out1 = ob_get_clean();
$json1 = json_decode($out1, true);

assertAlias(
    isset($json1['success']) && $json1['success'] === true && isset($json1['data']['recovery_code']),
    'ALIAS.1.1',
    'POST /api/auth/recover-password successfully resets password and rotates emergency recovery key'
);

// -----------------------------------------------------------------------------
// TEST 2: POST /api/groups/{token}/members/{memberId}/claim
// -----------------------------------------------------------------------------
echo "\n--- Alias 2: POST /api/groups/{token}/members/{memberId}/claim ---\n";

// Issue fresh session for user (since recover-password in Test 1 correctly revoked previous sessions)
$sessionPlainToken = bin2hex(random_bytes(32));
$sessionHash = hash('sha256', $sessionPlainToken);
$sessStmt = $pdo->prepare("
    INSERT INTO `user_sessions` (`user_id`, `session_token_hash`, `ip_address`, `user_agent`, `expires_at`, `created_at`, `last_active_at`)
    VALUES (:user_id, :token_hash, '127.0.0.1', 'AliasTestRunner', DATE_ADD(NOW(), INTERVAL 30 DAY), NOW(), NOW())
");
$sessStmt->execute([':user_id' => $userId, ':token_hash' => $sessionHash]);
$_COOKIE['smartsplit_session'] = $sessionPlainToken; // Authenticated session

$req2 = new Request(
    method: 'POST',
    path: "/api/groups/{$token}/members/{$memberAliceId}/claim",
    queryParams: null,
    body: [],
    headers: $standardApiHeaders
);
ob_start();
$router->dispatch($req2);
$out2 = ob_get_clean();
$json2 = json_decode($out2, true);

assertAlias(
    isset($json2['success']) && $json2['success'] === true && ($json2['data']['claimed'] ?? false) === true,
    'ALIAS.2.1',
    'POST /api/groups/{token}/members/{memberId}/claim successfully claims member slot with path param'
);

// -----------------------------------------------------------------------------
// TEST 3: POST /api/groups/{token}/members/{memberId}/unlink
// -----------------------------------------------------------------------------
echo "\n--- Alias 3: POST /api/groups/{token}/members/{memberId}/unlink ---\n";

$req3 = new Request(
    method: 'POST',
    path: "/api/groups/{$token}/members/{$memberAliceId}/unlink",
    queryParams: null,
    body: [],
    headers: $standardApiHeaders
);
ob_start();
$router->dispatch($req3);
$out3 = ob_get_clean();
$json3 = json_decode($out3, true);

assertAlias(
    isset($json3['success']) && $json3['success'] === true && ($json3['data']['unlinked'] ?? false) === true,
    'ALIAS.3.1',
    'POST /api/groups/{token}/members/{memberId}/unlink successfully unlinks member slot with path param'
);

// -----------------------------------------------------------------------------
// TEST 4: GET /api/groups/{token}/categories
// -----------------------------------------------------------------------------
echo "\n--- Alias 4: GET /api/groups/{token}/categories ---\n";

$req4 = new Request(
    method: 'GET',
    path: "/api/groups/{$token}/categories"
);
ob_start();
$router->dispatch($req4);
$out4 = ob_get_clean();
$json4 = json_decode($out4, true);

assertAlias(
    isset($json4['success']) && $json4['success'] === true && isset($json4['data']['categories']) && is_array($json4['data']['categories']),
    'ALIAS.4.1',
    'GET /api/groups/{token}/categories successfully returns categories list scoped by group'
);

// -----------------------------------------------------------------------------
// TEST 5: GET /api/groups/{token}/expenses/{id}/receipts/{receiptId}/view
// -----------------------------------------------------------------------------
echo "\n--- Alias 5: GET /api/groups/{token}/expenses/{id}/receipts/{receiptId}/view ---\n";

$req5 = new Request(
    method: 'GET',
    path: "/api/groups/{$token}/expenses/{$expenseId}/receipts/{$receiptId}/view"
);
ob_start();
ob_start();
$router->dispatch($req5);
$out5 = ob_get_clean();

assertAlias(
    $out5 === $png1x1 && Response::$lastStatusCode === 200,
    'ALIAS.5.1',
    'GET /api/groups/{token}/expenses/{id}/receipts/{receiptId}/view streams valid binary receipt content'
);

// -----------------------------------------------------------------------------
// TEST 6: GET /api/groups/{token}/expenses/{id}/receipts/{receiptId}
// -----------------------------------------------------------------------------
echo "\n--- Alias 6: GET /api/groups/{token}/expenses/{id}/receipts/{receiptId} ---\n";

$req6 = new Request(
    method: 'GET',
    path: "/api/groups/{$token}/expenses/{$expenseId}/receipts/{$receiptId}"
);
ob_start();
ob_start();
$router->dispatch($req6);
$out6 = ob_get_clean();

assertAlias(
    $out6 === $png1x1 && Response::$lastStatusCode === 200,
    'ALIAS.6.1',
    'GET /api/groups/{token}/expenses/{id}/receipts/{receiptId} bare ID route streams valid binary receipt content'
);

// -----------------------------------------------------------------------------
// CLEANUP TEST FIXTURES
// -----------------------------------------------------------------------------
if (file_exists($receiptFullPath)) {
    unlink($receiptFullPath);
}
try {
    $pdo->exec("DELETE FROM `receipt_attachments` WHERE `expense_id` = {$expenseId}");
    $pdo->exec("DELETE FROM `expense_splits` WHERE `expense_id` = {$expenseId}");
    $pdo->exec("DELETE FROM `expenses` WHERE `id` = {$expenseId}");
    $pdo->exec("DELETE FROM `members` WHERE `group_id` = {$groupId}");
    $pdo->exec("DELETE FROM `groups` WHERE `id` = {$groupId}");
    $pdo->exec("DELETE FROM `user_sessions` WHERE `user_id` = {$userId}");
    $pdo->exec("DELETE FROM `users` WHERE `id` = {$userId}");
} catch (\Throwable $e) {}

// -----------------------------------------------------------------------------
// SUMMARY & VERDICT
// -----------------------------------------------------------------------------
echo "\n================================================================================\n";
echo " INDEPENDENT ALIAS ROUTE DISPATCH SUMMARY\n";
echo "================================================================================\n";
echo " Total Assertions  : {$total}\n";
echo " Passed Assertions : {$passed}\n";
echo " Failed Assertions : " . count($failures) . "\n";
echo " Pass Rate         : " . sprintf('%.2f%%', ($passed / max(1, $total)) * 100) . "\n";
echo "================================================================================\n\n";

if (!empty($failures)) {
    echo "FAILED ASSERTIONS:\n";
    foreach ($failures as $f) {
        echo " - [{$f['testId']}] {$f['description']}\n";
    }
    exit(1);
}

echo ">>> VERDICT: ALL 6 ALIAS ROUTES INDEPENDENTLY VERIFIED: 100% PASS <<<\n";
exit(0);
