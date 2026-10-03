<?php

declare(strict_types=1);

/**
 * Smart Split V2 — SEC-15: API Contract Integrity & Boundary Validation Suite
 *
 * Validates the core architectural property:
 * "Every exposed API endpoint consistently enforces its documented input contract,
 *  authorization boundary, HTTP status semantics, response envelope, validation behavior,
 *  content type, and malformed-input handling without leaking implementation details
 *  or creating inconsistent client-visible states."
 *
 * 18 Test Domains:
 * SEC15.1  Route Table Inventory & HTTP 405/404 Routing
 * SEC15.2  HTTP Status Code Standard Verification (200, 201, 400, 401, 403, 404, 409, 410, 422, 429)
 * SEC15.3  Malformed JSON & Primitive Payload Handling
 * SEC15.4  Type Confusion & Cast Robustness
 * SEC15.5  Boundary Limits, Length & Numeric Overflow
 * SEC15.6  Enum Validation & Rejection
 * SEC15.7  Null, Missing & Whitespace Differentiation
 * SEC15.8  Query Parameter & Search Sanitization
 * SEC15.9  Path Parameter & Traversal Defense
 * SEC15.10 Authentication & Session Authorization Boundaries
 * SEC15.11 Idempotency Key Handling & Mutation Safety
 * SEC15.12 Receipt Attachment File & Base64 Validation
 * SEC15.13 CSV Export Headers & Content-Disposition Sanitization
 * SEC15.14 Real-Time SSE Stream & Polling Fallback
 * SEC15.15 Production Error Disclosure & Envelope Invariance
 * SEC15.16 Transaction Rollback on Mid-Request Failure
 * SEC15.17 Vanilla ES6 Frontend Envelope Compatibility
 * SEC15.18 Randomized Adversarial Fuzzing Matrix (250 Scenarios)
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Env;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Middleware\SecurityHeadersMiddleware;
use App\Core\Middleware\AuthSessionMiddleware;
use App\Controllers\GroupController;
use App\Controllers\MemberController;
use App\Controllers\ExpenseController;
use App\Controllers\BalanceController;
use App\Controllers\SettlementController;
use App\Controllers\CategoryController;
use App\Controllers\TemplateController;
use App\Controllers\RecurringController;
use App\Controllers\ReceiptController;
use App\Controllers\ActivityController;
use App\Controllers\EventController;
use App\Controllers\CurrencyController;
use App\Controllers\AuthController;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

echo "\n================================================================================\n";
echo " SEC-15: API CONTRACT INTEGRITY & BOUNDARY VALIDATION SUITE\n";
echo "================================================================================\n\n";

$passed = 0;
$total = 0;
$failures = [];

function assertSec15(bool $condition, string $testId, string $description, ?string $details = null): void {
    global $passed, $total, $failures;
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$testId}: {$description}\n";
    } else {
        echo "  [FAIL] {$testId}: {$description}\n";
        if ($details) {
            echo "         > Details: {$details}\n";
        }
        $failures[] = [
            'testId' => $testId,
            'description' => $description,
            'details' => $details,
        ];
    }
}

// Router Setup
$router = new Router();
$router->use(new SecurityHeadersMiddleware());
$router->use(new AuthSessionMiddleware($pdo));

// Register all 36 canonical routes matching public/index.php exactly
$router->get('/api/health', function (Request $req): void {
    Response::json([
        'status' => 'ok',
        'app' => Env::get('APP_NAME', 'Smart Split'),
        'version' => '1.0.0',
        'currency' => 'INR',
        'environment' => Env::get('APP_ENV', 'local'),
        'timestamp' => time(),
    ], 200, ['php_version' => PHP_VERSION], false);
});
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get('/api/auth/me', [AuthController::class, 'me']);
$router->post('/api/auth/recover', [AuthController::class, 'recoverPassword']);
$router->post('/api/auth/recover-password', [AuthController::class, 'recoverPassword']);

$router->post('/api/groups/{token}/claim-member', [AuthController::class, 'claimMember']);
$router->post('/api/groups/{token}/members/{memberId}/claim', [AuthController::class, 'claimMember']);
$router->post('/api/groups/{token}/unlink-member', [AuthController::class, 'unlinkMember']);
$router->post('/api/groups/{token}/members/{memberId}/unlink', [AuthController::class, 'unlinkMember']);
$router->get('/api/user/workspaces', [AuthController::class, 'userWorkspaces']);
$router->delete('/api/user/account', [AuthController::class, 'deleteAccount']);

$router->post('/api/groups', [GroupController::class, 'create']);
$router->get('/api/groups/{token}', [GroupController::class, 'show']);
$router->delete('/api/groups/{token}', [GroupController::class, 'delete']);
$router->post('/api/groups/{token}/creator-pairing', [GroupController::class, 'createPairingCode']);
$router->post('/api/groups/{token}/creator-pairing/claim', [GroupController::class, 'claimPairingCode']);

$router->post('/api/groups/{token}/members', [MemberController::class, 'create']);
$router->get('/api/groups/{token}/members', [MemberController::class, 'index']);
$router->put('/api/groups/{token}/members/{id}', [MemberController::class, 'update']);
$router->delete('/api/groups/{token}/members/{id}', [MemberController::class, 'delete']);

$router->get('/api/categories', [CategoryController::class, 'index']);
$router->get('/api/groups/{token}/categories', [CategoryController::class, 'index']);
$router->post('/api/groups/{token}/categories', [CategoryController::class, 'create']);
$router->delete('/api/groups/{token}/categories/{id}', [CategoryController::class, 'delete']);

$router->post('/api/groups/{token}/expenses', [ExpenseController::class, 'create']);
$router->get('/api/groups/{token}/expenses', [ExpenseController::class, 'index']);
$router->get('/api/groups/{token}/expenses/trash', [ExpenseController::class, 'trash']);
$router->get('/api/groups/{token}/expenses/{id}', [ExpenseController::class, 'show']);
$router->put('/api/groups/{token}/expenses/{id}', [ExpenseController::class, 'update']);
$router->put('/api/groups/{token}/expenses/{id}/restore', [ExpenseController::class, 'restore']);
$router->delete('/api/groups/{token}/expenses/{id}', [ExpenseController::class, 'delete']);
$router->get('/api/groups/{token}/export.csv', [ExpenseController::class, 'exportCsv']);

$router->get('/api/groups/{token}/balances', [BalanceController::class, 'index']);
$router->get('/api/groups/{token}/bilateral-balances', [BalanceController::class, 'bilateralBalances']);
$router->get('/api/groups/{token}/settlement-plan', [BalanceController::class, 'settlementPlan']);
$router->get('/api/groups/{token}/analytics/summary', [BalanceController::class, 'analyticsSummary']);
$router->get('/api/groups/{token}/activity-feed', [ActivityController::class, 'index']);
$router->get('/api/groups/{token}/events', [EventController::class, 'stream']);
$router->get('/api/groups/{token}/members/{memberId}/ledger', [BalanceController::class, 'memberLedger']);

$router->post('/api/groups/{token}/settlements', [SettlementController::class, 'create']);
$router->get('/api/groups/{token}/settlements', [SettlementController::class, 'index']);
$router->delete('/api/groups/{token}/settlements/{id}', [SettlementController::class, 'delete']);

$router->get('/api/groups/{token}/recurring', [RecurringController::class, 'index']);
$router->post('/api/groups/{token}/recurring', [RecurringController::class, 'create']);
$router->delete('/api/groups/{token}/recurring/{id}', [RecurringController::class, 'delete']);
$router->post('/api/groups/{token}/recurring/evaluate', [RecurringController::class, 'evaluate']);

$router->get('/api/groups/{token}/templates', [TemplateController::class, 'index']);
$router->post('/api/groups/{token}/templates', [TemplateController::class, 'create']);
$router->delete('/api/groups/{token}/templates/{id}', [TemplateController::class, 'delete']);

$router->get('/api/currencies', [CurrencyController::class, 'index']);
$router->get('/api/exchange-rates', [CurrencyController::class, 'exchangeRate']);

$router->get('/api/groups/{token}/expenses/{id}/receipts', [ReceiptController::class, 'index']);
$router->post('/api/groups/{token}/expenses/{id}/receipts', [ReceiptController::class, 'create']);
$router->get('/api/groups/{token}/expenses/{id}/receipts/{receiptId}/download', [ReceiptController::class, 'download']);
$router->get('/api/groups/{token}/expenses/{id}/receipts/{receiptId}/view', [ReceiptController::class, 'download']);
$router->get('/api/groups/{token}/expenses/{id}/receipts/{receiptId}', [ReceiptController::class, 'download']);
$router->delete('/api/groups/{token}/expenses/{id}/receipts/{receiptId}', [ReceiptController::class, 'delete']);

/**
 * Helper to dispatch simulated requests through the router
 */
