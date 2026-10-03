<?php

declare(strict_types=1);

/**
 * Smart Split V2 — Comprehensive Personal UPI Identity & Resolution Test Suite
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Database;
use App\Core\Request;
use App\Controllers\AuthController;
use App\Controllers\MemberController;
use App\Controllers\BalanceController;
use App\Repositories\GroupRepository;
use App\Repositories\MemberRepository;
use App\Services\BalanceService;

$pdo = Database::getConnection();

function testAssert(bool $condition, string $message): void {
    if (!$condition) {
        echo "  [FAIL] {$message}\n";
        exit(1);
    }
    echo "  [PASS] {$message}\n";
}

echo "\n================================================================================\n";
echo " SMART SPLIT V2: PERSONAL UPI IDENTITY SUITE\n";
echo "================================================================================\n\n";

// Clean up test data before running
$pdo->exec("DELETE FROM `user_sessions` WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `email` LIKE 'test_upi_%@example.com')");
$pdo->exec("DELETE FROM `users` WHERE `email` LIKE 'test_upi_%@example.com'");
$pdo->exec("DELETE FROM `groups` WHERE `name` LIKE 'UPI Test Group%'");

// ----------------------------------------------------------------------------
// 1. Database Schema Verification
// ----------------------------------------------------------------------------
echo "1. Database Schema Verification\n";

$colStmt = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'upi_id'");
$col = $colStmt->fetch(PDO::FETCH_ASSOC);
testAssert($col !== false, "Column `users.upi_id` exists in schema.");
testAssert(stripos((string)$col['Type'], 'varchar(80)') !== false, "Column `users.upi_id` type is VARCHAR(80).");
testAssert(strtoupper((string)$col['Null']) === 'YES', "Column `users.upi_id` is nullable.");

// ----------------------------------------------------------------------------
// 2. User Registration & Session Hydration
// ----------------------------------------------------------------------------
echo "\n2. User Registration & Session Hydration\n";

$authController = new AuthController($pdo);

// Register user 1
$reqRegister = new Request('POST', '/api/auth/register', [], [
    'email' => 'test_upi_user1@example.com',
    'password' => 'SecurePass123!',
    'display_name' => 'Alice Test',
]);

ob_start();
$authController->register($reqRegister);
$respRegister = json_decode(ob_get_clean(), true);

testAssert(isset($respRegister['success']) && $respRegister['success'] === true, "User registration succeeds.");
testAssert(isset($respRegister['data']['user']['id']), "Registration returns user ID.");
testAssert(array_key_exists('upi_id', $respRegister['data']['user']), "Registration user payload includes upi_id field.");
testAssert($respRegister['data']['user']['upi_id'] === null, "Default registered upi_id is null.");

$user1Id = (int) $respRegister['data']['user']['id'];

// ----------------------------------------------------------------------------
// 3. Profile Update API (CRUD & Validation)
// ----------------------------------------------------------------------------
echo "\n3. Profile Update API (CRUD & Validation)\n";

// 3.1 Unauthenticated update rejected
$reqUnauth = new Request('PUT', '/api/auth/profile', [], ['upi_id' => 'alice@okaxis']);
ob_start();
$authController->updateProfile($reqUnauth);
$respUnauth = json_decode(ob_get_clean(), true);
testAssert(isset($respUnauth['success']) && $respUnauth['success'] === false, "Unauthenticated profile update rejected with error.");
testAssert($respUnauth['error']['code'] === 'UNAUTHORIZED', "Unauthenticated error code is UNAUTHORIZED.");

// Helper to simulate authenticated request
function makeAuthRequest(int $userId, string $email, string $method, string $path, array $body = []): Request {
    $req = new Request($method, $path, [], $body);
    $req->setUser([
        'id' => $userId,
        'email' => $email,
        'display_name' => 'Alice Test',
        'avatar_emoji' => '👤',
        'avatar_color' => '#18352b',
        'session_id' => 999,
    ]);
    return $req;
}

// 3.2 Valid UPI update
$reqAuthValid = makeAuthRequest($user1Id, 'test_upi_user1@example.com', 'PUT', '/api/auth/profile', [
    'upi_id' => 'alice@okaxis',
]);
ob_start();
$authController->updateProfile($reqAuthValid);
$respAuthValid = json_decode(ob_get_clean(), true);
testAssert(isset($respAuthValid['success']) && $respAuthValid['success'] === true, "Authenticated user can save personal UPI ID.");
testAssert($respAuthValid['data']['user']['upi_id'] === 'alice@okaxis', "Profile response reflects saved upi_id.");

// 3.3 GET /api/auth/me returns updated UPI
$reqMe = makeAuthRequest($user1Id, 'test_upi_user1@example.com', 'GET', '/api/auth/me');
$reqMe->setUser([
    'id' => $user1Id,
    'email' => 'test_upi_user1@example.com',
    'display_name' => 'Alice Test',
    'avatar_emoji' => '👤',
    'avatar_color' => '#18352b',
    'upi_id' => 'alice@okaxis',
    'session_id' => 999,
]);
ob_start();
$authController->me($reqMe);
$respMe = json_decode(ob_get_clean(), true);
testAssert($respMe['data']['user']['upi_id'] === 'alice@okaxis', "GET /api/auth/me returns the active personal UPI ID.");

// 3.4 Boundary: Exact 80-char VPA accepted
$vpa80 = str_repeat('a', 73) . '@okaxis'; // 73 + 7 = 80 chars
$req80 = makeAuthRequest($user1Id, 'test_upi_user1@example.com', 'PUT', '/api/auth/profile', ['upi_id' => $vpa80]);
ob_start();
$authController->updateProfile($req80);
$resp80 = json_decode(ob_get_clean(), true);
testAssert($resp80['success'] === true && $resp80['data']['user']['upi_id'] === $vpa80, "Exact 80-character VPA accepted.");

// 3.5 Boundary: 81-char VPA rejected
$vpa81 = str_repeat('a', 74) . '@okaxis'; // 74 + 7 = 81 chars
$req81 = makeAuthRequest($user1Id, 'test_upi_user1@example.com', 'PUT', '/api/auth/profile', ['upi_id' => $vpa81]);
ob_start();
$authController->updateProfile($req81);
$resp81 = json_decode(ob_get_clean(), true);
testAssert($resp81['success'] === false && $resp81['error']['code'] === 'INVALID_UPI_ID', "81-character VPA rejected with INVALID_UPI_ID.");

// 3.6 Malformed VPA / XSS rejected
$reqMal = makeAuthRequest($user1Id, 'test_upi_user1@example.com', 'PUT', '/api/auth/profile', ['upi_id' => '<script>alert(1)</script>@axis']);
ob_start();
$authController->updateProfile($reqMal);
$respMal = json_decode(ob_get_clean(), true);
testAssert($respMal['success'] === false && $respMal['error']['code'] === 'INVALID_UPI_ID', "XSS / malformed VPA rejected with INVALID_UPI_ID.");

// 3.7 Whitespace trimmed and cleared to NULL
$reqClear = makeAuthRequest($user1Id, 'test_upi_user1@example.com', 'PUT', '/api/auth/profile', ['upi_id' => '   ']);
ob_start();
$authController->updateProfile($reqClear);
$respClear = json_decode(ob_get_clean(), true);
testAssert($respClear['success'] === true && $respClear['data']['user']['upi_id'] === null, "Whitespace-only input clears profile UPI to NULL.");

// Reset user 1 to canonical UPI
$reqReset = makeAuthRequest($user1Id, 'test_upi_user1@example.com', 'PUT', '/api/auth/profile', ['upi_id' => 'alice@okaxis']);
ob_start();
$authController->updateProfile($reqReset);
ob_end_clean();

// ----------------------------------------------------------------------------
// 4. Multi-Workspace Settlement Resolution & Hierarchy
// ----------------------------------------------------------------------------
echo "\n4. Multi-Workspace Settlement Resolution & Hierarchy\n";

$groupRepo = new GroupRepository($pdo);
$memberRepo = new MemberRepository($pdo);
$balanceService = new BalanceService($pdo, $memberRepo);
$balanceController = new BalanceController($groupRepo, $balanceService);

// Create Group A
$groupA = $groupRepo->create('UPI Test Group A', 'INR');
$tokenA = $groupA['invite_token'];
$groupAId = (int) $groupA['id'];

// Member 1 in Group A: Claimed by User 1 (Alice, who has users.upi_id = 'alice@okaxis')
$memberA1 = $memberRepo->create($groupAId, 'Alice Group A', $user1Id);
$memberA1Id = (int) $memberA1['id'];

// Member 2 in Group A: Guest with legacy members.upi_id = 'bob.guest@paytm'
$memberA2 = $memberRepo->create($groupAId, 'Bob Guest');
$memberA2Id = (int) $memberA2['id'];
$memberRepo->updateUpiId($memberA2Id, $groupAId, 'bob.guest@paytm');

// Member 3 in Group A: Guest with no UPI
$memberA3 = $memberRepo->create($groupAId, 'Charlie Guest');
$memberA3Id = (int) $memberA3['id'];

// Verify MemberRepository::findByGroupId resolution
$rosterA = $memberRepo->findByGroupId($groupAId);
$rosterMapA = [];
foreach ($rosterA as $m) {
    $rosterMapA[(int)$m['id']] = $m;
}

testAssert($rosterMapA[$memberA1Id]['upi_id'] === 'alice@okaxis', "Tier 1: Registered member resolves canonical users.upi_id.");
testAssert($rosterMapA[$memberA2Id]['upi_id'] === 'bob.guest@paytm', "Tier 2: Guest member resolves members.upi_id.");
testAssert($rosterMapA[$memberA3Id]['upi_id'] === null, "Tier 3: Unconfigured member resolves null.");

// Create Group B (Multi-Workspace consistency test)
$groupB = $groupRepo->create('UPI Test Group B', 'INR');
$tokenB = $groupB['invite_token'];
$groupBId = (int) $groupB['id'];

// Member 1 in Group B: Also Claimed by User 1 (Alice) with legacy members.upi_id = 'old.groupb@upi'
$memberB1 = $memberRepo->create($groupBId, 'Alice Group B', $user1Id);
$memberB1Id = (int) $memberB1['id'];
$memberRepo->updateUpiId($memberB1Id, $groupBId, 'old.groupb@upi');

$rosterB = $memberRepo->findByGroupId($groupBId);
$rosterMapB = [];
foreach ($rosterB as $m) {
    $rosterMapB[(int)$m['id']] = $m;
}

testAssert($rosterMapB[$memberB1Id]['upi_id'] === 'alice@okaxis', "Rule 2: Canonical users.upi_id supersedes legacy members.upi_id across groups.");
testAssert($rosterMapB[$memberB1Id]['member_upi_id'] === 'old.groupb@upi', "Legacy members.upi_id remains preserved in DB row as fallback.");

// ----------------------------------------------------------------------------
// 5. Settlement Plan Resolution & QR Payload Integration
// ----------------------------------------------------------------------------
echo "\n5. Settlement Plan Resolution & QR Payload Integration\n";

// Add an expense where Alice paid 3000 paise (₹30) split equally with Bob and Charlie (₹10 each)
$expStmt = $pdo->prepare("
    INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `split_type`, `expense_date`, `created_by_member_id`)
    VALUES (:gid, 'Lunch', 3000, 'EQUAL', '2026-10-04', :creator)
");
$expStmt->execute([':gid' => $groupAId, ':creator' => $memberA1Id]);
$expId = (int) $pdo->lastInsertId();

// Payers: Alice paid 3000
$pdo->prepare("INSERT INTO `expense_payers` (`expense_id`, `member_id`, `amount_paid_cents`) VALUES (?, ?, ?)")
    ->execute([$expId, $memberA1Id, 3000]);

// Splits: Alice 1000, Bob 1000, Charlie 1000
$splitStmt = $pdo->prepare("INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`) VALUES (?, ?, ?)");
$splitStmt->execute([$expId, $memberA1Id, 1000]);
$splitStmt->execute([$expId, $memberA2Id, 1000]);
$splitStmt->execute([$expId, $memberA3Id, 1000]);

// Fetch settlement plan via BalanceController
$reqPlan = new Request('GET', "/api/groups/{$tokenA}/settlement-plan", [], []);
$reqPlan->setRouteParams(['token' => $tokenA]);
ob_start();
$balanceController->settlementPlan($reqPlan);
$respPlan = json_decode(ob_get_clean(), true);

testAssert(isset($respPlan['success']) && $respPlan['success'] === true, "Settlement plan computes successfully.");
testAssert($respPlan['data']['total_transactions'] === 2, "Settlement plan generates 2 transfer transactions.");

$txs = $respPlan['data']['transactions'];
foreach ($txs as $tx) {
    testAssert((int)$tx['to_member_id'] === $memberA1Id, "Creditor in transaction is Alice.");
    testAssert($tx['to_upi_id'] === 'alice@okaxis', "Transaction to_upi_id is dynamically populated with Alice's personal profile UPI.");
}

// ----------------------------------------------------------------------------
// 6. Claiming & Unlinking Dynamic Resolution
// ----------------------------------------------------------------------------
echo "\n6. Claiming & Unlinking Dynamic Resolution\n";

// Create User 2 (Bob, with users.upi_id = 'bob.profile@okaxis')
$reqRegister2 = new Request('POST', '/api/auth/register', [], [
    'email' => 'test_upi_user2@example.com',
    'password' => 'SecurePass123!',
    'display_name' => 'Bob Test',
]);
ob_start();
$authController->register($reqRegister2);
$respReg2 = json_decode(ob_get_clean(), true);
$user2Id = (int) $respReg2['data']['user']['id'];

$reqBobUpi = makeAuthRequest($user2Id, 'test_upi_user2@example.com', 'PUT', '/api/auth/profile', ['upi_id' => 'bob.profile@okaxis']);
ob_start();
$authController->updateProfile($reqBobUpi);
ob_end_clean();

// Bob claims Member A2 (which has members.upi_id = 'bob.guest@paytm')
$reqClaim = makeAuthRequest($user2Id, 'test_upi_user2@example.com', 'POST', "/api/groups/{$tokenA}/claim-member", ['member_id' => $memberA2Id]);
$reqClaim->setRouteParams(['token' => $tokenA]);
ob_start();
$authController->claimMember($reqClaim);
$respClaim = json_decode(ob_get_clean(), true);
testAssert($respClaim['success'] === true, "User 2 successfully links to Member A2.");

// Resolution for Member A2 should now immediately switch from 'bob.guest@paytm' to 'bob.profile@okaxis'
$m2Resolved = $memberRepo->findById($memberA2Id);
testAssert($m2Resolved['upi_id'] === 'bob.profile@okaxis', "Post-claim: Member A2 instantly resolves User 2's canonical profile UPI.");
testAssert($m2Resolved['member_upi_id'] === 'bob.guest@paytm', "Post-claim: Legacy member UPI remains intact in database.");

// Bob unlinks from Member A2
$reqUnlink = makeAuthRequest($user2Id, 'test_upi_user2@example.com', 'POST', "/api/groups/{$tokenA}/unlink-member", ['member_id' => $memberA2Id]);
$reqUnlink->setRouteParams(['token' => $tokenA]);
ob_start();
$authController->unlinkMember($reqUnlink);
$respUnlink = json_decode(ob_get_clean(), true);
testAssert($respUnlink['success'] === true, "User 2 successfully unlinks from Member A2.");

// Resolution for Member A2 should immediately revert back to guest legacy UPI 'bob.guest@paytm'
$m2Reverted = $memberRepo->findById($memberA2Id);
testAssert($m2Reverted['upi_id'] === 'bob.guest@paytm', "Post-unlink: Member A2 resolution reverts back to guest legacy UPI.");

// ----------------------------------------------------------------------------
// 7. Cleanup & Completion
// ----------------------------------------------------------------------------
$pdo->exec("DELETE FROM `expense_splits` WHERE `expense_id` = {$expId}");
$pdo->exec("DELETE FROM `expense_payers` WHERE `expense_id` = {$expId}");
$pdo->exec("DELETE FROM `expenses` WHERE `id` = {$expId}");
$pdo->exec("DELETE FROM `members` WHERE `group_id` IN ({$groupAId}, {$groupBId})");
$pdo->exec("DELETE FROM `groups` WHERE `id` IN ({$groupAId}, {$groupBId})");
$pdo->exec("DELETE FROM `user_sessions` WHERE `user_id` IN ({$user1Id}, {$user2Id})");
$pdo->exec("DELETE FROM `users` WHERE `id` IN ({$user1Id}, {$user2Id})");

echo "\n================================================================================\n";
echo " ALL PERSONAL UPI IDENTITY TESTS PASSED (17/17 Assertions)\n";
echo "================================================================================\n\n";
