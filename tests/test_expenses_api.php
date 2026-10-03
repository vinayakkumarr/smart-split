<?php

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Env;
use App\Core\Request;
use App\Core\Router;
use App\Core\Database;
use App\Controllers\GroupController;
use App\Controllers\MemberController;
use App\Controllers\ExpenseController;


echo "=====================================================\n";
echo " Smart Split – Expense Logging & ACID Pipeline Tests\n";
echo "=====================================================\n";

$testsPassed = 0;
$totalTests = 0;

function assertTest(bool $condition, string $testName): void
{
    global $testsPassed, $totalTests;
    $totalTests++;
    if ($condition) {
        echo "  [PASS] {$testName}\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] {$testName}\n";
        exit(1);
    }
}

$router = new Router();
$router->post('/api/groups', [GroupController::class, 'create']);
$router->get('/api/groups/{token}', [GroupController::class, 'show']);
$router->post('/api/groups/{token}/members', [MemberController::class, 'create']);
$router->post('/api/groups/{token}/expenses', [ExpenseController::class, 'create']);
$router->get('/api/groups/{token}/expenses', [ExpenseController::class, 'index']);
$router->get('/api/groups/{token}/expenses/{id}', [ExpenseController::class, 'show']);
$router->delete('/api/groups/{token}/expenses/{id}', [ExpenseController::class, 'delete']);

function dispatch(Router $router, Request $req): array
{
    ob_start();
    try {
        $router->dispatch($req);
    } catch (\Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    $raw = ob_get_clean();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['raw' => $raw];
}

// 1. Setup Group & 3 Members
$groupRes = dispatch($router, new Request('POST', '/api/groups', null, [
    'name' => 'Dinner & Movies ' . time(),
    'creator_name' => 'Alice',
    'currency' => 'INR',
]));
$token = $groupRes['data']['group']['invite_token'];
$aliceId = (int) $groupRes['data']['creator']['id'];

$bobRes = dispatch($router, new Request('POST', "/api/groups/{$token}/members", null, ['name' => 'Bob']));
$bobId = (int) $bobRes['data']['member']['id'];

$charlieRes = dispatch($router, new Request('POST', "/api/groups/{$token}/members", null, ['name' => 'Charlie']));
$charlieId = (int) $charlieRes['data']['member']['id'];

assertTest($aliceId > 0 && $bobId > 0 && $charlieId > 0, "Created group with Alice, Bob, Charlie");

// 2. Test Equal Split Expense (₹100 = 10000 paise by Alice)
$exp1Res = dispatch($router, new Request('POST', "/api/groups/{$token}/expenses", null, [
    'title' => 'Dinner Buffet',
    'amount' => '100.00',
    'payer_member_id' => $aliceId,
    'split_type' => 'EQUAL',
    'split_members' => [$aliceId, $bobId, $charlieId],
    'expense_date' => '2026-09-21',
]));

assertTest($exp1Res['success'] === true, "POST /api/groups/{token}/expenses creates Equal split");
assertTest($exp1Res['data']['expense']['total_amount_cents'] === 10000, "Expense amount stored as 10000 paise");
assertTest(count($exp1Res['data']['expense']['splits']) === 3, "Expense has 3 split rows");

$splitsSum = array_sum(array_column($exp1Res['data']['expense']['splits'], 'amount_owed_cents'));
assertTest($splitsSum === 10000, "Equal split owed amounts sum precisely to 10000 paise");

$exp1Id = (int) $exp1Res['data']['expense']['id'];

// 3. Test Multi-Payer Percentage Split (₹60 = 6000 paise: Alice 4000, Bob 2000; Splits: 50%, 25%, 25%)
$exp2Res = dispatch($router, new Request('POST', "/api/groups/{$token}/expenses", null, [
    'title' => 'Groceries',
    'total_amount_cents' => 6000,
    'split_type' => 'PERCENTAGE',
    'payers' => [
        ['member_id' => $aliceId, 'amount_paid_cents' => 4000],
        ['member_id' => $bobId, 'amount_paid_cents' => 2000],
    ],
    'splits' => [
        $aliceId => 50.0,
        $bobId => 25.0,
        $charlieId => 25.0,
    ],
    'expense_date' => '2026-09-21',
]));

assertTest($exp2Res['success'] === true, "Multi-payer Percentage split created successfully");
assertTest(count($exp2Res['data']['expense']['payers']) === 2, "Expense has 2 payer rows");
$payerSum = array_sum(array_column($exp2Res['data']['expense']['payers'], 'amount_paid_cents'));
assertTest($payerSum === 6000, "Payers sum precisely equals 6000 paise");

// 4. Test ACID Rollback on Payer Mismatch
$countBefore = (int) Database::getConnection()->query("SELECT COUNT(*) FROM expenses WHERE group_id = {$groupRes['data']['group']['id']}")->fetchColumn();

$mismatchRes = dispatch($router, new Request('POST', "/api/groups/{token}/expenses", null, [
    'title' => 'Bad Expense',
    'total_amount_cents' => 5000,
    'payers' => [
        ['member_id' => $aliceId, 'amount_paid_cents' => 4000], // 1000 shortfall
    ],
    'split_type' => 'EQUAL',
    'split_members' => [$aliceId, $bobId],
]));

$countAfter = (int) Database::getConnection()->query("SELECT COUNT(*) FROM expenses WHERE group_id = {$groupRes['data']['group']['id']}")->fetchColumn();

assertTest($mismatchRes['success'] === false, "Payer shortfall correctly returns error response (success=false)");
assertTest($countBefore === $countAfter, "ACID Rollback confirmed: 0 orphaned rows created in database");

// 5. Test GET /api/groups/{token}/expenses
$listRes = dispatch($router, new Request('GET', "/api/groups/{$token}/expenses"));
assertTest($listRes['success'] === true, "GET /api/groups/{token}/expenses returns success");
assertTest($listRes['data']['total_count'] === 2, "Returns 2 active expenses");

// 6. Test GET /api/groups/{token}/expenses/{id}
$showRes = dispatch($router, new Request('GET', "/api/groups/{$token}/expenses/{$exp1Id}"));
assertTest($showRes['success'] === true, "GET /api/groups/{token}/expenses/{id} returns single expense");
assertTest($showRes['data']['expense']['title'] === 'Dinner Buffet', "Expense title matches");

// 7. Test DELETE /api/groups/{token}/expenses/{id} (Soft Delete)
$delRes = dispatch($router, new Request('DELETE', "/api/groups/{$token}/expenses/{$exp1Id}"));
assertTest($delRes['success'] === true && $delRes['data']['deleted'] === true, "DELETE /api/groups/{token}/expenses/{id} soft-deletes expense");

$listAfterDel = dispatch($router, new Request('GET', "/api/groups/{$token}/expenses"));
assertTest(($listAfterDel['data']['total_count'] ?? 0) === 1, "Soft-deleted expense is excluded from active listings");

echo "\n=====================================================\n";
echo " All {$testsPassed} / {$totalTests} Expense & ACID Pipeline Tests PASSED!\n";
echo "=====================================================\n";