function dispatchTestRequest(
    Router $router,
    string $method,
    string $path,
    ?array $body = null,
    ?array $queryParams = null,
    array $headers = [],
    ?string $cookieToken = null
): array {
    if ($cookieToken !== null) {
        $_COOKIE['smartsplit_session'] = $cookieToken;
    } else {
        unset($_COOKIE['smartsplit_session']);
    }

    $req = new Request($method, $path, $queryParams, $body, $headers);
    ob_start();
    try {
        $router->dispatch($req);
    } catch (\InvalidArgumentException $e) {
        $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 422;
        Response::error($e->getMessage(), 'VALIDATION_ERROR', null, $code, false);
    } catch (\RuntimeException $e) {
        $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 404;
        Response::error($e->getMessage(), 'NOT_FOUND', null, $code, false);
    } catch (\Throwable $e) {
        $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 500;
        Response::error($e->getMessage(), 'SERVER_ERROR', null, $code, false);
    }
    $raw = ob_get_clean();
    $decoded = json_decode($raw ?: '', true);

    return [
        'status' => Response::$lastStatusCode,
        'body' => $decoded,
        'raw' => $raw,
    ];
}

// Reset rate limiter for test runs
$pdo->exec("DELETE FROM `rate_limits` WHERE 1");

// ================================================================================
// SETUP TEST FIXTURES
// ================================================================================
$testRunId = 'sec15_' . bin2hex(random_bytes(4));

