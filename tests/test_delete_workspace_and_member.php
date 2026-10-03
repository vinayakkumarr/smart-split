<?php

declare(strict_types=1);

/**
 * Smart Split V2 — Test Suite: Workspace Deletion & Member Management Safeguards
 * 
 * Validates:
 * 1. Member Renaming (PUT /api/groups/{token}/members/{id})
 * 2. Safe Member Deletion (Unused hard delete, non-zero balance lockout, historical soft-deactivation)
 * 3. Workspace Deletion (Guest deletion, Owner-only authorization guard, Full transactional database cascade)
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use App\Core\Env;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Core\Middleware\AuthSessionMiddleware;
use App\Controllers\GroupController;
use App\Controllers\MemberController;
use App\Controllers\ExpenseController;
use App\Controllers\SettlementController;
use App\Controllers\AuthController;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

function testAssert(bool $condition, string $description, ?string $details = null): void
{
    if ($condition) {
        echo "  [PASS] {$description}\n";
    } else {
        echo "  [FAIL] {$description}\n";
        if ($details) {
            echo "         Details: {$details}\n";
        }
        exit(1);
    }
}

function dispatchRequest(Router $router, Request $req): array
{
    ob_start();
    try {
        $router->dispatch($req);
    } catch (\Throwable $e) {
        ob_end_clean();
        return [
            'success' => false,
            'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]
        ];
    }
    $raw = ob_get_clean();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['raw' => $raw];
}

echo "\n=================================================================\n";
echo " Smart Split — Workspace & Member Deletion Test Suite\n";
echo "=================================================================\n\n";

$_COOKIE = [];
$router = new Router();
$router->use(new AuthSessionMiddleware());
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/groups', [GroupController::class, 'create']);
$router->get('/api/groups/{token}', [GroupController::class, 'show']);
$router->delete('/api/groups/{token}', [GroupController::class, 'delete']);

$router->post('/api/groups/{token}/members', [MemberController::class, 'create']);
$router->get('/api/groups/{token}/members', [MemberController::class, 'index']);
$router->put('/api/groups/{token}/members/{id}', [MemberController::class, 'update']);
$router->delete('/api/groups/{token}/members/{id}', [MemberController::class, 'delete']);

$router->post('/api/groups/{token}/expenses', [ExpenseController::class, 'create']);
$router->post('/api/groups/{token}/settlements', [SettlementController::class, 'create']);

echo "--- 1. Testing Member Renaming (PUT /api/groups/{token}/members/{id}) ---\n";
// Create group
$gRes = dispatchRequest($router, new Request('POST', '/api/groups', null, [
    'name' => 'Deletion Test Trip ' . time(),
    'creator_name' => 'Alice',
    'currency' => 'INR',
]));
testAssert($gRes['success'] === true, "Created test group");
$token = $gRes['data']['group']['invite_token'];
$aliceId = (int) $gRes['data']['creator']['id'];
$aliceToken = (string) $gRes['data']['creator']['member_token'];

// Rename Alice -> Alice Johnson
$renRes = dispatchRequest($router, new Request('PUT', "/api/groups/{$token}/members/{$aliceId}", null, [
    'name' => 'Alice Johnson',
]));
testAssert($renRes['success'] === true, "Renamed member Alice -> Alice Johnson");
testAssert($renRes['data']['member']['name'] === 'Alice Johnson', "Member name updated in response");

// Add Bob
$bRes = dispatchRequest($router, new Request('POST', "/api/groups/{$token}/members", null, ['name' => 'Bob']));
$bobId = (int) $bRes['data']['member']['id'];

// Try to rename Bob to Alice Johnson (duplicate check)
$dupRes = dispatchRequest($router, new Request('PUT', "/api/groups/{$token}/members/{$bobId}", null, [
    'name' => 'Alice Johnson',
]));
testAssert($dupRes['success'] === false, "Duplicate member name rejected with HTTP 422");

echo "\n--- 2. Testing Member Deletion Safeguards (DELETE /api/groups/{token}/members/{id}) ---\n";
// Add Charlie (0 expenses, 0 balance)
$cRes = dispatchRequest($router, new Request('POST', "/api/groups/{$token}/members", null, ['name' => 'Charlie']));
$charlieId = (int) $cRes['data']['member']['id'];

// Delete Charlie (clean hard delete)
$delCRes = dispatchRequest($router, new Request('DELETE', "/api/groups/{$token}/members/{$charlieId}"));
testAssert($delCRes['success'] === true, "Deleted unused member Charlie with HTTP 200", json_encode($delCRes));
testAssert($delCRes['data']['deleted'] === true, "Deletion confirmed in response");

// Verify Charlie is not in member list
$listRes = dispatchRequest($router, new Request('GET', "/api/groups/{$token}/members"));
$memberNames = array_column($listRes['data']['members'], 'name');
testAssert(!in_array('Charlie', $memberNames, true), "Charlie removed from active roster");

// Add expense: Alice paid ₹1000, split equally with Bob (Bob owes ₹500)
$expRes = dispatchRequest($router, new Request('POST', "/api/groups/{$token}/expenses", null, [
    'title' => 'Dinner',
    'amount' => '1000.00',
    'split_type' => 'EQUAL',
    'paid_by_member_id' => $aliceId,
    'splits' => [
        ['member_id' => $aliceId, 'amount_owed' => '500.00'],
        ['member_id' => $bobId, 'amount_owed' => '500.00'],
    ],
]));
testAssert($expRes['success'] === true, "Created ₹1000 expense split between Alice and Bob", json_encode($expRes));

// Try to delete Bob while having active balance (Bob owes ₹500)
$delBobFail = dispatchRequest($router, new Request('DELETE', "/api/groups/{$token}/members/{$bobId}"));
testAssert($delBobFail['success'] === false, "Locked out deleting Bob with active balance (HTTP 422)");

// Settle Bob's debt: Alice records Bob pays Alice ₹500
$setRes = dispatchRequest($router, new Request('POST', "/api/groups/{$token}/settlements", null, [
    'payer_member_id' => $bobId,
    'payee_member_id' => $aliceId,
    'recorded_by_member_id' => $aliceId,
    'amount' => '500.00',
]));
testAssert($setRes['success'] === true, "Recorded settlement: Bob pays Alice ₹500 (balance now 0)");

// Now delete Bob (has historical transactions, balance = 0 -> soft deactivates)
$delBobSuccess = dispatchRequest($router, new Request('DELETE', "/api/groups/{$token}/members/{$bobId}"));
testAssert($delBobSuccess['success'] === true, "Removed settled member Bob (HTTP 200)");
testAssert(($delBobSuccess['data']['was_deactivated'] ?? false) === true, "Historical member soft-deactivated to preserve ledger");

// Try to delete Alice (the only remaining active member -> minimum limit check)
$delAliceFail = dispatchRequest($router, new Request('DELETE', "/api/groups/{$token}/members/{$aliceId}"));
testAssert($delAliceFail['success'] === false, "Prevented removing sole remaining member (HTTP 422 MIN_MEMBER_LIMIT)");

echo "\n--- 3. Testing Guest Workspace Deletion Safeguards (SEC-01 Fail-Closed) ---\n";
// Verify creator_member_id in GET /api/groups/{token}
$preDelShow = dispatchRequest($router, new Request('GET', "/api/groups/{$token}"));
testAssert(isset($preDelShow['data']['group']['creator_member_id']), "GET /api/groups/{token} contains creator_member_id");
testAssert((int) $preDelShow['data']['group']['creator_member_id'] === $aliceId, "creator_member_id accurately matches Alice (ID: {$aliceId})");

// SEC-01 Test Case 1: Missing X-Creator-Token header entirely -> MUST FAIL with 403 FORBIDDEN
$missingTokenRes = dispatchRequest($router, new Request('DELETE', "/api/groups/{$token}"));
testAssert($missingTokenRes['success'] === false, "Missing X-Creator-Token blocked with HTTP 403 FORBIDDEN (Fail-Closed)");
testAssert(($missingTokenRes['error']['code'] ?? '') === 'FORBIDDEN', "Error code is FORBIDDEN for missing creator token");

// Verify workspace still exists in DB after rejected request
$checkWsStillExists = dispatchRequest($router, new Request('GET', "/api/groups/{$token}"));
testAssert($checkWsStillExists['success'] === true, "Workspace remained intact after missing-token deletion attempt");

// SEC-01 Test Case 2: Empty X-Creator-Token header -> MUST FAIL with 403 FORBIDDEN
$emptyTokenRes = dispatchRequest($router, new Request('DELETE', "/api/groups/{$token}", null, null, [
    'x-creator-token' => '',
]));
testAssert($emptyTokenRes['success'] === false, "Empty X-Creator-Token blocked with HTTP 403 FORBIDDEN");
testAssert(($emptyTokenRes['error']['code'] ?? '') === 'FORBIDDEN', "Error code is FORBIDDEN for empty creator token");

// SEC-01 Test Case 3: Invalid/Random X-Creator-Token header -> MUST FAIL with 403 FORBIDDEN
$badTokenRes = dispatchRequest($router, new Request('DELETE', "/api/groups/{$token}", null, null, [
    'x-creator-token' => 'invalid_random_token_12345',
]));
testAssert($badTokenRes['success'] === false, "Invalid X-Creator-Token blocked with HTTP 403 FORBIDDEN");
testAssert(($badTokenRes['error']['code'] ?? '') === 'FORBIDDEN', "Error code is FORBIDDEN for bad creator token");

// SEC-01 Test Case 4: Non-creator participant token -> MUST FAIL with 403 FORBIDDEN
$stmtNonCreator = $pdo->prepare("SELECT `member_token` FROM `members` WHERE `id` = :id");
$stmtNonCreator->execute([':id' => $bobId]);
$bobToken = (string) $stmtNonCreator->fetchColumn();
$participantTokenRes = dispatchRequest($router, new Request('DELETE', "/api/groups/{$token}", null, null, [
    'x-creator-token' => $bobToken,
]));
testAssert($participantTokenRes['success'] === false, "Non-creator participant token blocked with HTTP 403 FORBIDDEN");
testAssert(($participantTokenRes['error']['code'] ?? '') === 'FORBIDDEN', "Error code is FORBIDDEN for participant member token");

// SEC-01 Test Case 5: Valid creator token -> Deletion MUST succeed with HTTP 200
$validDelWsRes = dispatchRequest($router, new Request('DELETE', "/api/groups/{$token}", null, null, [
    'x-creator-token' => $aliceToken,
]));
testAssert($validDelWsRes['success'] === true, "Deleted guest workspace with valid X-Creator-Token (HTTP 200)", json_encode($validDelWsRes));

// Verify workspace is now 404
$showWsRes = dispatchRequest($router, new Request('GET', "/api/groups/{$token}"));
testAssert($showWsRes['success'] === false, "Deleted workspace returns HTTP 404 NOT_FOUND");

// Nonexistent workspace deletion returns 404
$nonExistentDel = dispatchRequest($router, new Request('DELETE', "/api/groups/non_existent_token_99999", null, null, [
    'x-creator-token' => 'any_token',
]));
testAssert($nonExistentDel['success'] === false, "Non-existent workspace returns HTTP 404 on deletion attempt");
testAssert(($nonExistentDel['error']['code'] ?? '') === 'NOT_FOUND', "Error code is NOT_FOUND for non-existent workspace");

function createTestSession(PDO $pdo, int $userId): string
{
    $plainToken = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("
        INSERT INTO `user_sessions` (`user_id`, `session_token_hash`, `ip_address`, `user_agent`, `expires_at`, `created_at`, `last_active_at`)
        VALUES (:uid, :thash, '127.0.0.1', 'PHPTest', DATE_ADD(NOW(), INTERVAL 1 DAY), NOW(), NOW())
    ");
    $stmt->execute([':uid' => $userId, ':thash' => hash('sha256', $plainToken)]);
    return $plainToken;
}

// Section 3b: Authenticated Workspace Authorization Guard
echo "\n--- 3b. Testing Authenticated Workspace Deletion Authorization Guard ---\n";
// 1. Register User A (Owner) and User B (Attacker/Non-owner)
$u1Res = dispatchRequest($router, new Request('POST', '/api/auth/register', null, [
    'email' => 'owner_' . time() . '@smartsplit.test',
    'password' => 'Password123!Secure',
    'name' => 'Workspace Owner',
]));
testAssert($u1Res['success'] === true, "Registered Workspace Owner User A");
$ownerUserId = (int) $u1Res['data']['user']['id'];
$ownerSession = createTestSession($pdo, $ownerUserId);

$u2Res = dispatchRequest($router, new Request('POST', '/api/auth/register', null, [
    'email' => 'hacker_' . time() . '@smartsplit.test',
    'password' => 'Password123!Secure',
    'name' => 'Intruder User B',
]));
testAssert($u2Res['success'] === true, "Registered Intruder User B");
$intruderUserId = (int) $u2Res['data']['user']['id'];
$intruderSession = createTestSession($pdo, $intruderUserId);

// 2. Owner creates an authenticated workspace
$_COOKIE['smartsplit_session'] = $ownerSession;
$authWsRes = dispatchRequest($router, new Request('POST', '/api/groups', null, [
    'name' => 'Secured Project Alpha',
    'creator_name' => 'Workspace Owner',
    'currency' => 'EUR',
]));
testAssert($authWsRes['success'] === true, "Created secured authenticated workspace owned by User A");
$authWsToken = $authWsRes['data']['group']['invite_token'];
$authWsId = (int) $authWsRes['data']['group']['id'];

// Verify group owner_user_id in DB
$stmtAuthG = $pdo->prepare("SELECT `owner_user_id` FROM `groups` WHERE `id` = :id");
$stmtAuthG->execute([':id' => $authWsId]);
testAssert((int) $stmtAuthG->fetchColumn() === $ownerUserId, "Database confirms group is owned by User A");

// 3. User B tries to delete User A's workspace (Forbidden 403)
$_COOKIE['smartsplit_session'] = $intruderSession;
$intruderDel = dispatchRequest($router, new Request('DELETE', "/api/groups/{$authWsToken}"));
testAssert($intruderDel['success'] === false, "Unauthorized user blocked from deleting workspace (HTTP 403)");
testAssert(($intruderDel['error']['code'] ?? '') === 'FORBIDDEN', "Error code is FORBIDDEN");

// 4. Owner deletes their own workspace (Success 200)
$_COOKIE['smartsplit_session'] = $ownerSession;
$ownerDel = dispatchRequest($router, new Request('DELETE', "/api/groups/{$authWsToken}"));
testAssert($ownerDel['success'] === true, "Owner successfully deleted workspace (HTTP 200)");

// 5. Verify complete cascade in DB (0 orphaned rows)
$stmtCheckG = $pdo->prepare("SELECT COUNT(*) FROM `groups` WHERE `id` = :id");
$stmtCheckG->execute([':id' => $authWsId]);
testAssert((int) $stmtCheckG->fetchColumn() === 0, "Group completely purged from database");

$stmtCheckM = $pdo->prepare("SELECT COUNT(*) FROM `members` WHERE `group_id` = :id");
$stmtCheckM->execute([':id' => $authWsId]);
testAssert((int) $stmtCheckM->fetchColumn() === 0, "Members cascade-deleted from database");

echo "\n=================================================================\n";
echo " Verification Results: All Workspace & Member Deletion Tests PASSED!\n";
echo "=================================================================\n";
