<?php

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Request;
use App\Core\Router;
use App\Controllers\GroupController;
use App\Controllers\MemberController;
use App\Controllers\ExpenseController;
use App\Controllers\BalanceController;

echo "=====================================================\n";
echo " Smart Split – Trash / Deleted Items & Restore Tests\n";
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
$router->get('/api/groups/{token}/expenses/trash', [ExpenseController::class, 'trash']);
$router->get('/api/groups/{token}/expenses/{id}', [ExpenseController::class, 'show']);
$router->put('/api/groups/{token}/expenses/{id}', [ExpenseController::class, 'update']);
$router->put('/api/groups/{token}/expenses/{id}/restore', [ExpenseController::class, 'restore']);
$router->delete('/api/groups/{token}/expenses/{id}', [ExpenseController::class, 'delete']);
$router->get('/api/groups/{token}/balances', [BalanceController::class, 'index']);

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

// 1. Setup Group with 2 Members
$groupRes = dispatch($router, new Request('POST', '/api/groups', null, [
    'name' => 'Trash Test Group ' . time(),
    'creator_name' => 'Karan',
    'currency' => 'INR',
]));
assertTest($groupRes['success'] === true, 'Group created successfully');
$token = $groupRes['data']['group']['invite_token'];
$karanId = (int) $groupRes['data']['creator']['id'];

$simranRes = dispatch($router, new Request('POST', "/api/groups/{$token}/members", null, ['name' => 'Simran']));
$simranId = (int) $simranRes['data']['member']['id'];

// 2. Create 2 Expenses
$exp1Res = dispatch($router, new Request('POST', "/api/groups/{$token}/expenses", null, [
    'title' => 'Resort Stay',
    'amount' => '4000.00',
    'split_type' => 'EQUAL',
    'expense_date' => '2026-09-21',
    'paid_by_member_id' => $karanId,
    'splits' => [
        ['member_id' => $karanId],
        ['member_id' => $simranId],
    ],
]));
assertTest($exp1Res['success'] === true, 'Expense 1 (Resort Stay ₹4000) created');
$exp1Id = (int) $exp1Res['data']['expense']['id'];

$exp2Res = dispatch($router, new Request('POST', "/api/groups/{$token}/expenses", null, [
    'title' => 'Buffet Lunch',
    'amount' => '1500.00',
    'split_type' => 'EQUAL',
    'expense_date' => '2026-09-22',
    'paid_by_member_id' => $simranId,
    'splits' => [
        ['member_id' => $karanId],
        ['member_id' => $simranId],
    ],
]));
assertTest($exp2Res['success'] === true, 'Expense 2 (Buffet Lunch ₹1500) created');
$exp2Id = (int) $exp2Res['data']['expense']['id'];

// Check initial trash is empty
$trashEmptyRes = dispatch($router, new Request('GET', "/api/groups/{$token}/expenses/trash"));
assertTest($trashEmptyRes['success'] === true && count($trashEmptyRes['data']['expenses']) === 0, 'Trash is initially empty');

// 3. Soft-delete Expense 1
$delRes = dispatch($router, new Request('DELETE', "/api/groups/{$token}/expenses/{$exp1Id}"));
assertTest($delRes['success'] === true && $delRes['data']['deleted'] === true, 'Expense 1 soft-deleted');

// 4. Verify Active Expenses List has only 1 expense
$activeRes = dispatch($router, new Request('GET', "/api/groups/{$token}/expenses"));
assertTest(count($activeRes['data']['expenses']) === 1, 'Active expenses list contains only 1 item');
assertTest((int) $activeRes['data']['expenses'][0]['id'] === $exp2Id, 'Active item is Expense 2');

// 5. Verify Trash List contains Expense 1
$trashRes = dispatch($router, new Request('GET', "/api/groups/{$token}/expenses/trash"));
assertTest($trashRes['success'] === true, 'Trash endpoint responds with success');
assertTest(count($trashRes['data']['expenses']) === 1, 'Trash contains 1 item');
assertTest((int) $trashRes['data']['expenses'][0]['id'] === $exp1Id, 'Trash item is Expense 1');
assertTest($trashRes['data']['expenses'][0]['title'] === 'Resort Stay', 'Trash item title is accurate');
assertTest($trashRes['data']['expenses'][0]['is_deleted'] === true, 'Trash item has is_deleted=true');

// 6. Restore Expense 1
$restoreRes = dispatch($router, new Request('PUT', "/api/groups/{$token}/expenses/{$exp1Id}/restore"));
assertTest($restoreRes['success'] === true, 'Restore endpoint responds with success');
assertTest($restoreRes['data']['restored'] === true, 'Expense 1 marked as restored');

// 7. Verify Active Expenses List has both expenses again
$activeAfterRes = dispatch($router, new Request('GET', "/api/groups/{$token}/expenses"));
assertTest(count($activeAfterRes['data']['expenses']) === 2, 'Active expenses list restored to 2 items');

// 8. Verify Trash List is now empty
$trashAfterRes = dispatch($router, new Request('GET', "/api/groups/{$token}/expenses/trash"));
assertTest(count($trashAfterRes['data']['expenses']) === 0, 'Trash is empty after restore');

// 9. Verify Error Handling: attempting to restore an already active expense returns error
$invalidRestoreRes = dispatch($router, new Request('PUT', "/api/groups/{$token}/expenses/{$exp1Id}/restore"));
assertTest($invalidRestoreRes['success'] === false && $invalidRestoreRes['error']['code'] === 'NOT_FOUND', 'Attempting to restore active expense returns NOT_FOUND error');

// 10. Verify Balances after restore
$balRes = dispatch($router, new Request('GET', "/api/groups/{$token}/balances"));
assertTest($balRes['success'] === true, 'Balances retrieved successfully');
assertTest($balRes['data']['zero_sum_verified'] === true, 'Zero-sum balance invariant preserved after restore');

echo "\n=====================================================\n";
echo " ALL {$testsPassed} / {$totalTests} TRASH & RESTORE TESTS PASSED!\n";
echo "=====================================================\n";