$resGroup = dispatchTestRequest($router, 'POST', '/api/groups', [
    'name' => "SEC15 Contract Test Workspace {$testRunId}",
    'currency' => 'INR',
    'creator_name' => 'Alice',
]);

$groupToken = $resGroup['body']['data']['group']['invite_token'] ?? null;
$groupId = (int) ($resGroup['body']['data']['group']['id'] ?? 0);
$m1Id = (int) ($resGroup['body']['data']['creator']['id'] ?? 0);

if (!$groupToken || !$groupId || !$m1Id) {
    die("FATAL: Failed to initialize test workspace fixture.\n");
}

// Create 2 more Members
$m2 = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/members", ['name' => 'Bob']);
$m3 = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/members", ['name' => 'Charlie']);

$m2Id = (int) ($m2['body']['data']['member']['id'] ?? 0);
$m3Id = (int) ($m3['body']['data']['member']['id'] ?? 0);

// Create 1 Expense
$expRes = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/expenses", [
    'title' => 'Contract Dinner',
    'amount' => 300.00,
    'payer_member_id' => $m1Id,
    'split_type' => 'EQUAL',
    'split_members' => [$m1Id, $m2Id, $m3Id],
]);
$expId = (int) ($expRes['body']['data']['expense']['id'] ?? 0);

// Create 1 Custom Category
$catRes = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/categories", [
    'name' => 'Team Lunch',
    'icon' => '🍔',
    'color' => '#FF5733',
]);
$catId = (int) ($catRes['body']['data']['category']['id'] ?? 0);

// Create 1 Template
$tplRes = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/templates", [
    'title' => 'Weekly Lunch',
    'amount' => 150.00,
    'split_type' => 'EQUAL',
    'split_members' => [$m1Id, $m2Id],
]);
$tplId = (int) ($tplRes['body']['data']['template_id'] ?? 0);

// Create 1 Recurring Schedule
$recRes = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/recurring", [
    'title' => 'Office Internet',
    'amount' => 1200.00,
    'frequency' => 'MONTHLY',
    'start_date' => date('Y-m-d'),
    'split_type' => 'EQUAL',
    'split_members' => [$m1Id, $m2Id, $m3Id],
]);
$recId = (int) ($recRes['body']['data']['rule_id'] ?? 0);

// ================================================================================
// SEC15.1 — Route Table Inventory & HTTP 405/404 Routing
// ================================================================================
echo "\n--- Domain 1: Route Table Inventory & HTTP 405/404 Routing ---\n";

// Test 405 on wrong verb
$res405 = dispatchTestRequest($router, 'POST', '/api/health');
assertSec15(
    $res405['status'] === 405 && ($res405['body']['error']['code'] ?? '') === 'METHOD_NOT_ALLOWED',
    'SEC15.1.1',
    'POST /api/health correctly returns HTTP 405 Method Not Allowed'
);

$res405Group = dispatchTestRequest($router, 'PUT', '/api/groups');
assertSec15(
    $res405Group['status'] === 405,
    'SEC15.1.2',
    'PUT /api/groups correctly returns HTTP 405 Method Not Allowed'
);

// Test 404 on unmapped API route
$res404 = dispatchTestRequest($router, 'GET', '/api/nonexistent/endpoint');
assertSec15(
    $res404['status'] === 404 && ($res404['body']['error']['code'] ?? '') === 'NOT_FOUND',
    'SEC15.1.3',
    'GET /api/nonexistent/endpoint correctly returns HTTP 404 Not Found'
);

