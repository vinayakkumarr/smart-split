<?php

declare(strict_types=1);

/**
 * Test Suite for Step 14: Balances Dashboard & Visual Settlement Plan Execution
 */

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/Response.php';
require_once __DIR__ . '/../src/Core/Router.php';
require_once __DIR__ . '/../src/Core/BaseController.php';
require_once __DIR__ . '/../src/Core/Middleware/SecurityHeadersMiddleware.php';
require_once __DIR__ . '/../src/Utils/Money.php';
require_once __DIR__ . '/../src/Services/SplitCalculator.php';
require_once __DIR__ . '/../src/Services/BalanceService.php';
require_once __DIR__ . '/../src/Services/SettlementEngine.php';
require_once __DIR__ . '/../src/Services/ExpenseService.php';
require_once __DIR__ . '/../src/Repositories/GroupRepository.php';
require_once __DIR__ . '/../src/Repositories/MemberRepository.php';
require_once __DIR__ . '/../src/Repositories/CategoryRepository.php';
require_once __DIR__ . '/../src/Repositories/ExpenseRepository.php';
require_once __DIR__ . '/../src/Repositories/SettlementRepository.php';
require_once __DIR__ . '/../src/Repositories/ActivityLogRepository.php';
require_once __DIR__ . '/../src/Controllers/GroupController.php';
require_once __DIR__ . '/../src/Controllers/MemberController.php';
require_once __DIR__ . '/../src/Controllers/ExpenseController.php';
require_once __DIR__ . '/../src/Controllers/BalanceController.php';
require_once __DIR__ . '/../src/Controllers/SettlementController.php';

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
use App\Utils\Money;

Env::load(__DIR__ . '/../.env');

$db = Database::getConnection();
$db->exec("DELETE FROM expense_splits");
$db->exec("DELETE FROM expense_payers");
$db->exec("DELETE FROM expenses");
$db->exec("DELETE FROM settlements");
$db->exec("DELETE FROM activity_logs");
$db->exec("DELETE FROM members");
$db->exec("DELETE FROM groups");

$router = new Router();
$router->use(new SecurityHeadersMiddleware());

$router->post('/api/groups', [GroupController::class, 'create']);
$router->get('/api/groups/{token}', [GroupController::class, 'show']);
$router->post('/api/groups/{token}/members', [MemberController::class, 'create']);
$router->post('/api/groups/{token}/expenses', [ExpenseController::class, 'create']);
$router->get('/api/groups/{token}/expenses', [ExpenseController::class, 'index']);
$router->delete('/api/groups/{token}/expenses/{id}', [ExpenseController::class, 'delete']);
$router->get('/api/groups/{token}/balances', [BalanceController::class, 'index']);
$router->get('/api/groups/{token}/settlement-plan', [BalanceController::class, 'settlementPlan']);
$router->post('/api/groups/{token}/settlements', [SettlementController::class, 'create']);
$router->get('/api/groups/{token}/settlements', [SettlementController::class, 'index']);
$router->delete('/api/groups/{token}/settlements/{id}', [SettlementController::class, 'delete']);

function callApi(Router $router, string $method, string $path, array $body = []): array
{
    $headers = [
        'content-type' => 'application/json',
        'accept' => 'application/json',
        'x-requested-with' => 'Fetch',
    ];
    $req = new Request($method, $path, [], $body, $headers);
    ob_start();
    $router->dispatch($req);
    $out = ob_get_clean();
    $decoded = json_decode($out, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON: {$out}");
    }
    return $decoded;
}

$testsPassed = 0;
$totalTests = 0;

function assertCheck(bool $condition, string $testName): void
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

echo "=====================================================\n";
echo " Step 14 – Dashboard Balances & Settlement Plan Tests\n";
echo "=====================================================\n\n";

// 1. Setup Group with 3 Members
$gRes = callApi($router, 'POST', '/api/groups', ['name' => 'Goa Trip 2026', 'creator_name' => 'Alice', 'currency' => 'INR']);
$token = $gRes['data']['group']['invite_token'];
$aliceId = $gRes['data']['creator']['id'];

$bRes = callApi($router, 'POST', "/api/groups/{$token}/members", ['name' => 'Bob']);
$bobId = $bRes['data']['member']['id'];

$cRes = callApi($router, 'POST', "/api/groups/{$token}/members", ['name' => 'Charlie']);
$charlieId = $cRes['data']['member']['id'];

assertCheck($token && $aliceId && $bobId && $charlieId, "Group initialized with Alice, Bob, and Charlie");

