<?php

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Env;
use App\Core\Request;
use App\Core\Router;
use App\Services\SettlementEngine;
use App\Controllers\GroupController;
use App\Controllers\MemberController;
use App\Controllers\ExpenseController;
use App\Controllers\BalanceController;


echo "=====================================================\n";
echo " Smart Split – Debt Simplification Graph Engine Tests\n";
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

// 1. Test Circular Debt Scenario (All nets = 0)
echo "\n--- 1. Testing Circular Debt Scenario ---\n";
$circularBalances = [
    ['member_id' => 1, 'name' => 'Alice', 'net_balance_cents' => 0],
    ['member_id' => 2, 'name' => 'Bob', 'net_balance_cents' => 0],
    ['member_id' => 3, 'name' => 'Charlie', 'net_balance_cents' => 0],
];
$circularPlan = SettlementEngine::simplifyDebts($circularBalances);
assertTest($circularPlan['total_transactions'] === 0, "Circular debts resolve to 0 transactions");
assertTest(empty($circularPlan['transactions']), "Transaction list is empty for zero balances");

// 2. Test 3-Person Scenario (Alice: +4000, Bob: -500, Charlie: -3500)
echo "\n--- 2. Testing 3-Person Scenario ---\n";
$threePersonBalances = [
    ['member_id' => 1, 'name' => 'Alice', 'net_balance_cents' => 4000],
    ['member_id' => 2, 'name' => 'Bob', 'net_balance_cents' => -500],
    ['member_id' => 3, 'name' => 'Charlie', 'net_balance_cents' => -3500],
];
$threePersonPlan = SettlementEngine::simplifyDebts($threePersonBalances, 'INR');

assertTest($threePersonPlan['total_transactions'] === 2, "3-person scenario generates exactly 2 transactions (N-1)");
assertTest($threePersonPlan['total_settlement_volume_cents'] === 4000, "Total settlement volume is 4000 paise (₹40.00)");

// First transaction: Charlie (largest debt: 3500) pays Alice (largest credit: 4000) 3500
$tx1 = $threePersonPlan['transactions'][0];
assertTest($tx1['from_member_id'] === 3 && $tx1['to_member_id'] === 1 && $tx1['amount_cents'] === 3500, "Tx 1: Charlie pays Alice ₹35.00");

// Second transaction: Bob (remaining debt: 500) pays Alice (remaining credit: 500) 500
$tx2 = $threePersonPlan['transactions'][1];
assertTest($tx2['from_member_id'] === 2 && $tx2['to_member_id'] === 1 && $tx2['amount_cents'] === 500, "Tx 2: Bob pays Alice ₹5.00");

// 3. Test 5-Person Complex Multi-Party Scenario
echo "\n--- 3. Testing 5-Person Complex Graph Scenario ---\n";
$fivePersonBalances = [
    ['member_id' => 10, 'name' => 'User A', 'net_balance_cents' => 6000],
    ['member_id' => 20, 'name' => 'User B', 'net_balance_cents' => 2000],
    ['member_id' => 30, 'name' => 'User C', 'net_balance_cents' => -3000],
    ['member_id' => 40, 'name' => 'User D', 'net_balance_cents' => -4000],
    ['member_id' => 50, 'name' => 'User E', 'net_balance_cents' => -1000],
];
$fivePersonPlan = SettlementEngine::simplifyDebts($fivePersonBalances, 'INR');

assertTest($fivePersonPlan['total_transactions'] <= 4, "5-person graph simplifies to <= 4 transactions (N-1 bound)");
assertTest($fivePersonPlan['total_settlement_volume_cents'] === 8000, "Total settlement volume matches total debt (8000 paise)");

// Verify mathematical conservation of cash
$simulatedBalances = [10 => 0, 20 => 0, 30 => 0, 40 => 0, 50 => 0];
foreach ($fivePersonPlan['transactions'] as $tx) {
    $simulatedBalances[$tx['from_member_id']] -= $tx['amount_cents'];
    $simulatedBalances[$tx['to_member_id']] += $tx['amount_cents'];
}

assertTest($simulatedBalances[10] === 6000, "Simulated transfers give User A +6000");
assertTest($simulatedBalances[20] === 2000, "Simulated transfers give User B +2000");
assertTest($simulatedBalances[30] === -3000, "Simulated transfers give User C -3000");
assertTest($simulatedBalances[40] === -4000, "Simulated transfers give User D -4000");
assertTest($simulatedBalances[50] === -1000, "Simulated transfers give User E -1000");

// 4. Test Live API Endpoint: GET /api/groups/{token}/settlement-plan
echo "\n--- 4. Testing Live API Endpoint ---\n";
$router = new Router();
$router->post('/api/groups', [GroupController::class, 'create']);
$router->post('/api/groups/{token}/members', [MemberController::class, 'create']);
$router->post('/api/groups/{token}/expenses', [ExpenseController::class, 'create']);
$router->get('/api/groups/{token}/settlement-plan', [BalanceController::class, 'settlementPlan']);

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

$groupRes = dispatch($router, new Request('POST', '/api/groups', null, [
    'name' => 'Goa Trip Graph ' . time(),
    'creator_name' => 'Alice',
    'currency' => 'INR',
]));
$token = $groupRes['data']['group']['invite_token'];
$aliceId = (int) $groupRes['data']['creator']['id'];