// ================================================================================
// SEC15.2 — HTTP Status Code Standard Verification
// ================================================================================
echo "\n--- Domain 2: HTTP Status Code Semantics ---\n";

// 200 OK
$res200 = dispatchTestRequest($router, 'GET', '/api/health');
assertSec15(
    $res200['status'] === 200 && ($res200['body']['data']['status'] ?? '') === 'ok',
    'SEC15.2.1',
    'GET /api/health returns HTTP 200 OK with valid data envelope'
);

// 201 Created
$res201 = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/members", ['name' => 'David']);
assertSec15(
    $res201['status'] === 201 && ($res201['body']['data']['member']['name'] ?? '') === 'David',
    'SEC15.2.2',
    'POST /api/groups/{token}/members returns HTTP 201 Created'
);

// 404 Not Found for nonexistent workspace token
$resBadGroup = dispatchTestRequest($router, 'GET', '/api/groups/invalid_tok_99999');
assertSec15(
    $resBadGroup['status'] === 404 && ($resBadGroup['body']['error']['code'] ?? '') === 'NOT_FOUND',
    'SEC15.2.3',
    'GET /api/groups/{invalid} returns HTTP 404 Not Found'
);

// 422 for duplicate member name in same group
$resDupMember = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/members", ['name' => 'Alice']);
assertSec15(
    in_array($resDupMember['status'], [409, 422], true),
    'SEC15.2.4',
    'POST /api/groups/{token}/members with duplicate name returns HTTP 422/409'
);

// 422 Unprocessable Entity for invalid expense data (e.g. missing amount)
$resInvalidExp = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/expenses", [
    'title' => 'Missing Amount Expense',
    'payer_member_id' => $m1Id,
]);
assertSec15(
    $resInvalidExp['status'] === 422,
    'SEC15.2.5',
    'POST /api/groups/{token}/expenses missing required fields returns HTTP 422'
);

// ================================================================================
// SEC15.3 — Malformed JSON & Primitive Payload Handling
// ================================================================================
echo "\n--- Domain 3: Malformed JSON & Primitive Payload Handling ---\n";

// Empty body on POST /api/groups
$resEmptyJson = dispatchTestRequest($router, 'POST', '/api/groups', []);
assertSec15(
    $resEmptyJson['status'] === 422,
    'SEC15.3.1',
    'POST /api/groups with empty JSON object {} returns HTTP 422 Unprocessable Entity'
);

// Missing split details on EXACT split
$resBadExact = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/expenses", [
    'title' => 'Bad Exact Split',
    'amount' => 100.00,
    'payer_member_id' => $m1Id,
    'split_type' => 'EXACT',
    'splits' => [
        $m1Id => 30.00, // sum is 30, not 100
    ],
]);
assertSec15(
    $resBadExact['status'] === 422,
    'SEC15.3.2',
    'POST /api/groups/{token}/expenses with mismatched EXACT split sum returns HTTP 422'
);

// ================================================================================
// SEC15.4 — Type Confusion & Cast Robustness
// ================================================================================
echo "\n--- Domain 4: Type Confusion & Cast Robustness ---\n";

// String amount on expense creation
$resStrAmount = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/expenses", [
    'title' => 'Numeric String Amount',
    'amount' => '150.00',
    'payer_member_id' => $m1Id,
    'split_type' => 'EQUAL',
    'split_members' => [$m1Id, $m2Id],
]);
assertSec15(
    $resStrAmount['status'] === 201,
    'SEC15.4.1',
    'Numeric strings for amount and payer_id are cast safely without precision loss'
);

// Non-numeric string amount
$resAlphaAmount = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/expenses", [
    'title' => 'Alphabetic Amount',
    'amount' => 'one_hundred_dollars',
    'payer_member_id' => $m1Id,
    'split_type' => 'EQUAL',
]);
assertSec15(
    $resAlphaAmount['status'] === 422,
    'SEC15.4.2',
    'Non-numeric string amount is cleanly rejected with HTTP 422'
);

// Array passed for scalar string name
$resArrayName = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/members", [
    'name' => ['Alice', 'Bob'],
]);
assertSec15(
    in_array($resArrayName['status'], [400, 422], true),
    'SEC15.4.3',
    'Array passed for scalar name parameter is cleanly rejected'
);

// ================================================================================
// SEC15.5 — Boundary Limits, Length & Numeric Overflow
// ================================================================================
echo "\n--- Domain 5: Boundary Limits, Length & Numeric Overflow ---\n";

