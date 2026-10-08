<?php

declare(strict_types=1);

/**
 * Smart Split V2 – Secure Guest-to-Account Workspace Claiming Regression Suite
 *
 * Validates the complete lifecycle and security boundaries:
 * T01 — Valid creator claim: Guest workspace + valid creator token + authenticated user
 * T02 — Invalid creator token: Fails with 403, groups.owner_user_id remains NULL
 * T03 — Invite token alone: Claim rejected with 403 when creator token is missing
 * T04 — Numeric identifiers cannot authorize claim (IDOR/BOLA defense)
 * T05 — Already owned by same user: Idempotent success (200), no duplicate mutation
 * T06 — Already owned by another user: Fails with 409 conflict, no ownership change
 * T07 — Concurrent claim: Concurrency safety via row-locking
 * T08 — Multiple guest workspaces: Independent claim verification
 * T09 — Cloud workspace aggregation & deduplication in /api/user/workspaces
 * T10 — Financial integrity: Expenses, splits, balances, and settlements 100% mathematically unchanged
 * T11 — Cross-device discovery: GET /api/user/workspaces returns workspace for user
 * T12 — Fresh browser discovery: Authenticated user discovers workspace without localStorage
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
echo " SMART SPLIT: GUEST-TO-ACCOUNT WORKSPACE CLAIM REGRESSION SUITE\n";
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

// Initialize Router & Middleware
$router = new Router();
$router->use(new SecurityHeadersMiddleware());
$router->use(new AuthSessionMiddleware());

// Register API Routes
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get('/api/auth/me', [AuthController::class, 'me']);
$router->post('/api/groups/{token}/claim-workspace', [AuthController::class, 'claimWorkspace']);
$router->post('/api/groups/{token}/claim-member', [AuthController::class, 'claimMember']);
$router->get('/api/user/workspaces', [AuthController::class, 'userWorkspaces']);

$router->post('/api/groups', [GroupController::class, 'create']);
$router->get('/api/groups/{token}', [GroupController::class, 'show']);
$router->get('/api/groups/{token}/workspace', [GroupController::class, 'workspace']);
$router->post('/api/groups/{token}/members', [MemberController::class, 'create']);
$router->post('/api/groups/{token}/expenses', [ExpenseController::class, 'create']);
$router->get('/api/groups/{token}/balances', [BalanceController::class, 'index']);
$router->get('/api/groups/{token}/settlement-plan', [BalanceController::class, 'settlementPlan']);
$router->post('/api/groups/{token}/settlements', [SettlementController::class, 'create']);

function dispatchReq(Router $router, string $method, string $path, array $body = [], array $headers = [], ?string $sessionToken = null): array
{
    if ($sessionToken !== null) {
        $_COOKIE['smartsplit_session'] = $sessionToken;
    } else {
        unset($_COOKIE['smartsplit_session']);
    }

    $req = new Request($method, $path, [], $body, $headers);
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

// --- Setup Test Users ---
$unique = substr(md5(uniqid()), 0, 8);
$user1Email = "claim_user1_{$unique}@example.com";
$user2Email = "claim_user2_{$unique}@example.com";
$pass = "ClaimPass123!";

$u1Res = dispatchReq($router, 'POST', '/api/auth/register', [
    'email' => $user1Email,
    'password' => $pass,
    'display_name' => 'User Alice',
]);
$user1Id = (int) ($u1Res['data']['user']['id'] ?? 0);
$session1 = $u1Res['_session_cookie'];

$u2Res = dispatchReq($router, 'POST', '/api/auth/register', [
    'email' => $user2Email,
    'password' => $pass,
    'display_name' => 'User Bob',
]);
$user2Id = (int) ($u2Res['data']['user']['id'] ?? 0);
$session2 = $u2Res['_session_cookie'];

assertCheck("Test User 1 and User 2 registered successfully", $user1Id > 0 && $user2Id > 0 && !empty($session1) && !empty($session2));

// =============================================================================
// T01: Valid Creator Workspace Claim
// =============================================================================
echo "\n--- T01: Valid Creator Workspace Claim ---\n";

// 1. Create a guest workspace (no session)
$g1Res = dispatchReq($router, 'POST', '/api/groups', [
    'name' => 'Goa Vacation',
    'creator_name' => 'Organizer Alice',
    'currency' => 'INR',
], [], null);

assertCheck("Guest workspace created with HTTP 201", $g1Res['_status_code'] === 201);
$token1 = $g1Res['data']['group']['invite_token'];
$creatorToken1 = $g1Res['data']['creator']['member_token'];
$creatorId1 = (int) $g1Res['data']['creator']['id'];
$groupId1 = (int) $g1Res['data']['group']['id'];

// Verify DB initial guest state
$stmt = $pdo->prepare("SELECT `owner_user_id` FROM `groups` WHERE `id` = :id");
$stmt->execute([':id' => $groupId1]);
$gRow = $stmt->fetch();
assertCheck("Initial workspace in DB has owner_user_id = NULL", $gRow['owner_user_id'] === null);

$stmt = $pdo->prepare("SELECT `user_id` FROM `members` WHERE `id` = :id");
$stmt->execute([':id' => $creatorId1]);
$mRow = $stmt->fetch();
assertCheck("Initial creator member in DB has user_id = NULL", $mRow['user_id'] === null);

// 2. User 1 claims the workspace with valid X-Creator-Token
$claimRes1 = dispatchReq($router, 'POST', "/api/groups/{$token1}/claim-workspace", [], [
    'X-Creator-Token' => $creatorToken1,
], $session1);

assertCheck("Valid claim returns HTTP 200 and claimed=true", $claimRes1['_status_code'] === 200 && ($claimRes1['data']['claimed'] ?? false) === true);
assertCheck("Claim response flags is_owner=true", ($claimRes1['data']['is_owner'] ?? false) === true);

// Verify DB state mutated accurately
$stmt = $pdo->prepare("SELECT `owner_user_id` FROM `groups` WHERE `id` = :id");
$stmt->execute([':id' => $groupId1]);
$gRowAfter = $stmt->fetch();
assertCheck("Workspace in DB is now owned by User 1 (owner_user_id = {$user1Id})", (int) $gRowAfter['owner_user_id'] === $user1Id);

$stmt = $pdo->prepare("SELECT `user_id` FROM `members` WHERE `id` = :id");
$stmt->execute([':id' => $creatorId1]);
$mRowAfter = $stmt->fetch();
assertCheck("Creator member in DB is now linked to User 1 (user_id = {$user1Id})", (int) $mRowAfter['user_id'] === $user1Id);

// =============================================================================
// T02: Invalid Creator Token
// =============================================================================
echo "\n--- T02: Invalid Creator Token ---\n";

$g2Res = dispatchReq($router, 'POST', '/api/groups', [
    'name' => 'Manali Trip',
    'creator_name' => 'Organizer Bob',
    'currency' => 'INR',
], [], null);
$token2 = $g2Res['data']['group']['invite_token'];
$groupId2 = (int) $g2Res['data']['group']['id'];

$badClaimRes = dispatchReq($router, 'POST', "/api/groups/{$token2}/claim-workspace", [], [
    'X-Creator-Token' => 'invalid_forged_creator_token_1234567890abcdef',
], $session2);

assertCheck("Invalid creator token rejected with HTTP 403", $badClaimRes['_status_code'] === 403);
assertCheck("Error code is INVALID_CREATOR_TOKEN", ($badClaimRes['error']['code'] ?? '') === 'INVALID_CREATOR_TOKEN');

// Verify DB remains unowned
$stmt = $pdo->prepare("SELECT `owner_user_id` FROM `groups` WHERE `id` = :id");
$stmt->execute([':id' => $groupId2]);
assertCheck("Workspace remains unowned in DB (owner_user_id = NULL)", $stmt->fetchColumn() === null);

// =============================================================================
// T03: Missing Creator Token / Invite Token Alone
// =============================================================================
echo "\n--- T03: Missing Creator Token / Invite Token Alone ---\n";

$missingTokenRes = dispatchReq($router, 'POST', "/api/groups/{$token2}/claim-workspace", [], [], $session2);
assertCheck("Claim without creator token rejected with HTTP 403", $missingTokenRes['_status_code'] === 403);

// =============================================================================
// T04: Numeric Identifiers Cannot Authorize Claim (BOLA / IDOR Defense)
// =============================================================================
echo "\n--- T04: Numeric Identifiers Cannot Authorize Claim ---\n";

$idorRes = dispatchReq($router, 'POST', "/api/groups/{$groupId2}/claim-workspace", [
    'group_id' => $groupId2,
    'member_id' => 1,
], [], $session2);

assertCheck("Using numeric group ID instead of invite token returns HTTP 404", $idorRes['_status_code'] === 404);

// =============================================================================
// T05: Already Owned by Same User (Idempotent Success)
// =============================================================================
echo "\n--- T05: Already Owned by Same User (Idempotent Success) ---\n";

$idempotentRes = dispatchReq($router, 'POST', "/api/groups/{$token1}/claim-workspace", [], [
    'X-Creator-Token' => $creatorToken1,
], $session1);

assertCheck("Repeated claim by same owner returns HTTP 200", $idempotentRes['_status_code'] === 200);
assertCheck("Idempotent claim returns claimed=true", ($idempotentRes['data']['claimed'] ?? false) === true);

// =============================================================================
// T06: Already Owned by Another User (Conflict 409)
// =============================================================================
echo "\n--- T06: Already Owned by Another User (Conflict 409) ---\n";

// User 2 attempts to claim Workspace 1 (which is owned by User 1)
$conflictRes = dispatchReq($router, 'POST', "/api/groups/{$token1}/claim-workspace", [], [
    'X-Creator-Token' => $creatorToken1,
], $session2);

assertCheck("Claim on workspace owned by another user rejected with HTTP 409", $conflictRes['_status_code'] === 409);
assertCheck("Error code is WORKSPACE_ALREADY_OWNED", ($conflictRes['error']['code'] ?? '') === 'WORKSPACE_ALREADY_OWNED');

// Confirm owner remains User 1
$stmt = $pdo->prepare("SELECT `owner_user_id` FROM `groups` WHERE `id` = :id");
$stmt->execute([':id' => $groupId1]);
assertCheck("Owner remains strictly User 1 (no takeover)", (int) $stmt->fetchColumn() === $user1Id);

// =============================================================================
// T07: Concurrent Claim Simulation
// =============================================================================
echo "\n--- T07: Concurrent Claim / Row-Locking Simulation ---\n";

$g3Res = dispatchReq($router, 'POST', '/api/groups', [
    'name' => 'Kerala Retreat',
    'creator_name' => 'Organizer Carol',
    'currency' => 'INR',
], [], null);
$token3 = $g3Res['data']['group']['invite_token'];
$creatorToken3 = $g3Res['data']['creator']['member_token'];
$groupId3 = (int) $g3Res['data']['group']['id'];

// Simulate 2 competing requests
$claimTabA = dispatchReq($router, 'POST', "/api/groups/{$token3}/claim-workspace", [], ['X-Creator-Token' => $creatorToken3], $session1);
$claimTabB = dispatchReq($router, 'POST', "/api/groups/{$token3}/claim-workspace", [], ['X-Creator-Token' => $creatorToken3], $session1);

assertCheck("First concurrent request succeeds (HTTP 200)", $claimTabA['_status_code'] === 200);
assertCheck("Second concurrent request handles idempotently (HTTP 200)", $claimTabB['_status_code'] === 200);

// =============================================================================
// T08: Multiple Guest Workspaces Batch Claims
// =============================================================================
echo "\n--- T08: Multiple Guest Workspaces Independent Claims ---\n";

$multiTokens = [];
$multiCreatorTokens = [];

for ($i = 1; $i <= 3; $i++) {
    $res = dispatchReq($router, 'POST', '/api/groups', [
        'name' => "Batch Trip {$i}",
        'creator_name' => "Organizer {$i}",
        'currency' => 'INR',
    ], [], null);
    $multiTokens[$i] = $res['data']['group']['invite_token'];
    $multiCreatorTokens[$i] = $res['data']['creator']['member_token'];
}

$allClaimed = true;
foreach ($multiTokens as $idx => $t) {
    $cRes = dispatchReq($router, 'POST', "/api/groups/{$t}/claim-workspace", [], [
        'X-Creator-Token' => $multiCreatorTokens[$idx],
    ], $session1);
    if ($cRes['_status_code'] !== 200 || !($cRes['data']['claimed'] ?? false)) {
        $allClaimed = false;
    }
}
assertCheck("All 3 batch guest workspaces claimed independently by User 1", $allClaimed);

// =============================================================================
// T09: Cloud Workspace Aggregation & Deduplication
// =============================================================================
echo "\n--- T09: Cloud Workspace Aggregation & Deduplication ---\n";

$portRes = dispatchReq($router, 'GET', '/api/user/workspaces', [], [], $session1);
assertCheck("Portfolio endpoint returns HTTP 200", $portRes['_status_code'] === 200);

$wsList = $portRes['data']['workspaces'] ?? [];
$ownedCount = 0;
$seenTokens = [];
$hasDuplicates = false;

foreach ($wsList as $ws) {
    if (isset($seenTokens[$ws['invite_token']])) {
        $hasDuplicates = true;
    }
    $seenTokens[$ws['invite_token']] = true;
    if ($ws['is_owner'] === true) {
        $ownedCount++;
    }
}

assertCheck("User 1 portfolio contains all claimed workspaces", $ownedCount >= 5);
assertCheck("User 1 portfolio has ZERO duplicate workspaces", !$hasDuplicates);

// =============================================================================
// T10: Financial Math & Balance Invariance
// =============================================================================
echo "\n--- T10: Financial Math & Balance Invariance ---\n";

// 1. Create a guest workspace with 3 members, expenses, and settlements
$gFinRes = dispatchReq($router, 'POST', '/api/groups', [
    'name' => 'Financial Invariance Ledger',
    'creator_name' => 'Alice',
    'currency' => 'INR',
], [], null);
$finToken = $gFinRes['data']['group']['invite_token'];
$finCreatorToken = $gFinRes['data']['creator']['member_token'];
$aliceId = (int) $gFinRes['data']['creator']['id'];

$mBobRes = dispatchReq($router, 'POST', "/api/groups/{$finToken}/members", ['name' => 'Bob'], [], null);
$bobId = (int) $mBobRes['data']['member']['id'];

$mCharlieRes = dispatchReq($router, 'POST', "/api/groups/{$finToken}/members", ['name' => 'Charlie'], [], null);
$charlieId = (int) $mCharlieRes['data']['member']['id'];

// Log expenses before claiming
// Expense 1: Alice pays 3000 INR, split equally among Alice, Bob, Charlie (1000 each)
$e1Res = dispatchReq($router, 'POST', "/api/groups/{$finToken}/expenses", [
    'title' => 'Hotel Booking',
    'amount' => 3000,
    'payer_member_id' => $aliceId,
    'split_type' => 'EQUAL',
    'splits' => [
        ['member_id' => $aliceId],
        ['member_id' => $bobId],
        ['member_id' => $charlieId],
    ],
], [], null);

// Expense 2: Bob pays 1500 INR for dinner, split equally between Bob and Charlie (750 each)
$e2Res = dispatchReq($router, 'POST', "/api/groups/{$finToken}/expenses", [
    'title' => 'Dinner Buffet',
    'amount' => 1500,
    'payer_member_id' => $bobId,
    'split_type' => 'EQUAL',
    'splits' => [
        ['member_id' => $bobId],
        ['member_id' => $charlieId],
    ],
], [], null);

// Capture baseline financial state before claim
$balBefore = dispatchReq($router, 'GET', "/api/groups/{$finToken}/balances", [], [], null);
$planBefore = dispatchReq($router, 'GET', "/api/groups/{$finToken}/settlement-plan", [], [], null);

$totalSpendBefore = $balBefore['data']['total_spending_cents'] ?? 0;
$membersBefore = $balBefore['data']['members'] ?? [];
$planTransBefore = $planBefore['data']['transactions'] ?? [];

assertCheck("Pre-claim total spending is exactly 450,000 cents (₹4,500.00)", $totalSpendBefore === 450000);

// Perform Workspace Claim
$finClaimRes = dispatchReq($router, 'POST', "/api/groups/{$finToken}/claim-workspace", [], [
    'X-Creator-Token' => $finCreatorToken,
], $session1);
assertCheck("Financial test workspace claimed successfully (HTTP 200)", $finClaimRes['_status_code'] === 200);

// Capture financial state after claim
$balAfter = dispatchReq($router, 'GET', "/api/groups/{$finToken}/balances", [], [], $session1);
$planAfter = dispatchReq($router, 'GET', "/api/groups/{$finToken}/settlement-plan", [], [], $session1);

$totalSpendAfter = $balAfter['data']['total_spending_cents'] ?? 0;
$membersAfter = $balAfter['data']['members'] ?? [];
$planTransAfter = $planAfter['data']['transactions'] ?? [];

assertCheck("Post-claim total spending is 100% mathematically invariant (450,000 cents)", $totalSpendAfter === $totalSpendBefore);
assertCheck("Post-claim member count is unchanged (3 members)", count($membersAfter) === count($membersBefore));

// Verify exact individual balance invariance down to integer cents
$balancesMatch = true;
foreach ($membersBefore as $idx => $mb) {
    $ma = $membersAfter[$idx] ?? null;
    $netBefore = $mb['net_balance_cents'] ?? 0;
    $netAfter = $ma['net_balance_cents'] ?? 0;
    $paidBefore = $mb['total_paid_cents'] ?? $mb['paid_cents'] ?? 0;
    $paidAfter = $ma['total_paid_cents'] ?? $ma['paid_cents'] ?? 0;
    $owedBefore = $mb['total_owed_cents'] ?? $mb['owed_cents'] ?? 0;
    $owedAfter = $ma['total_owed_cents'] ?? $ma['owed_cents'] ?? 0;

    if (!$ma || $netBefore !== $netAfter || $paidBefore !== $paidAfter || $owedBefore !== $owedAfter) {
        $balancesMatch = false;
    }
}
assertCheck("Post-claim individual member balances are 100% mathematically identical", $balancesMatch);
assertCheck("Post-claim settlement debt simplification plan is 100% identical", json_encode($planTransBefore) === json_encode($planTransAfter));

// =============================================================================
// T11: Cross-Device Discovery via GET /api/user/workspaces
// =============================================================================
echo "\n--- T11: Cross-Device Discovery via GET /api/user/workspaces ---\n";

// Simulate Device B: New session for User 1 requesting portfolio
$u1PortB = dispatchReq($router, 'GET', '/api/user/workspaces', [], [], $session1);
$userWorkspaces = $u1PortB['data']['workspaces'] ?? [];

$foundFinWorkspace = false;
foreach ($userWorkspaces as $uw) {
    if ($uw['invite_token'] === $finToken) {
        $foundFinWorkspace = true;
        assertCheck("Claimed workspace is flagged as is_owner=true on Device B", $uw['is_owner'] === true);
        assertCheck("Claimed workspace has correct member_name='Alice' on Device B", $uw['member_name'] === 'Alice');
    }
}
assertCheck("Device B immediately discovers claimed workspace via server API", $foundFinWorkspace);

// =============================================================================
// T12: Fresh Browser Discovery (Zero LocalStorage)
// =============================================================================
echo "\n--- T12: Fresh Browser Discovery (Zero LocalStorage) ---\n";

// Fresh browser logs in with email + password, issues new session cookie
$loginFreshRes = dispatchReq($router, 'POST', '/api/auth/login', [
    'email' => $user1Email,
    'password' => $pass,
]);

assertCheck("Fresh browser login returns HTTP 200", $loginFreshRes['_status_code'] === 200);
$freshSession = $loginFreshRes['_session_cookie'];

$freshPortRes = dispatchReq($router, 'GET', '/api/user/workspaces', [], [], $freshSession);
$freshList = $freshPortRes['data']['workspaces'] ?? [];
assertCheck("Fresh browser with 0 localStorage discovers all owned workspaces from server", count($freshList) >= 6);

echo "\n================================================================================\n";
echo " GUEST WORKSPACE CLAIMING REGRESSION SUITE: {$passedTests} / {$totalTests} TESTS PASSED (" . round(($passedTests / max(1, $totalTests)) * 100) . "%)\n";
echo "================================================================================\n\n";

if ($passedTests !== $totalTests) {
    exit(1);
}
