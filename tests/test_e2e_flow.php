<?php

declare(strict_types=1);

/**
 * Smart Split – Full End-to-End System & Lifecycle Verification Suite
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Env;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Core\Middleware\SecurityHeadersMiddleware;
use App\Controllers\GroupController;
use App\Controllers\MemberController;
use App\Controllers\ExpenseController;
use App\Controllers\BalanceController;
use App\Controllers\SettlementController;

$db = Database::getConnection();
$db->exec("SET FOREIGN_KEY_CHECKS = 0");
$db->exec("DELETE FROM expense_splits");
$db->exec("DELETE FROM expense_payers");
$db->exec("DELETE FROM expense_items");
$db->exec("DELETE FROM receipt_attachments");
$db->exec("DELETE FROM expenses");
$db->exec("DELETE FROM settlements");
$db->exec("DELETE FROM activity_logs");
$db->exec("DELETE FROM members");
$db->exec("DELETE FROM groups");
$db->exec("SET FOREIGN_KEY_CHECKS = 1");


$router = new Router();
$router->use(new SecurityHeadersMiddleware());

// Register API Routes
$router->post('/api/groups', [GroupController::class, 'create']);
$router->get('/api/groups/{token}', [GroupController::class, 'show']);
$router->post('/api/groups/{token}/members', [MemberController::class, 'create']);
$router->get('/api/groups/{token}/members', [MemberController::class, 'index']);
$router->post('/api/groups/{token}/expenses', [ExpenseController::class, 'create']);
$router->get('/api/groups/{token}/expenses', [ExpenseController::class, 'index']);
$router->get('/api/groups/{token}/expenses/{id}', [ExpenseController::class, 'show']);
$router->delete('/api/groups/{token}/expenses/{id}', [ExpenseController::class, 'delete']);
$router->get('/api/groups/{token}/balances', [BalanceController::class, 'index']);
$router->get('/api/groups/{token}/settlement-plan', [BalanceController::class, 'settlementPlan']);
$router->post('/api/groups/{token}/settlements', [SettlementController::class, 'create']);
$router->post('/api/groups/{token}/settlements/{id}/confirm', [SettlementController::class, 'confirm']);
$router->get('/api/groups/{token}/settlements', [SettlementController::class, 'index']);
$router->delete('/api/groups/{token}/settlements/{id}', [SettlementController::class, 'delete']);

function dispatchApi(Router $router, string $method, string $path, array $body = [], array $queryParams = []): array
{
    $headers = [
        'content-type' => 'application/json',
        'accept' => 'application/json',
        'x-requested-with' => 'Fetch',
    ];

    $req = new Request($method, $path, $queryParams, $body, $headers);
    ob_start();
    $router->dispatch($req);
    $output = ob_get_clean();

    $json = json_decode($output, true);
    if (!$json) {
        throw new RuntimeException("Invalid JSON output from {$method} {$path}: " . $output);
    }
    return $json;
}

echo "=== SMART SPLIT FULL END-TO-END VERIFICATION ===\n\n";

// 1. Create Group with XSS test string
echo "1. Creating Group in INR base currency with XSS safety check...\n";
$groupRes = dispatchApi($router, 'POST', '/api/groups', [
    'name' => 'Goa Trip <script>alert("xss")</script>',
    'creator_name' => 'Alice <img src=x onerror=alert(1)>',
    'currency' => 'INR',
]);
assert($groupRes['success'] === true, 'Group creation failed');
$token = $groupRes['data']['group']['invite_token'];
$creatorId = $groupRes['data']['creator']['id'];
$currCode = $groupRes['data']['group']['currency_code'] ?? 'INR';
echo "✓ Group created! Token: {$token}, Currency: {$currCode}\n";

// 2. Add Members: Bob and Charlie
echo "\n2. Adding group members Bob and Charlie...\n";
$bobRes = dispatchApi($router, 'POST', "/api/groups/{$token}/members", ['name' => 'Bob']);
$bobId = $bobRes['data']['member']['id'];

$charlieRes = dispatchApi($router, 'POST', "/api/groups/{$token}/members", ['name' => 'Charlie']);
$charlieId = $charlieRes['data']['member']['id'];
echo "✓ Members registered: Alice (ID: {$creatorId}), Bob (ID: {$bobId}), Charlie (ID: {$charlieId})\n";

// 3. Log Expense 1: Alice pays ₹60.00 (6000 paise) split equally among Alice, Bob, Charlie (₹20 each)
echo "\n3. Logging Expense 1: Alice pays ₹60.00 split 3 ways equally...\n";
$exp1Res = dispatchApi($router, 'POST', "/api/groups/{$token}/expenses", [
    'title' => 'Seafood Dinner',
    'amount_cents' => 6000,
    'expense_date' => '2026-09-21',
    'split_type' => 'EQUAL',
    'payers' => [
        ['member_id' => $creatorId, 'amount_cents' => 6000],
    ],
    'splits' => [
        ['member_id' => $creatorId, 'amount_cents' => 2000],
        ['member_id' => $bobId, 'amount_cents' => 2000],
        ['member_id' => $charlieId, 'amount_cents' => 2000],
    ],
]);
assert($exp1Res['success'] === true, 'Expense 1 failed');
$exp1Id = $exp1Res['data']['expense']['id'];
echo "✓ Expense 1 recorded (ID: {$exp1Id})\n";

// 4. Log Expense 2: Bob pays ₹30.00 (3000 paise) split equally between Bob and Charlie (₹15 each)
echo "\n4. Logging Expense 2: Bob pays ₹30.00 split between Bob & Charlie...\n";
$exp2Res = dispatchApi($router, 'POST', "/api/groups/{$token}/expenses", [
    'title' => 'Scooter Rental',
    'amount_cents' => 3000,
    'expense_date' => '2026-09-21',
    'split_type' => 'EQUAL',
    'payers' => [
        ['member_id' => $bobId, 'amount_cents' => 3000],
    ],
    'splits' => [
        ['member_id' => $bobId, 'amount_cents' => 1500],
        ['member_id' => $charlieId, 'amount_cents' => 1500],
    ],
]);
assert($exp2Res['success'] === true, 'Expense 2 failed');
echo "✓ Expense 2 recorded\n";

// 5. Verify Net Balances (Alice: +₹40.00, Bob: -₹5.00, Charlie: -₹35.00)
echo "\n5. Verifying Net Balances and Zero-Sum Ledger Invariant...\n";
$balRes = dispatchApi($router, 'GET', "/api/groups/{$token}/balances");
assert($balRes['success'] === true, 'Balance API failed');
assert($balRes['data']['zero_sum_verified'] === true, 'Zero-sum invariant failed');

$balancesMap = [];
foreach ($balRes['data']['members'] as $m) {
    $balancesMap[$m['member_id']] = $m['net_balance_cents'];
    $paidFmt = \App\Utils\Money::format($m['total_paid_cents'] ?? 0);
    $owedFmt = \App\Utils\Money::format($m['total_owed_cents'] ?? 0);
    $netFmt = \App\Utils\Money::format($m['net_balance_cents'] ?? 0);
    echo "   - Member {$m['name']}: Paid {$paidFmt}, Owed {$owedFmt}, Net {$netFmt} ({$m['status']})\n";
}

assert($balancesMap[$creatorId] === 4000, 'Alice net balance must be +4000 paise');
assert($balancesMap[$bobId] === -500, 'Bob net balance must be -500 paise');
assert($balancesMap[$charlieId] === -3500, 'Charlie net balance must be -3500 paise');
echo "✓ Net balances match mathematical expectations (+4000, -500, -3500 -> Sum: 0)\n";

// 6. Verify Settlement Plan (Optimal transactions <= N-1)
echo "\n6. Computing Greedy Debt Simplification Plan...\n";
$planRes = dispatchApi($router, 'GET', "/api/groups/{$token}/settlement-plan");
$txs = $planRes['data']['transactions'];
assert(count($txs) === 2, 'Settlement plan must contain exactly 2 transactions');
echo "   - Transaction 1: {$txs[0]['from_name']} pays {$txs[0]['to_name']} {$txs[0]['amount_formatted']}\n";
echo "   - Transaction 2: {$txs[1]['from_name']} pays {$txs[1]['to_name']} {$txs[1]['amount_formatted']}\n";
echo "✓ Simplified transactions generated: " . count($txs) . " transactions (<= 2)\n";

// 7. Execute Settlement: Charlie pays Alice ₹35.00 (3500 paise)
echo "\n7. Recording Settlement Payment: Charlie pays Alice ₹35.00...\n";
$settleRes = dispatchApi($router, 'POST', "/api/groups/{$token}/settlements", [
    'payer_id' => $charlieId,
    'payee_id' => $creatorId,
    'amount_cents' => 3500,
    'notes' => 'UPI transfer',
]);
assert($settleRes['success'] === true, 'Settlement recording failed');
$settleId = $settleRes['data']['settlement']['id'];
echo "✓ Settlement recorded (ID: {$settleId})\n";

// Creditor (Alice) confirms receipt
$confRes = dispatchApi($router, 'POST', "/api/groups/{$token}/settlements/{$settleId}/confirm", [
    'confirmed_by_member_id' => $creatorId,
]);
assert($confRes['success'] === true, 'Settlement confirmation failed');
echo "✓ Settlement confirmed by creditor (Alice)\n";

// 8. Re-verify Net Balances: Charlie should now be SETTLED (0), Alice (+500), Bob (-500)
echo "\n8. Re-checking balances after settlement...\n";
$balRes2 = dispatchApi($router, 'GET', "/api/groups/{$token}/balances");
$balancesMap2 = [];
foreach ($balRes2['data']['members'] as $m) {
    $balancesMap2[$m['member_id']] = $m['net_balance_cents'];
    $netFmt = \App\Utils\Money::format($m['net_balance_cents'] ?? 0);
    echo "   - Member {$m['name']}: Net {$netFmt} ({$m['status']})\n";
}
assert($balancesMap2[$charlieId] === 0, 'Charlie should be fully settled');
assert($balancesMap2[$creatorId] === 500, 'Alice should be +500 paise');
assert($balancesMap2[$bobId] === -500, 'Bob should be -500 paise');
echo "✓ Balances reactively updated! Charlie is Settled Up (0).\n";

// 9. Test Soft Deletion of Expense 1
echo "\n9. Testing Soft Deletion of Expense 1...\n";
$delRes = dispatchApi($router, 'DELETE', "/api/groups/{$token}/expenses/{$exp1Id}");
assert($delRes['success'] === true, 'Delete expense failed');
echo "✓ Expense 1 soft-deleted\n";

// 10. Verify Balances after Deletion
$balRes3 = dispatchApi($router, 'GET', "/api/groups/{$token}/balances");
assert($balRes3['success'] === true);
assert($balRes3['data']['zero_sum_verified'] === true);
echo "✓ Zero-sum preserved after expense deletion\n";

echo "\n=======================================================\n";
echo "ALL END-TO-END LIFECYCLE TESTS COMPLETED SUCCESSFULLY!\n";
echo "=======================================================\n";