$bobRes = dispatch($router, new Request('POST', "/api/groups/{$token}/members", null, ['name' => 'Bob']));
$bobId = (int) $bobRes['data']['member']['id'];

$charlieRes = dispatch($router, new Request('POST', "/api/groups/{$token}/members", null, ['name' => 'Charlie']));
$charlieId = (int) $charlieRes['data']['member']['id'];

// Alice pays ₹60 for Alice, Bob, Charlie (20 each)
dispatch($router, new Request('POST', "/api/groups/{$token}/expenses", null, [
    'title' => 'Dinner',
    'total_amount_cents' => 6000,
    'payer_member_id' => $aliceId,
    'split_type' => 'EQUAL',
    'split_members' => [$aliceId, $bobId, $charlieId],
]));

// Bob pays ₹30 for Bob, Charlie (15 each)
dispatch($router, new Request('POST', "/api/groups/{$token}/expenses", null, [
    'title' => 'Taxi',
    'total_amount_cents' => 3000,
    'payer_member_id' => $bobId,
    'split_type' => 'EQUAL',
    'split_members' => [$bobId, $charlieId],
]));

$planRes = dispatch($router, new Request('GET', "/api/groups/{$token}/settlement-plan"));
assertTest($planRes['success'] === true, "GET /api/groups/{token}/settlement-plan returns success");
assertTest($planRes['data']['total_transactions'] === 2, "API settlement plan has 2 transactions");
assertTest(count($planRes['data']['transactions']) === 2, "API returned 2 transactions array");

// 5. Test UPI ID Resolution and Member UPI Update Validation
echo "\n--- 5. Testing UPI ID Resolution & Validation ---\n";
$router->put('/api/groups/{token}/members/{id}', [MemberController::class, 'update']);

// Test invalid UPI ID rejected with 422
$badUpiRes = dispatch($router, new Request('PUT', "/api/groups/{$token}/members/{$aliceId}", null, [
    'name' => 'Alice',
    'upi_id' => 'invalid-upi-no-at',
]));
assertTest($badUpiRes['success'] === false && ($badUpiRes['error']['code'] ?? '') === 'INVALID_UPI_ID', "Invalid UPI ID rejected with INVALID_UPI_ID (422)");

// Test valid UPI ID accepted and persisted
$goodUpiRes = dispatch($router, new Request('PUT', "/api/groups/{$token}/members/{$aliceId}", null, [
    'name' => 'Alice',
    'upi_id' => 'alice.fintech@okaxis',
]));
assertTest($goodUpiRes['success'] === true, "Valid UPI ID successfully updated for Alice");
assertTest(($goodUpiRes['data']['member']['upi_id'] ?? '') === 'alice.fintech@okaxis', "Updated member payload contains correct upi_id");

// UPI-01: 80-character valid VPA accepted and persisted
$vpa80 = str_repeat('a', 70) . '@icicibank'; // 70 + 1 + 9 = 80 chars
$good80Res = dispatch($router, new Request('PUT', "/api/groups/{$token}/members/{$aliceId}", null, [
    'name' => 'Alice',
    'upi_id' => $vpa80,
]));
assertTest($good80Res['success'] === true, "Exactly 80-character valid UPI ID accepted for Alice");
assertTest(($good80Res['data']['member']['upi_id'] ?? '') === $vpa80, "Persisted 80-char upi_id matches input exactly");

// UPI-01: 81-character VPA rejected with 422 INVALID_UPI_ID
$vpa81 = str_repeat('a', 71) . '@icicibank'; // 71 + 1 + 9 = 81 chars
$bad81Res = dispatch($router, new Request('PUT', "/api/groups/{$token}/members/{$aliceId}", null, [
    'name' => 'Alice',
    'upi_id' => $vpa81,
]));
assertTest($bad81Res['success'] === false && ($bad81Res['error']['code'] ?? '') === 'INVALID_UPI_ID', "81-character UPI ID rejected with INVALID_UPI_ID (422)");

// UPI-01: Reset back to alice.fintech@okaxis for settlement plan test
dispatch($router, new Request('PUT', "/api/groups/{$token}/members/{$aliceId}", null, [
    'name' => 'Alice',
    'upi_id' => 'alice.fintech@okaxis',
]));

// Test settlement plan now includes to_upi_id for Alice
$planRes2 = dispatch($router, new Request('GET', "/api/groups/{$token}/settlement-plan"));
assertTest($planRes2['success'] === true, "GET /api/groups/{token}/settlement-plan returns success after UPI update");
$txAlice = $planRes2['data']['transactions'][0];
assertTest(($txAlice['to_upi_id'] ?? '') === 'alice.fintech@okaxis', "Settlement plan transaction to Alice includes to_upi_id: alice.fintech@okaxis");

// UPI-01: Nullable unset (clearing UPI ID)
$unsetUpiRes = dispatch($router, new Request('PUT', "/api/groups/{$token}/members/{$aliceId}", null, [
    'name' => 'Alice',
    'upi_id' => '',
]));
assertTest($unsetUpiRes['success'] === true, "Clearing UPI ID with empty string succeeds");
assertTest($unsetUpiRes['data']['member']['upi_id'] === null, "Member upi_id is now null after unset");

echo "\n=====================================================\n";
echo " All {$testsPassed} / {$totalTests} Debt Simplification Graph Tests PASSED!\n";
echo "=====================================================\n";