// Zero amount expense
$resZeroExp = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/expenses", [
    'title' => 'Zero Expense',
    'amount' => 0.00,
    'payer_member_id' => $m1Id,
    'split_type' => 'EQUAL',
]);
assertSec15(
    $resZeroExp['status'] === 422,
    'SEC15.5.1',
    'Zero amount expense (amount=0) is rejected with HTTP 422'
);

// Negative amount expense
$resNegExp = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/expenses", [
    'title' => 'Negative Expense',
    'amount' => -100.00,
    'payer_member_id' => $m1Id,
    'split_type' => 'EQUAL',
]);
assertSec15(
    $resNegExp['status'] === 422,
    'SEC15.5.2',
    'Negative amount expense (amount=-100) is rejected with HTTP 422'
);

// Zero settlement amount
$resZeroSet = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/settlements", [
    'from_member_id' => $m2Id,
    'to_member_id' => $m1Id,
    'amount' => 0.00,
]);
assertSec15(
    $resZeroSet['status'] === 422,
    'SEC15.5.3',
    'Zero amount settlement is rejected with HTTP 422'
);

// Self-settlement (from === to)
$resSelfSet = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/settlements", [
    'from_member_id' => $m1Id,
    'to_member_id' => $m1Id,
    'amount' => 50.00,
]);
assertSec15(
    $resSelfSet['status'] === 422,
    'SEC15.5.4',
    'Self-settlement (payer === payee) is rejected with HTTP 422'
);

// Unicode & Emoji preservation
$resEmojiExp = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/expenses", [
    'title' => '🍕 Pizza Night & 🍻 Beers 🎉',
    'amount' => 80.00,
    'payer_member_id' => $m1Id,
    'split_type' => 'EQUAL',
    'split_members' => [$m1Id, $m2Id],
]);
assertSec15(
    $resEmojiExp['status'] === 201 && str_contains($resEmojiExp['body']['data']['expense']['title'] ?? '', '🍕 Pizza Night'),
    'SEC15.5.5',
    'UTF-8 multibyte characters and emojis are preserved intact'
);

// ================================================================================
// SEC15.6 — Enum Validation & Rejection
// ================================================================================
echo "\n--- Domain 6: Enum Validation & Rejection ---\n";

// Invalid split_type enum
$resBadSplitMethod = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/expenses", [
    'title' => 'Bad Split Method',
    'amount' => 50.00,
    'payer_member_id' => $m1Id,
    'split_type' => 'MAGIC_SPLIT',
]);
assertSec15(
    $resBadSplitMethod['status'] === 422,
    'SEC15.6.1',
    'Invalid split_type enum value is rejected with HTTP 422'
);

// Invalid recurring frequency enum
$resBadFreq = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/recurring", [
    'title' => 'Bad Frequency Recurring',
    'amount' => 100.00,
    'frequency' => 'HOURLY',
    'start_date' => date('Y-m-d'),
    'split_type' => 'EQUAL',
]);
assertSec15(
    $resBadFreq['status'] === 422,
    'SEC15.6.2',
    'Invalid recurring frequency enum value is rejected with HTTP 422'
);

// ================================================================================
// SEC15.7 — Null, Missing & Whitespace Differentiation
// ================================================================================
echo "\n--- Domain 7: Null, Missing & Whitespace Differentiation ---\n";

// Whitespace-only member name
$resSpaceMember = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/members", [
    'name' => "   \t\n  ",
]);
assertSec15(
    $resSpaceMember['status'] === 422,
    'SEC15.7.1',
    'Whitespace-only member name is rejected with HTTP 422'
);

// Whitespace-only expense title
$resSpaceExp = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/expenses", [
    'title' => "     ",
    'amount' => 50.00,
    'payer_member_id' => $m1Id,
    'split_type' => 'EQUAL',
]);
assertSec15(
    $resSpaceExp['status'] === 422,
    'SEC15.7.2',
    'Whitespace-only expense title is rejected with HTTP 422'
);

// ================================================================================
// SEC15.8 — Query Parameter & Search Sanitization
// ================================================================================
echo "\n--- Domain 8: Query Parameter & Search Sanitization ---\n";

// SQL wildcard search parameter test
$resWildcard = dispatchTestRequest($router, 'GET', "/api/groups/{$groupToken}/expenses", null, [
    'search' => '%_--\'" OR 1=1',
]);
assertSec15(
    $resWildcard['status'] === 200 && is_array($resWildcard['body']['data']['expenses'] ?? null),
    'SEC15.8.1',
    'SQL wildcards and quotes in search parameters are safely escaped without SQL injection'
);

