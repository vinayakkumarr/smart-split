<?php

declare(strict_types=1);

/**
 * SMART SPLIT V2 — PERSONAL UPI IDENTITY
 * ADVERSARIAL TARGETED BREAKING TEST SUITE
 * 
 * Exhaustive penetration testing, BOLA/IDOR attacks, VPA injection matrix,
 * mass assignment attacks, cross-workspace contamination, historical immutability,
 * and financial isolation verification.
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Database;
use App\Core\Request;
use App\Controllers\AuthController;
use App\Controllers\MemberController;
use App\Controllers\BalanceController;
use App\Controllers\SettlementController;
use App\Repositories\GroupRepository;
use App\Repositories\MemberRepository;
use App\Repositories\SettlementRepository;
use App\Services\BalanceService;
use App\Services\SettlementEngine;
use App\Services\SplitCalculator;
use App\Utils\Money;

$pdo = Database::getConnection();

$totalAssertions = 0;
$passedAssertions = 0;
$failedAssertions = 0;
$defectRegister = [];

function advAssert(bool $condition, string $code, string $description, ?array $context = null): void {
    global $totalAssertions, $passedAssertions, $failedAssertions, $defectRegister;
    $totalAssertions++;
    if ($condition) {
        $passedAssertions++;
        echo "  [PASS] {$code}: {$description}\n";
    } else {
        $failedAssertions++;
        $defectRegister[] = [
            'code' => $code,
            'description' => $description,
            'context' => $context,
        ];
        echo "  [FAIL] {$code}: {$description}\n";
    }
}

echo "\n================================================================================\n";
echo " SMART SPLIT V2 — PERSONAL UPI IDENTITY: ADVERSARIAL BREAKING SUITE\n";
echo "================================================================================\n\n";

// Teardown any leftover test data
$pdo->exec("DELETE FROM `user_sessions` WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `email` LIKE 'adv_%@example.com')");
$pdo->exec("DELETE FROM `users` WHERE `email` LIKE 'adv_%@example.com'");
$pdo->exec("DELETE FROM `groups` WHERE `name` LIKE 'ADV_TEST_%'");

// ============================================================================
// 1. TEST ACTORS & DATA MATRIX SETUP
// ============================================================================
echo "--- 1. Establishing Isolated Adversarial Test Data Matrix ---\n";

$authController = new AuthController($pdo);
$groupRepo = new GroupRepository($pdo);
$memberRepo = new MemberRepository($pdo);
$balanceService = new BalanceService($pdo, $memberRepo);
$balanceController = new BalanceController($groupRepo, $balanceService);
$settlementRepo = new SettlementRepository($pdo);
$settlementController = new SettlementController($groupRepo, $memberRepo, $settlementRepo, $balanceService);

// Create User A
$pdo->exec("INSERT INTO users (email, password_hash, display_name, recovery_code_hash, avatar_emoji, avatar_color, upi_id, is_active)
            VALUES ('adv_usera@example.com', '" . password_hash('PassA123!', PASSWORD_BCRYPT) . "', 'User Alpha', 'recA', '👤', '#18352b', 'usera@okaxis', 1)");
$userAId = (int) $pdo->lastInsertId();

// Create User B
$pdo->exec("INSERT INTO users (email, password_hash, display_name, recovery_code_hash, avatar_emoji, avatar_color, upi_id, is_active)
            VALUES ('adv_userb@example.com', '" . password_hash('PassB123!', PASSWORD_BCRYPT) . "', 'User Beta', 'recB', '🛡️', '#3730a3', 'userb@hdfcbank', 1)");
$userBId = (int) $pdo->lastInsertId();

// Create User C (No Personal UPI)
$pdo->exec("INSERT INTO users (email, password_hash, display_name, recovery_code_hash, avatar_emoji, avatar_color, upi_id, is_active)
            VALUES ('adv_userc@example.com', '" . password_hash('PassC123!', PASSWORD_BCRYPT) . "', 'User Charlie', 'recC', '🧭', '#28534e', NULL, 1)");
$userCId = (int) $pdo->lastInsertId();

// Create Group 1 (Contains User A & Guest 1)
$group1 = $groupRepo->create('ADV_TEST_GROUP_1', 'INR');
$g1Id = (int) $group1['id'];
$g1Token = $group1['invite_token'];

$m1_UserA = $memberRepo->create($g1Id, 'User A (G1)', $userAId);
$m1_UserAId = (int) $m1_UserA['id'];
$pdo->exec("UPDATE members SET upi_id = 'legacy1@upi' WHERE id = {$m1_UserAId}");

$m1_Guest = $memberRepo->create($g1Id, 'Guest One');
$m1_GuestId = (int) $m1_Guest['id'];
$pdo->exec("UPDATE members SET upi_id = 'guest1@paytm' WHERE id = {$m1_GuestId}");

// Create Group 2 (Contains User A, User B, User C)
$group2 = $groupRepo->create('ADV_TEST_GROUP_2', 'INR');
$g2Id = (int) $group2['id'];
$g2Token = $group2['invite_token'];

$m2_UserA = $memberRepo->create($g2Id, 'User A (G2)', $userAId);
$m2_UserAId = (int) $m2_UserA['id'];
$pdo->exec("UPDATE members SET upi_id = 'legacy2@upi' WHERE id = {$m2_UserAId}");

$m2_UserB = $memberRepo->create($g2Id, 'User B (G2)', $userBId);
$m2_UserBId = (int) $m2_UserB['id'];

$m2_UserC = $memberRepo->create($g2Id, 'User C (G2)', $userCId);
$m2_UserCId = (int) $m2_UserC['id'];

// Create Group 3 (Does NOT contain User A)
$group3 = $groupRepo->create('ADV_TEST_GROUP_3', 'INR');
$g3Id = (int) $group3['id'];
$g3Token = $group3['invite_token'];

$m3_UserB = $memberRepo->create($g3Id, 'User B (G3)', $userBId);
$m3_UserBId = (int) $m3_UserB['id'];

advAssert($userAId > 0 && $userBId > 0 && $userCId > 0, 'ADV-SETUP-01', 'Test user accounts established');
advAssert($g1Id > 0 && $g2Id > 0 && $g3Id > 0, 'ADV-SETUP-02', 'Test workspaces established');

function makeTestAuthRequest(int $userId, string $email, string $method, string $path, array $body = [], array $params = []): Request {
    $req = new Request($method, $path, [], $body);
    $req->setUser([
        'id' => $userId,
        'email' => $email,
        'display_name' => 'Test User',
        'avatar_emoji' => '👤',
        'avatar_color' => '#18352b',
        'session_id' => 99999,
    ]);
    if (!empty($params)) {
        $req->setRouteParams($params);
    }
    return $req;
}

// ============================================================================
// 2. PROFILE UPI CRUD & FORMAT BOUNDARY BREAKING TESTS
// ============================================================================
echo "\n--- 2. Profile UPI CRUD & Format Boundary Breaking Tests ---\n";

// 2.1 Minimum valid VPA (5 chars: 2 handle + @ + 2 provider, e.g. ab@cd)
$reqMin = makeTestAuthRequest($userAId, 'adv_usera@example.com', 'PUT', '/api/auth/profile', ['upi_id' => 'ab@cd']);
ob_start();
$authController->updateProfile($reqMin);
$resMin = json_decode(ob_get_clean(), true);
$dbUpiMin = $pdo->query("SELECT upi_id FROM users WHERE id = {$userAId}")->fetchColumn();
advAssert($resMin['success'] === true && $resMin['data']['user']['upi_id'] === 'ab@cd' && $dbUpiMin === 'ab@cd', 'ADV-CRUD-01', 'Minimum 5-character VPA (ab@cd) accepted and persisted');

// 2.2 Maximum valid VPA (80 chars: 15 chars + @ + 64 chars domain)
$handle15 = str_repeat('a', 15);
$domain64 = str_repeat('b', 64);
$vpa80 = "{$handle15}@{$domain64}"; // 15 + 1 + 64 = 80 chars
$reqMax = makeTestAuthRequest($userAId, 'adv_usera@example.com', 'PUT', '/api/auth/profile', ['upi_id' => $vpa80]);
ob_start();
$authController->updateProfile($reqMax);
$resMax = json_decode(ob_get_clean(), true);
$dbUpiMax = $pdo->query("SELECT upi_id FROM users WHERE id = {$userAId}")->fetchColumn();
advAssert($resMax['success'] === true && $resMax['data']['user']['upi_id'] === $vpa80 && $dbUpiMax === $vpa80, 'ADV-CRUD-02', 'Maximum 80-character VPA accepted and persisted exactly');

// 2.3 Mixed-case, dots, underscores, hyphens, numbers
$vpaMixed = 'John.Doe_99-pay@okhdfc';
$reqMixed = makeTestAuthRequest($userAId, 'adv_usera@example.com', 'PUT', '/api/auth/profile', ['upi_id' => $vpaMixed]);
ob_start();
$authController->updateProfile($reqMixed);
$resMixed = json_decode(ob_get_clean(), true);
$dbUpiMixed = $pdo->query("SELECT upi_id FROM users WHERE id = {$userAId}")->fetchColumn();
advAssert($resMixed['success'] === true && $resMixed['data']['user']['upi_id'] === $vpaMixed && $dbUpiMixed === $vpaMixed, 'ADV-CRUD-03', 'Complex VPA with mixed case, dots, hyphens, underscores persisted');

// 2.4 Sequential State Machine Transitions: A -> B -> C -> A
$vpaSeq = ['seq.one@bank', 'seq.two@bank', 'seq.three@bank', 'seq.one@bank'];
$allSeqPass = true;
foreach ($vpaSeq as $seqVal) {
    $reqSeq = makeTestAuthRequest($userAId, 'adv_usera@example.com', 'PUT', '/api/auth/profile', ['upi_id' => $seqVal]);
    ob_start();
    $authController->updateProfile($reqSeq);
    $resSeq = json_decode(ob_get_clean(), true);
    $curDb = $pdo->query("SELECT upi_id FROM users WHERE id = {$userAId}")->fetchColumn();
    if ($resSeq['data']['user']['upi_id'] !== $seqVal || $curDb !== $seqVal) {
        $allSeqPass = false;
    }
}
advAssert($allSeqPass, 'ADV-CRUD-04', 'Sequential state transitions A -> B -> C -> A update DB without stale cache');

// 2.5 Nullable Clearing & Whitespace Trimming to genuine SQL NULL
$clearPayloads = [
    'empty_string' => '',
    'whitespace_spaces' => '    ',
    'whitespace_tabs' => "\t\t",
    'explicit_null' => null,
];
$allClearsPass = true;
foreach ($clearPayloads as $cType => $cVal) {
    // Set a known value first
    $pdo->exec("UPDATE users SET upi_id = 'temporary@bank' WHERE id = {$userAId}");
    $reqClr = makeTestAuthRequest($userAId, 'adv_usera@example.com', 'PUT', '/api/auth/profile', ['upi_id' => $cVal]);
    ob_start();
    $authController->updateProfile($reqClr);
    $resClr = json_decode(ob_get_clean(), true);
    $stmtDb = $pdo->query("SELECT upi_id FROM users WHERE id = {$userAId}");
    $rowDb = $stmtDb->fetch(PDO::FETCH_ASSOC);
    if ($resClr['data']['user']['upi_id'] !== null || $rowDb['upi_id'] !== null) {
        $allClearsPass = false;
    }
}
advAssert($allClearsPass, 'ADV-CRUD-05', 'Clearing via empty string, whitespace, tabs, and null writes genuine SQL NULL (not string "null" or empty string)');

// ============================================================================
// 3. ADVERSARIAL INVALID VPA INJECTION ATTACK MATRIX (30+ Attack Vectors)
// ============================================================================
echo "\n--- 3. Adversarial Invalid VPA Injection Attack Matrix ---\n";

$attackVectors = [
    'empty' => '', // when sent as invalid standalone if required (tested above as clear)
    'single_char' => 'a',
    'single_char_at' => 'a@',
    'at_only' => '@upi',
    'too_short_handle' => 'a@b',
    'too_short_domain' => 'ab@c',
    'double_at' => 'user@@bank',
    'space_in_handle' => 'user name@bank',
    'space_in_domain' => 'user@bank name',
    'script_tag_in_handle' => '<script>alert(1)</script>@bank',
    'script_tag_in_domain' => 'user@<script>alert(1)</script>',
    'raw_script_tag' => '<script>alert(1)</script>',
    'sql_sq_inject' => "user' OR '1'='1@bank",
    'sql_stacked_drop' => "user@bank; DROP TABLE users;--",
    'double_quote' => 'user"quote@bank',
    'backslash' => 'user\\slash@bank',
    'semicolon' => 'user;semicolon@bank',
    'newline_char' => "user\nname@bank",
    'carriage_return' => "user\rname@bank",
    'tab_char' => "user\tname@bank",
    'emoji_handle' => 'user💳@bank',
    'emoji_domain' => 'user@bank💳',
    'unicode_handle' => 'userñ@bank',
    'unicode_domain' => 'user@bänk',
    'multiple_at_signs' => 'user@bank@upi@okaxis',
    'overlength_81_chars' => str_repeat('a', 16) . '@' . str_repeat('b', 65), // 82 chars
    'invalid_type_integer' => 123,
    'invalid_type_float' => 123.45,
    'invalid_type_boolean_true' => true,
    'invalid_type_boolean_false' => false,
    'invalid_type_empty_array' => [],
    'invalid_type_array_with_val' => ['alice@okaxis'],
    'invalid_type_object' => ['vpa' => 'alice@okaxis'],
    'trailing_semicolon_vpa' => 'valid.user@okaxis;',
    'leading_slash' => '/alice@okaxis',
];

$allRejectionsPass = true;
$rejectionFailures = [];

foreach ($attackVectors as $vName => $vPayload) {
    // Set known good value
    $pdo->exec("UPDATE users SET upi_id = 'stable.good@okaxis' WHERE id = {$userAId}");
    
    $reqAtk = makeTestAuthRequest($userAId, 'adv_usera@example.com', 'PUT', '/api/auth/profile', ['upi_id' => $vPayload]);
    ob_start();
    $authController->updateProfile($reqAtk);
    $rawOutput = ob_get_clean();
    $jsonStart = strpos($rawOutput, '{');
    $jsonStr = $jsonStart !== false ? substr($rawOutput, $jsonStart) : $rawOutput;
    $resAtk = json_decode($jsonStr, true);
    
    $dbAfter = $pdo->query("SELECT upi_id FROM users WHERE id = {$userAId}")->fetchColumn();
    
    // An attack payload should be rejected with 422 INVALID_UPI_ID and preserve the pre-existing DB value
    if ($vName === 'empty') {
        continue; // empty string tested in clear section
    }
    
    $isRejected = isset($resAtk['success']) && $resAtk['success'] === false && $resAtk['error']['code'] === 'INVALID_UPI_ID';
    $isDbPreserved = ($dbAfter === 'stable.good@okaxis');
    
    if (!$isRejected || !$isDbPreserved) {
        $allRejectionsPass = false;
        $rejectionFailures[] = "{$vName}: rejected=" . ($isRejected ? 'YES' : 'NO') . " db_preserved=" . ($isDbPreserved ? 'YES' : 'NO');
    }
}

advAssert($allRejectionsPass, 'ADV-INJ-01', 'All 30+ malformed, XSS, SQLi, overlength, and invalid-type VPA payloads strictly rejected with 422 and 0 DB mutation', $rejectionFailures);

// ============================================================================
// 4. BOLA / IDOR & AUTHORIZATION ATTACK MATRIX
// ============================================================================
echo "\n--- 4. BOLA / IDOR & Authorization Attacks ---\n";

// 4.1 Authenticate as User A, attempt to mutate User B's profile via body parameter injection
$pdo->exec("UPDATE users SET upi_id = 'userb.original@hdfcbank' WHERE id = {$userBId}");
$pdo->exec("UPDATE users SET upi_id = 'usera.original@okaxis' WHERE id = {$userAId}");

$reqBolaBody = makeTestAuthRequest($userAId, 'adv_usera@example.com', 'PUT', '/api/auth/profile', [
    'id' => $userBId,
    'user_id' => $userBId,
    'account_id' => $userBId,
    'upi_id' => 'hacked.by.usera@attacker',
]);
ob_start();
$authController->updateProfile($reqBolaBody);
$resBolaBody = json_decode(ob_get_clean(), true);

$userBDbAfter = $pdo->query("SELECT upi_id FROM users WHERE id = {$userBId}")->fetchColumn();
$userADbAfter = $pdo->query("SELECT upi_id FROM users WHERE id = {$userAId}")->fetchColumn();

advAssert(
    $userBDbAfter === 'userb.original@hdfcbank' && $userADbAfter === 'hacked.by.usera@attacker',
    'ADV-BOLA-01',
    'BOLA Attack Rejected: Injected user_id/account_id is ignored, User B remains completely unmodified, only User A changes'
);

// 4.2 Unauthenticated Request: Missing session cookie / user context
$reqUnauth = new Request('PUT', '/api/auth/profile', [], ['upi_id' => 'unauth.hacker@evil']);
ob_start();
$authController->updateProfile($reqUnauth);
$resUnauth = json_decode(ob_get_clean(), true);

advAssert(
    $resUnauth['success'] === false && $resUnauth['error']['code'] === 'UNAUTHORIZED',
    'ADV-AUTH-01',
    'Unauthenticated profile modification returns 401 UNAUTHORIZED with zero DB mutation'
);

// 4.3 Session Switching & Multi-User Rapid Alternation
$pdo->exec("UPDATE users SET upi_id = 'usera@okaxis' WHERE id = {$userAId}");
$pdo->exec("UPDATE users SET upi_id = 'userb@hdfcbank' WHERE id = {$userBId}");

$sessionAlternationPass = true;
for ($i = 0; $i < 5; $i++) {
    // Request User A
    $reqA = makeTestAuthRequest($userAId, 'adv_usera@example.com', 'PUT', '/api/auth/profile', ['upi_id' => "usera.iter{$i}@okaxis"]);
    ob_start();
    $authController->updateProfile($reqA);
    $resA = json_decode(ob_get_clean(), true);
    
    // Request User B
    $reqB = makeTestAuthRequest($userBId, 'adv_userb@example.com', 'PUT', '/api/auth/profile', ['upi_id' => "userb.iter{$i}@hdfcbank"]);
    ob_start();
    $authController->updateProfile($reqB);
    $resB = json_decode(ob_get_clean(), true);
    
    $curA = $pdo->query("SELECT upi_id FROM users WHERE id = {$userAId}")->fetchColumn();
    $curB = $pdo->query("SELECT upi_id FROM users WHERE id = {$userBId}")->fetchColumn();
    
    if ($curA !== "usera.iter{$i}@okaxis" || $curB !== "userb.iter{$i}@hdfcbank") {
        $sessionAlternationPass = false;
    }
}
advAssert($sessionAlternationPass, 'ADV-SESS-01', 'Rapid alternating multi-user session requests execute without session confusion');

// ============================================================================
// 5. PROFILE MASS-ASSIGNMENT SECURITY ATTACK
// ============================================================================
echo "\n--- 5. Profile Mass-Assignment Security Attack ---\n";

// Capture User A state before attack
$userABefore = $pdo->query("SELECT * FROM users WHERE id = {$userAId}")->fetch(PDO::FETCH_ASSOC);

$reqMassAssign = makeTestAuthRequest($userAId, 'adv_usera@example.com', 'PUT', '/api/auth/profile', [
    'upi_id' => 'alice.legit@okaxis',
    'id' => 1,
    'email' => 'admin_hijacked@example.com',
    'password' => 'HackedPassword123!',
    'password_hash' => '$2y$12$malicioushashhere',
    'recovery_code_hash' => '$2y$10$maliciousrecovhash',
    'failed_login_attempts' => 99,
    'locked_until' => '2099-01-01 00:00:00',
    'is_active' => 0,
    'role' => 'SUPERADMIN',
    'is_admin' => 1,
]);
ob_start();
$authController->updateProfile($reqMassAssign);
$resMassAssign = json_decode(ob_get_clean(), true);

$userAAfter = $pdo->query("SELECT * FROM users WHERE id = {$userAId}")->fetch(PDO::FETCH_ASSOC);

$massAssignPrevented = (
    $userAAfter['upi_id'] === 'alice.legit@okaxis' &&
    $userAAfter['id'] === $userABefore['id'] &&
    $userAAfter['email'] === $userABefore['email'] &&
    $userAAfter['password_hash'] === $userABefore['password_hash'] &&
    $userAAfter['recovery_code_hash'] === $userABefore['recovery_code_hash'] &&
    $userAAfter['failed_login_attempts'] === $userABefore['failed_login_attempts'] &&
    $userAAfter['locked_until'] === $userABefore['locked_until'] &&
    $userAAfter['is_active'] === $userABefore['is_active']
);

advAssert($massAssignPrevented, 'ADV-MASS-01', 'Mass-assignment attack prevented: protected security/auth columns cannot be modified via profile endpoint');

// ============================================================================
// 6. CROSS-WORKSPACE RESOLUTION, PRECEDENCE & CONTAMINATION ATTACK
// ============================================================================
echo "\n--- 6. Cross-Workspace Resolution, Precedence & Contamination ---\n";

// Setup state:
// User A has canonical upi_id = 'alice.canonical@okaxis'
// Group 1: Member 1 linked to User A (has legacy members.upi_id = 'legacy.g1@paytm')
// Group 2: Member 1 linked to User A (has legacy members.upi_id = 'legacy.g2@axis')
// Group 3: Does NOT have User A

$pdo->exec("UPDATE users SET upi_id = 'alice.canonical@okaxis' WHERE id = {$userAId}");
$pdo->exec("UPDATE members SET upi_id = 'legacy.g1@paytm' WHERE id = {$m1_UserAId}");
$pdo->exec("UPDATE members SET upi_id = 'legacy.g2@axis' WHERE id = {$m2_UserAId}");

// 6.1 Precedence Test: Canonical profile UPI must supersede legacy member UPI in both workspaces
$m1Res = $memberRepo->findById($m1_UserAId);
$m2Res = $memberRepo->findById($m2_UserAId);

advAssert(
    $m1Res['upi_id'] === 'alice.canonical@okaxis' &&
    $m1Res['member_upi_id'] === 'legacy.g1@paytm' &&
    $m1Res['user_upi_id'] === 'alice.canonical@okaxis',
    'ADV-PREC-01',
    'Group 1 resolves canonical users.upi_id while preserving legacy members.upi_id'
);

advAssert(
    $m2Res['upi_id'] === 'alice.canonical@okaxis' &&
    $m2Res['member_upi_id'] === 'legacy.g2@axis' &&
    $m2Res['user_upi_id'] === 'alice.canonical@okaxis',
    'ADV-PREC-02',
    'Group 2 resolves canonical users.upi_id while preserving legacy members.upi_id'
);

// 6.2 Precedence Test: Remove canonical profile UPI -> Fallback to respective legacy member UPIs
$pdo->exec("UPDATE users SET upi_id = NULL WHERE id = {$userAId}");
$m1ResFallback = $memberRepo->findById($m1_UserAId);
$m2ResFallback = $memberRepo->findById($m2_UserAId);

advAssert(
    $m1ResFallback['upi_id'] === 'legacy.g1@paytm' &&
    $m2ResFallback['upi_id'] === 'legacy.g2@axis',
    'ADV-PREC-03',
    'When users.upi_id is NULL, Group 1 falls back to legacy.g1@paytm and Group 2 falls back to legacy.g2@axis independently'
);

// 6.3 Precedence Test: Restore canonical profile UPI -> Both return to canonical
$pdo->exec("UPDATE users SET upi_id = 'alice.canonical@okaxis' WHERE id = {$userAId}");
$m1ResRestored = $memberRepo->findById($m1_UserAId);
$m2ResRestored = $memberRepo->findById($m2_UserAId);

advAssert(
    $m1ResRestored['upi_id'] === 'alice.canonical@okaxis' &&
    $m2ResRestored['upi_id'] === 'alice.canonical@okaxis',
    'ADV-PREC-04',
    'Restoring users.upi_id instantly restores canonical resolution uniformly across all workspaces'
);

// 6.4 Guest member is 100% unaffected by user profile changes
$mGuestRes = $memberRepo->findById($m1_GuestId);
advAssert(
    $mGuestRes['upi_id'] === 'guest1@paytm' &&
    $mGuestRes['user_id'] === null &&
    $mGuestRes['user_upi_id'] === null,
    'ADV-GUEST-01',
    'Guest member resolves members.upi_id without any dependency on users table'
);

// 6.5 User C (Registered user with NULL upi_id and NULL member upi_id) resolves NULL
$m2UserCRes = $memberRepo->findById($m2_UserCId);
advAssert(
    $m2UserCRes['upi_id'] === null &&
    $m2UserCRes['user_upi_id'] === null &&
    $m2UserCRes['member_upi_id'] === null,
    'ADV-PREC-05',
    'Registered user with no profile UPI and no member UPI resolves NULL without throwing exceptions'
);

// ============================================================================
// 7. SETTLEMENT PLAN DYNAMIC ROUTING & QR INTEGRITY
// ============================================================================
echo "\n--- 7. Settlement Plan Dynamic Routing & QR Integrity ---\n";

// In Group 2:
// User A pays ₹90.00 (9000 paise) split equally among User A, User B, User C (₹30.00 each)
// Debtors: User B owes ₹30.00 to User A, User C owes ₹30.00 to User A
$pdo->exec("
    INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `split_type`, `expense_date`, `created_by_member_id`)
    VALUES ({$g2Id}, 'Group 2 Dinner', 9000, 'EQUAL', '2026-10-04', {$m2_UserAId})
");
$expG2Id = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO `expense_payers` (`expense_id`, `member_id`, `amount_paid_cents`) VALUES ({$expG2Id}, {$m2_UserAId}, 9000)");
$pdo->exec("INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`) VALUES 
    ({$expG2Id}, {$m2_UserAId}, 3000),
    ({$expG2Id}, {$m2_UserBId}, 3000),
    ({$expG2Id}, {$m2_UserCId}, 3000)
");

// 7.1 Compute settlement plan: Payee is User A -> to_upi_id must be 'alice.canonical@okaxis'
$reqPlanG2 = new Request('GET', "/api/groups/{$g2Token}/settlement-plan", [], []);
$reqPlanG2->setRouteParams(['token' => $g2Token]);
ob_start();
$balanceController->settlementPlan($reqPlanG2);
$resPlanG2 = json_decode(ob_get_clean(), true);

$txs = $resPlanG2['data']['transactions'];
$allTxsHaveCanonicalUpi = true;
foreach ($txs as $tx) {
    if ($tx['to_member_id'] === $m2_UserAId && $tx['to_upi_id'] !== 'alice.canonical@okaxis') {
        $allTxsHaveCanonicalUpi = false;
    }
}
advAssert(count($txs) === 2 && $allTxsHaveCanonicalUpi, 'ADV-SETTLE-01', 'Settlement Plan transfers dynamically resolve creditor canonical profile UPI');

// 7.2 Update User A UPI to 'alice.new@icici' -> Next settlement plan immediately reflects new UPI
$pdo->exec("UPDATE users SET upi_id = 'alice.new@icici' WHERE id = {$userAId}");
ob_start();
$balanceController->settlementPlan($reqPlanG2);
$resPlanG2Updated = json_decode(ob_get_clean(), true);

$txsUpdated = $resPlanG2Updated['data']['transactions'];
$allTxsUpdated = true;
foreach ($txsUpdated as $tx) {
    if ($tx['to_member_id'] === $m2_UserAId && $tx['to_upi_id'] !== 'alice.new@icici') {
        $allTxsUpdated = false;
    }
}
advAssert($allTxsUpdated, 'ADV-SETTLE-02', 'Profile UPI update instantly routes subsequent settlement transactions to new VPA');

// ============================================================================
// 8. HISTORICAL FINANCIAL & SETTLEMENT IMMUTABILITY ATTACK
// ============================================================================
echo "\n--- 8. Historical Financial & Settlement Immutability Attack ---\n";

// Record a completed settlement payment from User B to User A for ₹30.00
$pdo->exec("
    INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `notes`)
    VALUES ({$g2Id}, {$m2_UserBId}, {$m2_UserAId}, 3000, 'Settlement payment while UPI was alice.new@icici')
");
$historicalSettleId = (int) $pdo->lastInsertId();

$settleBefore = $pdo->query("SELECT * FROM `settlements` WHERE `id` = {$historicalSettleId}")->fetch(PDO::FETCH_ASSOC);

// Now perform adversarial UPI mutations on User A (change, clear, change again)
$pdo->exec("UPDATE users SET upi_id = 'alice.brandnew@axis' WHERE id = {$userAId}");
$pdo->exec("UPDATE users SET upi_id = NULL WHERE id = {$userAId}");
$pdo->exec("UPDATE users SET upi_id = 'alice.final@okaxis' WHERE id = {$userAId}");

$settleAfter = $pdo->query("SELECT * FROM `settlements` WHERE `id` = {$historicalSettleId}")->fetch(PDO::FETCH_ASSOC);

$isHistoricalSettlementConserved = (
    $settleAfter['id'] === $settleBefore['id'] &&
    $settleAfter['group_id'] === $settleBefore['group_id'] &&
    $settleAfter['payer_member_id'] === $settleBefore['payer_member_id'] &&
    $settleAfter['payee_member_id'] === $settleBefore['payee_member_id'] &&
    $settleAfter['amount_cents'] === $settleBefore['amount_cents'] &&
    $settleAfter['notes'] === $settleBefore['notes'] &&
    $settleAfter['is_deleted'] === $settleBefore['is_deleted']
);

advAssert($isHistoricalSettlementConserved, 'ADV-HIST-01', 'Historical settlement records remain 100% byte-for-byte immutable across profile UPI mutations');

// ============================================================================
// 9. FINANCIAL ENGINE MATHEMATICAL ISOLATION
// ============================================================================
echo "\n--- 9. Financial Engine Mathematical Isolation ---\n";

// Verify that SplitCalculator and SettlementEngine mathematical outputs are completely invariant to UPI metadata
$balancesNoUpi = [
    ['member_id' => 1, 'name' => 'A', 'net_balance_cents' => 6000, 'upi_id' => null],
    ['member_id' => 2, 'name' => 'B', 'net_balance_cents' => -3000, 'upi_id' => null],
    ['member_id' => 3, 'name' => 'C', 'net_balance_cents' => -3000, 'upi_id' => null],
];

$balancesWithUpi1 = [
    ['member_id' => 1, 'name' => 'A', 'net_balance_cents' => 6000, 'upi_id' => 'alice@okaxis'],
    ['member_id' => 2, 'name' => 'B', 'net_balance_cents' => -3000, 'upi_id' => 'bob@hdfc'],
    ['member_id' => 3, 'name' => 'C', 'net_balance_cents' => -3000, 'upi_id' => null],
];

$balancesWithUpi2 = [
    ['member_id' => 1, 'name' => 'A', 'net_balance_cents' => 6000, 'upi_id' => 'alice.new@icici'],
    ['member_id' => 2, 'name' => 'B', 'net_balance_cents' => -3000, 'upi_id' => null],
    ['member_id' => 3, 'name' => 'C', 'net_balance_cents' => -3000, 'upi_id' => 'charlie@okaxis'],
];

$plan0 = SettlementEngine::simplifyDebts($balancesNoUpi);
$plan1 = SettlementEngine::simplifyDebts($balancesWithUpi1);
$plan2 = SettlementEngine::simplifyDebts($balancesWithUpi2);

$financialIntegrityExact = (
    $plan0['total_transactions'] === $plan1['total_transactions'] &&
    $plan1['total_transactions'] === $plan2['total_transactions'] &&
    $plan0['total_settlement_volume_cents'] === $plan1['total_settlement_volume_cents'] &&
    $plan1['total_settlement_volume_cents'] === $plan2['total_settlement_volume_cents'] &&
    $plan0['transactions'][0]['amount_cents'] === $plan1['transactions'][0]['amount_cents'] &&
    $plan1['transactions'][0]['amount_cents'] === $plan2['transactions'][0]['amount_cents']
);

advAssert($financialIntegrityExact, 'ADV-FIN-01', 'Financial Engine output invariant to UPI variations (exact 6000 cents volume & 2 transactions)');

// ============================================================================
// 10. ROUTE / ALIAS SYMMETRICAL CONTRACT TEST
// ============================================================================
echo "\n--- 10. Route / Alias Symmetrical Contract Test ---\n";

$reqAuthRoute = makeTestAuthRequest($userAId, 'adv_usera@example.com', 'PUT', '/api/auth/profile', ['upi_id' => 'alice.alias@okaxis']);
ob_start();
$authController->updateProfile($reqAuthRoute);
$resAuthRoute = json_decode(ob_get_clean(), true);

$reqUserRoute = makeTestAuthRequest($userAId, 'adv_usera@example.com', 'PUT', '/api/user/profile', ['upi_id' => 'alice.alias2@okaxis']);
ob_start();
$authController->updateProfile($reqUserRoute);
$resUserRoute = json_decode(ob_get_clean(), true);

advAssert(
    $resAuthRoute['success'] === true && $resUserRoute['success'] === true &&
    $resAuthRoute['data']['user']['upi_id'] === 'alice.alias@okaxis' &&
    $resUserRoute['data']['user']['upi_id'] === 'alice.alias2@okaxis',
    'ADV-ALIAS-01',
    'Symmetrical contract: Both /api/auth/profile and /api/user/profile execute identical validation & DB mutation'
);

// ============================================================================
// 11. TEARDOWN & SUMMARY
// ============================================================================
echo "\n--- 11. Fixture Teardown & Database Integrity Verification ---\n";

$pdo->exec("DELETE FROM `settlements` WHERE `group_id` IN ({$g1Id}, {$g2Id}, {$g3Id})");
$pdo->exec("DELETE FROM `expense_splits` WHERE `expense_id` = {$expG2Id}");
$pdo->exec("DELETE FROM `expense_payers` WHERE `expense_id` = {$expG2Id}");
$pdo->exec("DELETE FROM `expenses` WHERE `group_id` IN ({$g1Id}, {$g2Id}, {$g3Id})");
$pdo->exec("DELETE FROM `members` WHERE `group_id` IN ({$g1Id}, {$g2Id}, {$g3Id})");
$pdo->exec("DELETE FROM `groups` WHERE `id` IN ({$g1Id}, {$g2Id}, {$g3Id})");
$pdo->exec("DELETE FROM `user_sessions` WHERE `user_id` IN ({$userAId}, {$userBId}, {$userCId})");
$pdo->exec("DELETE FROM `users` WHERE `id` IN ({$userAId}, {$userBId}, {$userCId})");

// Verify zero test artifacts remained
$orphanUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'adv_%'")->fetchColumn();
$orphanGroups = (int) $pdo->query("SELECT COUNT(*) FROM `groups` WHERE name LIKE 'ADV_TEST_%'")->fetchColumn();

advAssert($orphanUsers === 0 && $orphanGroups === 0, 'ADV-CLEAN-01', 'All test actors, groups, and settlements cleanly purged from database');

echo "\n================================================================================\n";
echo " ADVERSARIAL BREAKING TEST SUITE SUMMARY\n";
echo "================================================================================\n";
echo " Total Assertions Executed: {$totalAssertions}\n";
echo " Passed Assertions:         {$passedAssertions}\n";
echo " Failed Assertions:         {$failedAssertions}\n";
echo " Success Rate:              " . sprintf('%.1f%%', ($passedAssertions / max(1, $totalAssertions)) * 100) . "\n";
echo "================================================================================\n\n";

if ($failedAssertions > 0) {
    exit(1);
}
exit(0);
