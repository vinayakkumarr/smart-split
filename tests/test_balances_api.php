<?php

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Env;
use App\Core\Request;
use App\Core\Router;
use App\Controllers\GroupController;
use App\Controllers\MemberController;
use App\Controllers\ExpenseController;
use App\Controllers\BalanceController;


echo "=====================================================\n";
echo " Smart Split – Net Balance & Ledger Engine Tests\n";
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
$router->post('/api/groups/{token}/members', [MemberController::class, 'create']);
$router->post('/api/groups/{token}/expenses', [ExpenseController::class, 'create']);
$router->get('/api/groups/{token}/balances', [BalanceController::class, 'index']);
$router->get('/api/groups/{token}/bilateral-balances', [BalanceController::class, 'bilateralBalances']);
$router->get('/api/groups/{token}/members/{memberId}/ledger', [BalanceController::class, 'memberLedger']);

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

// 1. Create Group & Members
$groupRes = dispatch($router, new Request('POST', '/api/groups', null, [
    'name' => 'Goa Trip ' . time(),
    'creator_name' => 'Alice',
    'currency' => 'INR',
]));
$token = $groupRes['data']['group']['invite_token'];
$aliceId = (int) $groupRes['data']['creator']['id'];

$bobRes = dispatch($router, new Request('POST', "/api/groups/{$token}/members", null, ['name' => 'Bob']));
$bobId = (int) $bobRes['data']['member']['id'];

$charlieRes = dispatch($router, new Request('POST', "/api/groups/{$token}/members", null, ['name' => 'Charlie']));
$charlieId = (int) $charlieRes['data']['member']['id'];

// 2. Expense 1: Alice pays ₹60 (6000 paise) for Alice, Bob, Charlie equally (2000 paise each)
dispatch($router, new Request('POST', "/api/groups/{$token}/expenses", null, [
    'title' => 'Dinner',
    'total_amount_cents' => 6000,
    'payer_member_id' => $aliceId,
    'split_type' => 'EQUAL',
    'split_members' => [$aliceId, $bobId, $charlieId],
]));

// 3. Expense 2: Bob pays ₹30 (3000 paise) for Bob and Charlie equally (1500 paise each)
dispatch($router, new Request('POST', "/api/groups/{$token}/expenses", null, [
    'title' => 'Taxi',
    'total_amount_cents' => 3000,
    'payer_member_id' => $bobId,
    'split_type' => 'EQUAL',
    'split_members' => [$bobId, $charlieId],
]));

// 4. Query Balances: GET /api/groups/{token}/balances
$balRes = dispatch($router, new Request('GET', "/api/groups/{$token}/balances"));
assertTest($balRes['success'] === true, "GET /api/groups/{token}/balances returns success");
assertTest($balRes['data']['total_spending_cents'] === 9000, "Total group spend is ₹90.00 (9000 paise)");
assertTest($balRes['data']['zero_sum_verified'] === true, "Zero-sum invariant verified: true");

$members = [];
foreach ($balRes['data']['members'] as $m) {
    $members[$m['member_id']] = $m;
}

// Alice: Paid 6000, Owed 2000 -> Net = +4000 (CREDITOR)
assertTest($members[$aliceId]['total_paid_cents'] === 6000, "Alice total paid = 6000 paise");
assertTest($members[$aliceId]['total_owed_cents'] === 2000, "Alice total owed = 2000 paise");
assertTest($members[$aliceId]['net_balance_cents'] === 4000, "Alice net balance = +4000 paise");
assertTest($members[$aliceId]['status'] === 'CREDITOR', "Alice status is CREDITOR");

// Bob: Paid 3000, Owed 3500 -> Net = -500 (DEBTOR)
assertTest($members[$bobId]['total_paid_cents'] === 3000, "Bob total paid = 3000 paise");
assertTest($members[$bobId]['total_owed_cents'] === 3500, "Bob total owed = 3500 paise");
assertTest($members[$bobId]['net_balance_cents'] === -500, "Bob net balance = -500 paise");
assertTest($members[$bobId]['status'] === 'DEBTOR', "Bob status is DEBTOR");

// Charlie: Paid 0, Owed 3500 -> Net = -3500 (DEBTOR)
assertTest($members[$charlieId]['total_paid_cents'] === 0, "Charlie total paid = 0 paise");
assertTest($members[$charlieId]['total_owed_cents'] === 3500, "Charlie total owed = 3500 paise");
assertTest($members[$charlieId]['net_balance_cents'] === -3500, "Charlie net balance = -3500 paise");
assertTest($members[$charlieId]['status'] === 'DEBTOR', "Charlie status is DEBTOR");

// Net sum verification: 4000 - 500 - 3500 = 0
$calculatedNetSum = $members[$aliceId]['net_balance_cents'] + $members[$bobId]['net_balance_cents'] + $members[$charlieId]['net_balance_cents'];
assertTest($calculatedNetSum === 0, "Sum of individual net balances exactly equals 0 paise");

// 5. Query Itemized Ledger: GET /api/groups/{token}/members/{aliceId}/ledger
$ledgerRes = dispatch($router, new Request('GET', "/api/groups/{$token}/members/{$aliceId}/ledger"));
assertTest($ledgerRes['success'] === true, "GET /api/groups/{token}/members/{id}/ledger returns success");
assertTest(count($ledgerRes['data']['ledger']['paid_expenses']) === 1, "Alice paid expenses list has 1 item");
assertTest(count($ledgerRes['data']['ledger']['consumed_expenses']) === 1, "Alice consumed expenses list has 1 item");

// 6. Query 1-on-1 Bilateral Balances: GET /api/groups/{token}/bilateral-balances
$bilateralRes = dispatch($router, new Request('GET', "/api/groups/{$token}/bilateral-balances"));
assertTest($bilateralRes['success'] === true, "GET /api/groups/{token}/bilateral-balances returns success");
$pairs = $bilateralRes['data']['pairs'] ?? [];
assertTest(count($pairs) === 3, "Bilateral pairs calculated for 3 members (3 unique pairs)");

echo "\n=====================================================\n";
echo " All {$testsPassed} / {$totalTests} Net Balance & Ledger Engine Tests PASSED!\n";
echo "=====================================================\n";
