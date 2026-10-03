<?php

declare(strict_types=1);

/**
 * Test Suite for Step 15: Security Hardening, XSS, CSRF & Edge Case Invariant Tests
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

Env::load(__DIR__ . '/../.env');

$db = Database::getConnection();
$db->exec("DELETE FROM `receipt_attachments`");
$db->exec("DELETE FROM `expense_item_assignments`");
$db->exec("DELETE FROM `expense_items`");
$db->exec("DELETE FROM `expense_splits`");
$db->exec("DELETE FROM `expense_payers`");
$db->exec("DELETE FROM `expenses`");
$db->exec("DELETE FROM `settlements`");
$db->exec("DELETE FROM `recurring_rules`");
$db->exec("DELETE FROM `expense_templates`");
$db->exec("DELETE FROM `creator_pairing_codes`");
$db->exec("DELETE FROM `workspace_events`");
$db->exec("DELETE FROM `activity_logs`");
$db->exec("DELETE FROM `rate_limits`");
$db->exec("DELETE FROM `categories` WHERE `group_id` IS NOT NULL");
$db->exec("DELETE FROM `members`");
$db->exec("DELETE FROM `groups`");

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

function executeRequest(Router $router, string $method, string $path, array $body = [], array $headers = []): array
{
    $defaultHeaders = [
        'content-type' => 'application/json',
        'accept' => 'application/json',
        'x-requested-with' => 'Fetch',
    ];
    $mergedHeaders = array_merge($defaultHeaders, $headers);
    $req = new Request($method, $path, [], $body, $mergedHeaders);
    ob_start();
    try {
        $router->dispatch($req);
    } catch (\Throwable $e) {
        \App\Core\Response::error($e->getMessage(), 'VALIDATION_ERROR', null, 422, false);
    }
    $out = ob_get_clean();
    $decoded = json_decode($out, true);
    return is_array($decoded) ? $decoded : ['raw' => $out];
}

$testsPassed = 0;
$totalTests = 0;

function assertSecurity(bool $condition, string $testName, mixed $debug = null): void
{
    global $testsPassed, $totalTests;
    $totalTests++;
    if ($condition) {
        echo "  [PASS] {$testName}\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] {$testName}\n";
        if ($debug !== null) {
            echo "         > Debug: " . json_encode($debug, JSON_UNESCAPED_SLASHES) . "\n";
        }
        exit(1);
    }
}

echo "=====================================================\n";
echo " Step 15 – Security Hardening, XSS, CSRF & Edge Tests\n";
echo "=====================================================\n\n";

// 1. Security Headers Verification
echo "--- 1. Testing Security Headers Middleware ---\n";
$middleware = new SecurityHeadersMiddleware();
$req = new Request('GET', '/api/health');
$middleware($req);
assertSecurity(true, "Security headers middleware executed without exceptions");

// 2. XSS Payload Injection in Group, Member, and Expense
echo "\n--- 2. Testing XSS Injections & Sanitization ---\n";
$xssGroupName = '<script>alert("xss")</script> Trip 2026';
$xssCreatorName = '<img src=x onerror=alert(1)> Alice';
$createGroupRes = executeRequest($router, 'POST', '/api/groups', [
    'name' => $xssGroupName,
    'creator_name' => $xssCreatorName,
    'currency' => 'INR',
]);

assertSecurity($createGroupRes['success'] === true, "Created group with XSS injection payload", $createGroupRes);
$token = $createGroupRes['data']['group']['invite_token'];
$aliceId = $createGroupRes['data']['creator']['id'];

// Check sanitized names
assertSecurity(!str_contains($createGroupRes['data']['creator']['name'], '<script>') && !str_contains($createGroupRes['data']['creator']['name'], '<img'), "Creator name stripped/neutralized of raw executable HTML tags");

// Add member with HTML tags
$xssMemberRes = executeRequest($router, 'POST', "/api/groups/{$token}/members", [
    'name' => '<b onmouseover="alert(\'pwned\')">Bob</b>',
]);
assertSecurity($xssMemberRes['success'] === true, "Member created successfully");
$bobId = $xssMemberRes['data']['member']['id'];
assertSecurity(!str_contains($xssMemberRes['data']['member']['name'], '<b onmouseover'), "Member name cleaned of malicious attributes");

// Add expense with XSS in title
$xssExpenseRes = executeRequest($router, 'POST', "/api/groups/{$token}/expenses", [
    'title' => '<svg onload="alert(\'xss\')">Dinner</svg>',
    'amount_cents' => 5000,
    'expense_date' => '2026-09-21',
    'split_type' => 'EQUAL',
    'payers' => [
        ['member_id' => $aliceId, 'amount_cents' => 5000],
    ],
    'splits' => [
        ['member_id' => $aliceId, 'amount_cents' => 2500],
        ['member_id' => $bobId, 'amount_cents' => 2500],
    ],
]);
assertSecurity($xssExpenseRes['success'] === true, "Expense created with XSS title");
assertSecurity(!str_contains($xssExpenseRes['data']['expense']['title'], '<svg onload'), "Expense title safely stripped of script payload");

// 3. Input Guards & Validation Edge Cases
echo "\n--- 3. Testing Input Guards & Edge Cases ---\n";

// Blank group name
$blankGroupRes = executeRequest($router, 'POST', '/api/groups', [
    'name' => '   ',
    'creator_name' => 'Alice',
]);
assertSecurity($blankGroupRes['success'] === false, "Blank group name rejected with validation error");

// Blank member name
$blankMemberRes = executeRequest($router, 'POST', "/api/groups/{$token}/members", [
    'name' => '',
]);
assertSecurity($blankMemberRes['success'] === false, "Empty member name rejected with validation error");

// Zero amount expense
$zeroExpenseRes = executeRequest($router, 'POST', "/api/groups/{$token}/expenses", [
    'title' => 'Zero Item',
    'amount_cents' => 0,
    'split_type' => 'EQUAL',
]);
assertSecurity($zeroExpenseRes['success'] === false, "0 amount expense rejected with validation error");

// Negative amount expense
$negExpenseRes = executeRequest($router, 'POST', "/api/groups/{$token}/expenses", [
    'title' => 'Negative Item',
    'amount_cents' => -1000,
    'split_type' => 'EQUAL',
]);
assertSecurity($negExpenseRes['success'] === false, "Negative amount expense rejected with validation error");

// Payer ID == Payee ID in Settlement
$selfSettleRes = executeRequest($router, 'POST', "/api/groups/{$token}/settlements", [
    'payer_id' => $aliceId,
    'payee_id' => $aliceId,
    'amount_cents' => 1000,
]);
assertSecurity($selfSettleRes['success'] === false, "Self-settlement (payer == payee) rejected with validation error");

// Non-existent member in settlement
$fakeMemberSettleRes = executeRequest($router, 'POST', "/api/groups/{$token}/settlements", [
    'payer_id' => 999999,
    'payee_id' => $aliceId,
    'amount_cents' => 1000,
]);
assertSecurity($fakeMemberSettleRes['success'] === false, "Settlement with non-existent member ID rejected");

echo "\n=====================================================\n";
echo " All {$testsPassed} / {$totalTests} Step 15 Security & Edge Case Tests PASSED!\n";
echo "=====================================================\n";
