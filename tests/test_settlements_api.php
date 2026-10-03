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
use App\Controllers\SettlementController;


echo "=====================================================\n";
echo " Smart Split – Settlement Recording API Tests\n";
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
$router->get('/api/groups/{token}/settlement-plan', [BalanceController::class, 'settlementPlan']);
$router->post('/api/groups/{token}/settlements', [SettlementController::class, 'create']);
$router->post('/api/groups/{token}/settlements/{id}/confirm', [SettlementController::class, 'confirm']);
$router->post('/api/groups/{token}/settlements/{id}/dispute', [SettlementController::class, 'dispute']);
$router->post('/api/groups/{token}/settlements/{id}/reverse', [SettlementController::class, 'reverse']);
$router->get('/api/groups/{token}/settlements', [SettlementController::class, 'index']);
$router->delete('/api/groups/{token}/settlements/{id}', [SettlementController::class, 'delete']);

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

// 1. Setup Group & Members
$groupRes = dispatch($router, new Request('POST', '/api/groups', null, [
    'name' => 'Goa Settle Test ' . time(),
    'creator_name' => 'Alice',
    'currency' => 'INR',
]));
$token = $groupRes['data']['group']['invite_token'];
$aliceId = (int) $groupRes['data']['creator']['id'];

$bobRes = dispatch($router, new Request('POST', "/api/groups/{$token}/members", null, ['name' => 'Bob']));
$bobId = (int) $bobRes['data']['member']['id'];

$charlieRes = dispatch($router, new Request('POST', "/api/groups/{$token}/members", null, ['name' => 'Charlie']));
$charlieId = (int) $charlieRes['data']['member']['id'];

// 2. Log Expenses
// Alice pays ₹60 (6000 paise) for all 3
dispatch($router, new Request('POST', "/api/groups/{$token}/expenses", null, [
    'title' => 'Dinner',
    'total_amount_cents' => 6000,
    'payer_member_id' => $aliceId,
    'split_type' => 'EQUAL',
    'split_members' => [$aliceId, $bobId, $charlieId],
]));

// Bob pays ₹30 (3000 paise) for Bob & Charlie
dispatch($router, new Request('POST', "/api/groups/{$token}/expenses", null, [
    'title' => 'Taxi',
    'total_amount_cents' => 3000,
    'payer_member_id' => $bobId,
    'split_type' => 'EQUAL',
    'split_members' => [$bobId, $charlieId],
]));

// Current state: Alice +4000, Bob -500, Charlie -3500

// 3. Record Settlement 1: Charlie pays Alice ₹35.00 (3500 paise)
$settle1Res = dispatch($router, new Request('POST', "/api/groups/{$token}/settlements", null, [
    'payer_id' => $charlieId,
    'payee_id' => $aliceId,
    'amount' => '35.00',
    'notes' => 'Google Pay UPI',
]));

assertTest($settle1Res['success'] === true, "POST /api/groups/{token}/settlements creates settlement 1");
assertTest($settle1Res['data']['settlement']['amount_cents'] === 3500, "Settlement amount stored as 3500 paise");

$settle1Id = (int) $settle1Res['data']['settlement']['id'];

// Creditor (Alice) confirms receipt
$conf1Res = dispatch($router, new Request('POST', "/api/groups/{$token}/settlements/{$settle1Id}/confirm", null, [
    'confirmed_by_member_id' => $aliceId,
]));
assertTest($conf1Res['success'] === true, "Creditor confirms settlement 1 receipt");

// Check updated balances
$bal1 = dispatch($router, new Request('GET', "/api/groups/{$token}/balances"));
$members1 = [];
foreach ($bal1['data']['members'] as $m) {
    $members1[$m['member_id']] = $m;
}

assertTest($members1[$charlieId]['net_balance_cents'] === 0, "Charlie net balance is now 0 paise (SETTLED)");
assertTest($members1[$charlieId]['status'] === 'SETTLED', "Charlie status is SETTLED");
assertTest($members1[$aliceId]['net_balance_cents'] === 500, "Alice net balance is reduced to +500 paise");
assertTest($members1[$bobId]['net_balance_cents'] === -500, "Bob net balance remains -500 paise");

// Check remaining settlement plan
$plan1 = dispatch($router, new Request('GET', "/api/groups/{$token}/settlement-plan"));
assertTest($plan1['data']['total_transactions'] === 1, "Only 1 remaining transaction in settlement plan");
assertTest($plan1['data']['transactions'][0]['from_member_id'] === $bobId, "Remaining transaction is from Bob");
assertTest($plan1['data']['transactions'][0]['to_member_id'] === $aliceId, "Remaining transaction is to Alice");
assertTest($plan1['data']['transactions'][0]['amount_cents'] === 500, "Remaining transaction amount is ₹5.00");

// 4. Record Settlement 2: Alice records that Bob paid ₹5.00 (500 paise) in cash (Creditor Fast-Path)
$settle2Res = dispatch($router, new Request('POST', "/api/groups/{$token}/settlements", null, [
    'payer_id' => $bobId,
    'payee_id' => $aliceId,
    'amount_cents' => 500,
    'recorded_by_member_id' => $aliceId,
    'payment_method' => 'CASH',
    'notes' => 'Cash',
]));

assertTest($settle2Res['success'] === true, "POST /api/groups/{token}/settlements creates settlement 2");
$settle2Id = (int) $settle2Res['data']['settlement']['id'];

// Check all balances are 0
$bal2 = dispatch($router, new Request('GET', "/api/groups/{$token}/balances"));
foreach ($bal2['data']['members'] as $m) {
    assertTest($m['net_balance_cents'] === 0 && $m['status'] === 'SETTLED', "Member {$m['name']} is completely SETTLED (0 paise)");
}

$plan2 = dispatch($router, new Request('GET', "/api/groups/{$token}/settlement-plan"));
assertTest($plan2['data']['total_transactions'] === 0, "Settlement plan is completely clear (0 transactions remaining)");

// 5. Query All Settlements: GET /api/groups/{token}/settlements
$listSettle = dispatch($router, new Request('GET', "/api/groups/{$token}/settlements"));
assertTest($listSettle['success'] === true && $listSettle['data']['total_count'] === 2, "GET /api/groups/{token}/settlements lists 2 settlements");

// 6. Delete Settlement 2: DELETE /api/groups/{token}/settlements/{id}
$delRes = dispatch($router, new Request('DELETE', "/api/groups/{$token}/settlements/{$settle2Id}"));
if (!isset($delRes['success']) || !$delRes['success']) {
    echo "DEBUG delRes: " . print_r($delRes, true) . "\n";
}
assertTest(($delRes['success'] ?? false) === true && ($delRes['data']['deleted'] ?? false) === true, "DELETE /api/groups/{token}/settlements/{id} soft-deletes settlement");

// Check that Bob's debt of -500 is restored
$bal3 = dispatch($router, new Request('GET', "/api/groups/{$token}/balances"));
$members3 = [];
foreach ($bal3['data']['members'] as $m) {
    $members3[$m['member_id']] = $m;
}
assertTest($members3[$bobId]['net_balance_cents'] === -500, "Soft deletion restores Bob's debt of -500 paise");

echo "\n=====================================================\n";
echo " All {$testsPassed} / {$totalTests} Settlement API Tests PASSED!\n";
echo "=====================================================\n";