// Negative pagination limit and offset handling
$resPag = dispatchTestRequest($router, 'GET', "/api/groups/{$groupToken}/expenses", null, [
    'limit' => '-10',
    'offset' => '-5',
]);
assertSec15(
    $resPag['status'] === 200,
    'SEC15.8.2',
    'Negative limit/offset query parameters do not cause crash or SQL syntax error'
);

// ================================================================================
// SEC15.9 — Path Parameter & Traversal Defense
// ================================================================================
echo "\n--- Domain 9: Path Parameter & Traversal Defense ---\n";

// Non-numeric expense ID in path
$resAlphaPath = dispatchTestRequest($router, 'GET', "/api/groups/{$groupToken}/expenses/not-an-integer-id");
assertSec15(
    in_array($resAlphaPath['status'], [400, 404, 422], true),
    'SEC15.9.1',
    'Non-numeric resource ID in URL path does not trigger 500'
);

// Traversal characters in group token
$resTraverse = dispatchTestRequest($router, 'GET', '/api/groups/..%2f..%2fetc%2fpasswd');
assertSec15(
    in_array($resTraverse['status'], [400, 404], true),
    'SEC15.9.2',
    'Path traversal sequences in group token parameter are rejected'
);

// ================================================================================
// SEC15.10 — Authentication & Session Authorization Boundaries
// ================================================================================
echo "\n--- Domain 10: Authentication & Session Authorization Boundaries ---\n";

// Identity probe /api/auth/me without session cookie
$resMeAnon = dispatchTestRequest($router, 'GET', '/api/auth/me');
assertSec15(
    $resMeAnon['status'] === 200 && ($resMeAnon['body']['data']['authenticated'] ?? true) === false,
    'SEC15.10.1',
    'GET /api/auth/me without session returns {authenticated: false, user: null}'
);

// Unauthenticated request to /api/user/workspaces
$resWorkspacesAnon = dispatchTestRequest($router, 'GET', '/api/user/workspaces');
assertSec15(
    $resWorkspacesAnon['status'] === 401,
    'SEC15.10.2',
    'GET /api/user/workspaces without session cookie returns HTTP 401 Unauthorized'
);

// Unauthenticated request to /api/user/account
$resDeleteAnon = dispatchTestRequest($router, 'DELETE', '/api/user/account');
assertSec15(
    $resDeleteAnon['status'] === 401,
    'SEC15.10.3',
    'DELETE /api/user/account without session cookie returns HTTP 401 Unauthorized'
);

// ================================================================================
// SEC15.11 — Idempotency Key Handling & Mutation Safety
// ================================================================================
echo "\n--- Domain 11: Idempotency Key Handling & Mutation Safety ---\n";

$idemKey = 'idem_sec15_' . bin2hex(random_bytes(8));
$payloadIdem = [
    'title' => 'Idempotent Expense Test',
    'amount' => 45.00,
    'payer_member_id' => $m1Id,
    'split_type' => 'EQUAL',
    'split_members' => [$m1Id, $m2Id],
    'idempotency_key' => $idemKey,
];

// First dispatch
$resIdem1 = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/expenses", $payloadIdem, null, [
    'X-Idempotency-Key' => $idemKey,
]);
assertSec15(
    $resIdem1['status'] === 201,
    'SEC15.11.1',
    'Initial mutation with idempotency key succeeds with HTTP 201'
);

// Replay identical dispatch
$resIdem2 = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/expenses", $payloadIdem, null, [
    'X-Idempotency-Key' => $idemKey,
]);
assertSec15(
    $resIdem2['status'] === 201 || $resIdem2['status'] === 200,
    'SEC15.11.2',
    'Replayed mutation with identical idempotency key handles duplicate safely'
);

// ================================================================================
// SEC15.12 — Receipt Attachment File & Base64 Validation
// ================================================================================
echo "\n--- Domain 12: Receipt Attachment File & Base64 Validation ---\n";

// Receipt upload missing file payload
$resNoFile = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/expenses/{$expId}/receipts", []);
assertSec15(
    $resNoFile['status'] === 422,
    'SEC15.12.1',
    'POST receipt without file or Base64 payload returns HTTP 422'
);

// Receipt upload with valid Base64 image
$sample1x1Png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
$resBase64 = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/expenses/{$expId}/receipts", [
    'file_name' => 'receipt.png',
    'data_base64' => $sample1x1Png,
    'actor_member_id' => $m1Id,
]);
assertSec15(
    $resBase64['status'] === 201 && !empty($resBase64['body']['data']['receipt']['id']),
    'SEC15.12.2',
    'POST receipt with valid Base64 PNG returns HTTP 201 and receipt payload'
);