// 2. Log 3-way Expense: Alice pays ₹90.00 (9000 paise) split equally
$expRes = callApi($router, 'POST', "/api/groups/{$token}/expenses", [
    'title' => 'Resort Stay',
    'amount_cents' => 9000,
    'expense_date' => '2026-09-21',
    'split_type' => 'EQUAL',
    'payers' => [
        ['member_id' => $aliceId, 'amount_cents' => 9000],
    ],
    'splits' => [
        ['member_id' => $aliceId, 'amount_cents' => 3000],
        ['member_id' => $bobId, 'amount_cents' => 3000],
        ['member_id' => $charlieId, 'amount_cents' => 3000],
    ],
]);
assertCheck($expRes['success'] === true, "Logged 3-way expense for ₹90.00");
$expId = $expRes['data']['expense']['id'];

// 3. Verify BalanceSummary calculation
$balRes = callApi($router, 'GET', "/api/groups/{$token}/balances");
assertCheck($balRes['data']['total_spending_cents'] === 9000, "Total group spend is ₹90.00 (9000 paise)");

$bMap = [];
foreach ($balRes['data']['members'] as $m) {
    $bMap[$m['member_id']] = $m;
}
assertCheck($bMap[$aliceId]['net_balance_cents'] === 6000 && $bMap[$aliceId]['status'] === 'CREDITOR', "Alice is CREDITOR for +₹60.00");
assertCheck($bMap[$bobId]['net_balance_cents'] === -3000 && $bMap[$bobId]['status'] === 'DEBTOR', "Bob is DEBTOR for -₹30.00");
assertCheck($bMap[$charlieId]['net_balance_cents'] === -3000 && $bMap[$charlieId]['status'] === 'DEBTOR', "Charlie is DEBTOR for -₹30.00");

// 4. Verify SettlementPlan visual transfers
$planRes = callApi($router, 'GET', "/api/groups/{$token}/settlement-plan");
$txs = $planRes['data']['transactions'];
assertCheck(count($txs) === 2, "Settlement plan produces exactly 2 transactions");
assertCheck($txs[0]['amount_cents'] === 3000 && $txs[1]['amount_cents'] === 3000, "Both transfers are for ₹30.00");

// 5. Execute 1st 'Mark as Paid': Bob pays Alice ₹30.00
$settle1Res = callApi($router, 'POST', "/api/groups/{$token}/settlements", [
    'payer_id' => $bobId,
    'payee_id' => $aliceId,
    'amount_cents' => 3000,
    'notes' => 'Settled via UPI',
]);
assertCheck($settle1Res['success'] === true, "Bob's ₹30.00 payment to Alice successfully recorded");

// Re-verify balances after 1st settlement
$balAfter1 = callApi($router, 'GET', "/api/groups/{$token}/balances");
$bMapAfter1 = [];
foreach ($balAfter1['data']['members'] as $m) {
    $bMapAfter1[$m['member_id']] = $m;
}
assertCheck($bMapAfter1[$bobId]['net_balance_cents'] === 0 && $bMapAfter1[$bobId]['status'] === 'SETTLED', "Bob is now fully SETTLED (₹0.00)");
assertCheck($bMapAfter1[$aliceId]['net_balance_cents'] === 3000, "Alice balance updated to +₹30.00");

// 6. Execute 2nd 'Mark as Paid': Charlie pays Alice ₹30.00
$settle2Res = callApi($router, 'POST', "/api/groups/{$token}/settlements", [
    'payer_id' => $charlieId,
    'payee_id' => $aliceId,
    'amount_cents' => 3000,
    'notes' => 'Cash handed over',
]);
assertCheck($settle2Res['success'] === true, "Charlie's ₹30.00 payment to Alice successfully recorded");

// Re-verify balances after 2nd settlement
$balAfter2 = callApi($router, 'GET', "/api/groups/{$token}/balances");
foreach ($balAfter2['data']['members'] as $m) {
    assertCheck($m['net_balance_cents'] === 0 && $m['status'] === 'SETTLED', "Member {$m['name']} is completely SETTLED (₹0.00)");
}

$planAfter2 = callApi($router, 'GET', "/api/groups/{$token}/settlement-plan");
assertCheck(count($planAfter2['data']['transactions']) === 0, "Settlement plan is 100% clear (0 transactions remaining)");

// 7. Verify Expense Feed listing & soft deletion
$expListRes = callApi($router, 'GET', "/api/groups/{$token}/expenses");
assertCheck(count($expListRes['data']['expenses']) === 1, "Expense feed lists active expense");

$delExpRes = callApi($router, 'DELETE', "/api/groups/{$token}/expenses/{$expId}");
assertCheck($delExpRes['success'] === true, "Soft-deleted expense");

$expListAfterDel = callApi($router, 'GET', "/api/groups/{$token}/expenses");
assertCheck(count($expListAfterDel['data']['expenses']) === 0, "Expense feed is empty after deletion");

echo "\n=====================================================\n";
echo " All {$testsPassed} / {$totalTests} Step 14 Dashboard & Settlement Tests PASSED!\n";
echo "=====================================================\n";