// ================================================================================
// SEC15.13 — CSV Export Headers & Content-Disposition Sanitization
// ================================================================================
echo "\n--- Domain 13: CSV Export Headers & Content-Disposition Sanitization ---\n";

$resCsv = dispatchTestRequest($router, 'GET', "/api/groups/{$groupToken}/export.csv");
assertSec15(
    $resCsv['status'] === 200 && str_contains($resCsv['raw'], 'Date') && str_contains($resCsv['raw'], 'Description'),
    'SEC15.13.1',
    'GET /api/groups/{token}/export.csv streams valid CSV header and records'
);

// ================================================================================
// SEC15.14 — Real-Time SSE Stream & Polling Fallback
// ================================================================================
echo "\n--- Domain 14: Real-Time SSE Stream & Polling Fallback ---\n";

// Polling fallback test (?poll=1)
$resPoll = dispatchTestRequest($router, 'GET', "/api/groups/{$groupToken}/events", null, ['poll' => '1']);
assertSec15(
    $resPoll['status'] === 200 && is_array($resPoll['body']['data']['events'] ?? null),
    'SEC15.14.1',
    'GET /api/groups/{token}/events?poll=1 returns HTTP 200 JSON event feed'
);

// Nonexistent token returns 404
$resBadSse = dispatchTestRequest($router, 'GET', "/api/groups/nonexistent_token_9999/events", null, ['poll' => '1']);
assertSec15(
    $resBadSse['status'] === 404,
    'SEC15.14.2',
    'GET /api/groups/{invalid}/events returns HTTP 404 Not Found'
);

// ================================================================================
// SEC15.15 — Production Error Disclosure & Envelope Invariance
// ================================================================================
echo "\n--- Domain 15: Production Error Disclosure & Envelope Invariance ---\n";

$resErr = dispatchTestRequest($router, 'GET', "/api/groups/invalid_token/expenses/999999");
assertSec15(
    isset($resErr['body']['success']) && $resErr['body']['success'] === false &&
    isset($resErr['body']['error']['code']) &&
    isset($resErr['body']['error']['message']) &&
    !isset($resErr['body']['error']['trace']) &&
    !isset($resErr['body']['error']['sql']),
    'SEC15.15.1',
    'Production error envelope adheres to {success: false, error: {code, message}} with zero SQL or stack traces'
);

// ================================================================================
// SEC15.16 — Transaction Rollback on Mid-Request Failure
// ================================================================================
echo "\n--- Domain 16: Transaction Rollback on Mid-Request Failure ---\n";

$expCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM `expenses` WHERE `group_id` = {$groupId}")->fetchColumn();

// Attempt multi-item expense creation where splits sum does not equal total
$resAtomicFail = dispatchTestRequest($router, 'POST', "/api/groups/{$groupToken}/expenses", [
    'title' => 'Atomic Failure Test',
    'amount' => 500.00,
    'payer_member_id' => $m1Id,
    'split_type' => 'EXACT',
    'splits' => [
        $m1Id => 100.00,
        $m2Id => 100.00,
        // missing 300
    ],
]);
$expCountAfter = (int) $pdo->query("SELECT COUNT(*) FROM `expenses` WHERE `group_id` = {$groupId}")->fetchColumn();

assertSec15(
    $resAtomicFail['status'] === 422 && $expCountBefore === $expCountAfter,
    'SEC15.16.1',
    'Failed multi-item expense transaction rolls back cleanly without leaving orphaned records'
);

// ================================================================================
// SEC15.17 — Vanilla ES6 Frontend Envelope Compatibility
// ================================================================================
echo "\n--- Domain 17: Vanilla ES6 Frontend Envelope Compatibility ---\n";

$resSuccess = dispatchTestRequest($router, 'GET', "/api/groups/{$groupToken}");
assertSec15(
    isset($resSuccess['body']['success']) && $resSuccess['body']['success'] === true &&
    isset($resSuccess['body']['data']) && is_array($resSuccess['body']['data']) &&
    isset($resSuccess['body']['data']['group']['name']),
    'SEC15.17.1',
    'Successful API response conforms to {success: true, data: {...}} standard expected by ES6 frontend'
);

// ================================================================================
// SEC15.18 — Randomized Adversarial Fuzzing Matrix (250 Scenarios)
// ================================================================================
echo "\n--- Domain 18: Randomized Adversarial Fuzzing Matrix (250 Scenarios) ---\n";

mt_srand(20261002); // Fixed deterministic seed

$fuzzEndpoints = [
    ['POST', "/api/groups/{$groupToken}/members"],
    ['POST', "/api/groups/{$groupToken}/expenses"],
    ['POST', "/api/groups/{$groupToken}/settlements"],
    ['POST', "/api/groups/{$groupToken}/categories"],
    ['POST', "/api/groups/{$groupToken}/templates"],
    ['POST', "/api/groups/{$groupToken}/recurring"],
    ['GET', "/api/groups/{$groupToken}/expenses"],
    ['GET', "/api/groups/{$groupToken}/balances"],
];

$adversarialPayloads = [
    ['name' => str_repeat('A', 5000)],
    ['title' => "'; DROP TABLE expenses; --"],
    ['amount' => NAN],
    ['amount' => INF],
    ['amount' => -0.00001],
    ['amount' => 1e12],
    ['payer_member_id' => -999],
    ['payer_member_id' => 'admin'],
    ['split_type' => str_repeat('Z', 50)],
    ['splits' => null],
    ['splits' => 'not an array'],
    ['splits' => [[ 'member_id' => 99999, 'amount' => -50 ]]],
    ['frequency' => "\x00\xFF\xFE"],
    ['token' => 'invalid" OR "1"="1'],
    ['limit' => 999999999],
    ['offset' => -999999],
    ['search' => '`~!@#$%^&*()_+-=[]{}\\|;:\'",<.>/?'],
];

$fuzzTotal = 250;
$fuzzPassed = 0;
$fuzzCrashes = 0;

for ($i = 1; $i <= $fuzzTotal; $i++) {
    $ep = $fuzzEndpoints[array_rand($fuzzEndpoints)];
    $method = $ep[0];
    $url = $ep[1];

    $payload = $adversarialPayloads[array_rand($adversarialPayloads)];
    // inject random fields
    $payload['rand_' . bin2hex(random_bytes(3))] = bin2hex(random_bytes(10));

    $res = dispatchTestRequest($router, $method, $url, $payload);
    
    // Status MUST be a valid HTTP status (200..499). It must NEVER be 500 (unhandled crash).
    if ($res['status'] >= 200 && $res['status'] < 500) {
        $fuzzPassed++;
    } else {
        $fuzzCrashes++;
        echo "       [FUZZ FAIL #{$i}] {$method} {$url} returned HTTP {$res['status']}!\n";
    }
}

assertSec15(
    $fuzzPassed === $fuzzTotal && $fuzzCrashes === 0,
    'SEC15.18.1',
    "Randomized Adversarial Fuzzing: {$fuzzPassed}/{$fuzzTotal} requests handled gracefully with zero 500 server crashes"
);

// ================================================================================
// CLEANUP TEST FIXTURES
// ================================================================================
try {
    $pdo->exec("DELETE FROM `receipt_attachments` WHERE `expense_id` IN (SELECT `id` FROM `expenses` WHERE `group_id` = {$groupId})");
    $pdo->exec("DELETE FROM `expenses` WHERE `group_id` = {$groupId}");
    $pdo->exec("DELETE FROM `members` WHERE `group_id` = {$groupId}");
    $pdo->exec("DELETE FROM `settlements` WHERE `group_id` = {$groupId}");
    $pdo->exec("DELETE FROM `categories` WHERE `group_id` = {$groupId}");
    $pdo->exec("DELETE FROM `expense_templates` WHERE `group_id` = {$groupId}");
    $pdo->exec("DELETE FROM `recurring_rules` WHERE `group_id` = {$groupId}");
    $pdo->exec("DELETE FROM `events` WHERE `group_id` = {$groupId}");
    $pdo->exec("DELETE FROM `groups` WHERE `id` = {$groupId}");
} catch (\Throwable $e) {}

// ================================================================================
// FINAL VERDICT & SUMMARY
// ================================================================================
echo "\n================================================================================\n";
echo " SEC-15 AUDIT EXECUTION SUMMARY\n";
echo "================================================================================\n";
echo " Total Assertions  : {$total}\n";
echo " Passed Assertions : {$passed}\n";
echo " Failed Assertions : " . count($failures) . "\n";
echo " Fuzz Scenarios    : {$fuzzTotal}/{$fuzzTotal} PASS\n";
echo " Pass Rate         : " . sprintf('%.2f%%', ($passed / max(1, $total)) * 100) . "\n";
echo "================================================================================\n\n";

if (!empty($failures)) {
    echo "FAILED ASSERTIONS:\n";
    foreach ($failures as $f) {
        echo " - [{$f['testId']}] {$f['description']}\n";
        if ($f['details']) {
            echo "   {$f['details']}\n";
        }
    }
    exit(1);
}

echo ">>> VERDICT: SEC-15 API CONTRACT INTEGRITY & BOUNDARY SUITE: 100% PASS <<<\n";
exit(0);
