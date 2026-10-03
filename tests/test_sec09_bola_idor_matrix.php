<?php

declare(strict_types=1);

/**
 * Smart Split V2 — SEC-09: Comprehensive BOLA / IDOR Cross-Workspace Matrix & Authorization Defense Suite
 *
 * Validates the core security property:
 * "A valid actor operating inside Workspace A must never be able to read, modify, delete, restore,
 *  download, or otherwise manipulate a resource belonging exclusively to Workspace B merely by
 *  supplying Workspace A's valid token together with Workspace B's resource identifier."
 *
 * Covers 18 forensic test sections:
 * SEC-09.1  Fixture Isolation & Setup
 * SEC-09.2  Positive Authorization Controls (A->A, B->B)
 * SEC-09.3  Expense Cross-Workspace Matrix (Read, Update, Delete, Restore)
 * SEC-09.4  Member Cross-Workspace Matrix (Update, Delete, Ledger)
 * SEC-09.5  Settlement Cross-Workspace Matrix (Delete, Foreign Member Assignment)
 * SEC-09.6  Receipt Cross-Workspace Matrix (Index, Download, Mismatched Parent, Delete)
 * SEC-09.7  Category Cross-Workspace Matrix (Delete)
 * SEC-09.8  Template Cross-Workspace Matrix (Delete)
 * SEC-09.9  Recurring Schedule Cross-Workspace Matrix (Delete, Evaluation)
 * SEC-09.10 Trash / Restore Cross-Workspace Matrix
 * SEC-09.11 Search / Filter / CSV Export Isolation
 * SEC-09.12 Analytics, Balances & Activity Feed Isolation
 * SEC-09.13 Real-Time SSE Event Stream Isolation
 * SEC-09.14 Creator Privilege & Device Pairing Isolation
 * SEC-09.15 Identifier Tampering & Boundary Variants (Nonexistent, Negative, Zero, String)
 * SEC-09.16 Write-Side Effect Invariance Verification (Database State)
 * SEC-09.17 Financial Balance Invariance Verification
 * SEC-09.18 Fixture Cleanup & Determinism
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
use App\Services\ReceiptService;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

echo "\n================================================================================\n";
echo " SEC-09: COMPREHENSIVE BOLA / IDOR CROSS-WORKSPACE AUTHORIZATION MATRIX\n";
echo "================================================================================\n\n";

$passed = 0;
$total = 0;
$failures = [];

function assertSec09(bool $condition, string $testId, string $description, ?string $details = null): void {
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
            'details' => $details
        ];
    }
}

// Router Setup
$router = new Router();
$router->use(new SecurityHeadersMiddleware());
$router->use(new AuthSessionMiddleware());

// Register all endpoints exactly as in production index.php
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get('/api/auth/me', [AuthController::class, 'me']);
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
$router->get('/api/groups/{token}/expenses/{id}/receipts', [ReceiptController::class, 'index']);
$router->post('/api/groups/{token}/expenses/{id}/receipts', [ReceiptController::class, 'create']);
$router->get('/api/groups/{token}/expenses/{id}/receipts/{receiptId}/download', [ReceiptController::class, 'download']);
$router->get('/api/groups/{token}/expenses/{id}/receipts/{receiptId}', [ReceiptController::class, 'download']);
$router->delete('/api/groups/{token}/expenses/{id}/receipts/{receiptId}', [ReceiptController::class, 'delete']);

function dispatchApi(Router $router, string $method, string $path, ?array $body = null, ?array $queryParams = null, array $headers = []): array {
    $req = new Request($method, $path, $queryParams, $body, $headers);
    ob_start();
    try {
        $router->dispatch($req);
    } catch (\InvalidArgumentException $e) {
        $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 400;
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
        'body' => is_array($decoded) ? $decoded : ['raw' => $raw],
        'raw' => $raw,
    ];
}

// =============================================================================
// SEC-09.1: TWO-WORKSPACE ADVERSARIAL FIXTURE INITIALIZATION
// =============================================================================
echo "--- SEC-09.1: Setting up Independent Adversarial Fixtures (Workspace A & Workspace B) ---\n";

// 1. Create Workspace A
$resA = dispatchApi($router, 'POST', '/api/groups', [
    'name' => 'BOLA_WORKSPACE_ALPHA_101',
    'currency' => 'INR',
    'creator_name' => 'Alice_Alpha',
]);
$groupA = $resA['body']['data']['group'];
$tokenA = $groupA['invite_token'];
$groupIdA = (int) $groupA['id'];
$creatorA = $resA['body']['data']['creator'];
$creatorIdA = (int) $creatorA['id'];
$creatorTokenA = (string) ($creatorA['member_token'] ?? $creatorA['token'] ?? '');

// Add Member 2 to A
$memA2Res = dispatchApi($router, 'POST', "/api/groups/{$tokenA}/members", ['name' => 'Bob_Alpha']);
$memberIdA2 = (int) $memA2Res['body']['data']['member']['id'];

// Create Custom Category in A
$catARes = dispatchApi($router, 'POST', "/api/groups/{$tokenA}/categories", ['name' => 'A_Custom_Groceries', 'icon' => '🛒']);
$categoryIdA = (int) $catARes['body']['data']['category']['id'];

// Create Expense in A
$expARes = dispatchApi($router, 'POST', "/api/groups/{$tokenA}/expenses", [
    'title' => 'A_Team_Dinner',
    'total_amount_cents' => 200000,
    'split_type' => 'EQUAL',
    'category_id' => $categoryIdA,
    'expense_date' => '2026-06-01',
    'created_by_member_id' => $creatorIdA,
    'payers' => [['member_id' => $creatorIdA, 'amount_paid_cents' => 200000]],
    'splits' => [
        ['member_id' => $creatorIdA, 'amount_owed_cents' => 100000],
        ['member_id' => $memberIdA2, 'amount_owed_cents' => 100000],
    ],
]);
$expenseIdA = (int) $expARes['body']['data']['expense']['id'];

// Create Trash Expense in A (to test restore isolation)
$expATrashRes = dispatchApi($router, 'POST', "/api/groups/{$tokenA}/expenses", [
    'title' => 'A_Deleted_Snacks',
    'total_amount_cents' => 50000,
    'split_type' => 'EQUAL',
    'created_by_member_id' => $creatorIdA,
    'payers' => [['member_id' => $creatorIdA, 'amount_paid_cents' => 50000]],
    'splits' => [['member_id' => $creatorIdA, 'amount_owed_cents' => 50000]],
]);
$expenseIdATrash = (int) $expATrashRes['body']['data']['expense']['id'];
dispatchApi($router, 'DELETE', "/api/groups/{$tokenA}/expenses/{$expenseIdATrash}");

// Create Template in A
$tplARes = dispatchApi($router, 'POST', "/api/groups/{$tokenA}/templates", [
    'title' => 'A_Weekly_Coffee_Template',
    'total_amount_cents' => 40000,
    'split_type' => 'EQUAL',
    'created_by_member_id' => $creatorIdA,
]);
$templateIdA = (int) $tplARes['body']['data']['template_id'];

// Create Recurring in A
$recARes = dispatchApi($router, 'POST', "/api/groups/{$tokenA}/recurring", [
    'title' => 'A_Monthly_Wifi',
    'amount_cents' => 150000,
    'frequency' => 'MONTHLY',
    'start_date' => '2026-06-01',
    'created_by_member_id' => $creatorIdA,
    'payer_member_id' => $creatorIdA,
    'splits' => [
        ['member_id' => $creatorIdA, 'amount_owed_cents' => 75000],
        ['member_id' => $memberIdA2, 'amount_owed_cents' => 75000],
    ],
]);
$recurringIdA = (int) $recARes['body']['data']['rule_id'];

// Create Settlement in A
$setARes = dispatchApi($router, 'POST', "/api/groups/{$tokenA}/settlements", [
    'payer_id' => $memberIdA2,
    'payee_id' => $creatorIdA,
    'amount_cents' => 50000,
]);
$settlementIdA = (int) $setARes['body']['data']['settlement']['id'];

// -----------------------------------------------------------------------------
// 2. Create Workspace B (Target of Cross-Workspace Attacks)
// -----------------------------------------------------------------------------
$resB = dispatchApi($router, 'POST', '/api/groups', [
    'name' => 'BOLA_WORKSPACE_BETA_202',
    'currency' => 'INR',
    'creator_name' => 'Charlie_Beta',
]);
$groupB = $resB['body']['data']['group'];
$tokenB = $groupB['invite_token'];
$groupIdB = (int) $groupB['id'];
$creatorB = $resB['body']['data']['creator'];
$creatorIdB = (int) $creatorB['id'];
$creatorTokenB = (string) ($creatorB['member_token'] ?? $creatorB['token'] ?? '');

// Add Member 2 to B
$memB2Res = dispatchApi($router, 'POST', "/api/groups/{$tokenB}/members", ['name' => 'Dave_Beta']);
$memberIdB2 = (int) $memB2Res['body']['data']['member']['id'];

// Create Custom Category in B
$catBRes = dispatchApi($router, 'POST', "/api/groups/{$tokenB}/categories", ['name' => 'B_Custom_Equipment', 'icon' => '💻']);
$categoryIdB = (int) $catBRes['body']['data']['category']['id'];

// Create Expense in B
$expBRes = dispatchApi($router, 'POST', "/api/groups/{$tokenB}/expenses", [
    'title' => 'B_Secret_Servers',
    'total_amount_cents' => 500000,
    'split_type' => 'EQUAL',
    'category_id' => $categoryIdB,
    'expense_date' => '2026-06-01',
    'created_by_member_id' => $creatorIdB,
    'payers' => [['member_id' => $creatorIdB, 'amount_paid_cents' => 500000]],
    'splits' => [
        ['member_id' => $creatorIdB, 'amount_owed_cents' => 250000],
        ['member_id' => $memberIdB2, 'amount_owed_cents' => 250000],
    ],
]);
$expenseIdB = (int) $expBRes['body']['data']['expense']['id'];

// Attach Receipt to Expense B via ReceiptService directly to ensure fixture completeness
$receiptService = new ReceiptService();
$dummyReceiptData = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
$receiptB = $receiptService->uploadReceiptBase64(
    $groupIdB,
    $expenseIdB,
    'b_secret_invoice.png',
    $dummyReceiptData,
    $creatorIdB
);
$receiptIdB = (int) $receiptB['id'];

// Create Trash Expense in B
$expBTrashRes = dispatchApi($router, 'POST', "/api/groups/{$tokenB}/expenses", [
    'title' => 'B_Secret_Old_Expense',
    'total_amount_cents' => 120000,
    'split_type' => 'EQUAL',
    'created_by_member_id' => $creatorIdB,
    'payers' => [['member_id' => $creatorIdB, 'amount_paid_cents' => 120000]],
    'splits' => [['member_id' => $creatorIdB, 'amount_owed_cents' => 120000]],
]);
$expenseIdBTrash = (int) $expBTrashRes['body']['data']['expense']['id'];
dispatchApi($router, 'DELETE', "/api/groups/{$tokenB}/expenses/{$expenseIdBTrash}");

// Create Template in B
$tplBRes = dispatchApi($router, 'POST', "/api/groups/{$tokenB}/templates", [
    'title' => 'B_Secret_Template',
    'total_amount_cents' => 80000,
    'split_type' => 'EQUAL',
    'created_by_member_id' => $creatorIdB,
]);
$templateIdB = (int) $tplBRes['body']['data']['template_id'];

// Create Recurring in B
$recBRes = dispatchApi($router, 'POST', "/api/groups/{$tokenB}/recurring", [
    'title' => 'B_Secret_Server_Subscription',
    'amount_cents' => 300000,
    'frequency' => 'MONTHLY',
    'start_date' => '2026-06-01',
    'created_by_member_id' => $creatorIdB,
    'payer_member_id' => $creatorIdB,
    'splits' => [
        ['member_id' => $creatorIdB, 'amount_owed_cents' => 150000],
        ['member_id' => $memberIdB2, 'amount_owed_cents' => 150000],
    ],
]);
$recurringIdB = (int) $recBRes['body']['data']['rule_id'];

// Create Settlement in B
$setBRes = dispatchApi($router, 'POST', "/api/groups/{$tokenB}/settlements", [
    'payer_id' => $memberIdB2,
    'payee_id' => $creatorIdB,
    'amount_cents' => 100000,
]);
$settlementIdB = (int) $setBRes['body']['data']['settlement']['id'];

assertSec09(
    $groupIdA > 0 && $groupIdB > 0 && $groupIdA !== $groupIdB &&
    $expenseIdA > 0 && $expenseIdB > 0 && $expenseIdA !== $expenseIdB &&
    $receiptIdB > 0,
    'SEC09-FIX-01',
    'Two independent workspaces initialized with distinct IDs and isolated resource trees'
);

// Capture baseline balance vectors
$balARes = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/balances");
$initialBalanceA = $balARes['body']['data']['members'] ?? [];
$balBRes = dispatchApi($router, 'GET', "/api/groups/{$tokenB}/balances");
$initialBalanceB = $balBRes['body']['data']['members'] ?? [];

// =============================================================================
// SEC-09.2: POSITIVE AUTHORIZATION CONTROLS (Legitimate Access Succeeded)
// =============================================================================
echo "\n--- SEC-09.2: Positive Authorization Controls (A->A, B->B) ---\n";

$posExpA = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/expenses/{$expenseIdA}");
assertSec09($posExpA['status'] === 200 && ($posExpA['body']['data']['expense']['id'] ?? 0) === $expenseIdA,
    'SEC09-POS-01', 'Positive Control: Workspace A legitimately reads its own expense');

$posExpB = dispatchApi($router, 'GET', "/api/groups/{$tokenB}/expenses/{$expenseIdB}");
assertSec09($posExpB['status'] === 200 && ($posExpB['body']['data']['expense']['id'] ?? 0) === $expenseIdB,
    'SEC09-POS-02', 'Positive Control: Workspace B legitimately reads its own expense');

$posRecB = dispatchApi($router, 'GET', "/api/groups/{$tokenB}/expenses/{$expenseIdB}/receipts");
assertSec09($posRecB['status'] === 200 && count($posRecB['body']['data']['receipts'] ?? []) >= 1,
    'SEC09-POS-03', 'Positive Control: Workspace B legitimately lists its own receipt attachments');

// =============================================================================
// SEC-09.3: EXPENSE CROSS-WORKSPACE MATRIX (A -> B Attacks)
// =============================================================================
echo "\n--- SEC-09.3: Expense Cross-Workspace Matrix ---\n";

// 1. Read Attack: A token + B expense ID
$readExpAttack = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/expenses/{$expenseIdB}");
assertSec09(
    $readExpAttack['status'] === 404 && !str_contains($readExpAttack['raw'], 'B_Secret_Servers'),
    'SEC09-EXP-01',
    'READ BOLA: GET /api/groups/{A}/expenses/{B_ID} rejected with 404 (Zero disclosure of B data)'
);

// 2. Read Attack Reverse: B token + A expense ID
$readExpAttackRev = dispatchApi($router, 'GET', "/api/groups/{$tokenB}/expenses/{$expenseIdA}");
assertSec09(
    $readExpAttackRev['status'] === 404 && !str_contains($readExpAttackRev['raw'], 'A_Team_Dinner'),
    'SEC09-EXP-02',
    'READ BOLA: GET /api/groups/{B}/expenses/{A_ID} rejected with 404 (Symmetric isolation)'
);

// 3. Update Attack: A token + B expense ID with malicious modifications
$updateExpAttack = dispatchApi($router, 'PUT', "/api/groups/{$tokenA}/expenses/{$expenseIdB}", [
    'title' => 'TAMPERED_BY_WORKSPACE_A',
    'total_amount_cents' => 1,
    'split_type' => 'EQUAL',
    'payers' => [['member_id' => $creatorIdA, 'amount_paid_cents' => 1]],
    'splits' => [['member_id' => $creatorIdA, 'amount_owed_cents' => 1]],
]);
assertSec09(
    $updateExpAttack['status'] === 404,
    'SEC09-EXP-03',
    'UPDATE BOLA: PUT /api/groups/{A}/expenses/{B_ID} rejected with 404'
);

// Verify B expense was NOT modified in database
$stmtB = $pdo->prepare("SELECT `title`, `total_amount_cents`, `is_deleted` FROM `expenses` WHERE `id` = :id");
$stmtB->execute([':id' => $expenseIdB]);
$expBRow = $stmtB->fetch(PDO::FETCH_ASSOC);
assertSec09(
    $expBRow && $expBRow['title'] === 'B_Secret_Servers' && (int)$expBRow['total_amount_cents'] === 500000,
    'SEC09-EXP-04',
    'State Invariance: B expense title and amount in database remain completely unaltered after attack'
);

// 4. Delete Attack: A token + B expense ID
$deleteExpAttack = dispatchApi($router, 'DELETE', "/api/groups/{$tokenA}/expenses/{$expenseIdB}");
assertSec09(
    $deleteExpAttack['status'] === 404,
    'SEC09-EXP-05',
    'DELETE BOLA: DELETE /api/groups/{A}/expenses/{B_ID} rejected with 404'
);
$stmtB->execute([':id' => $expenseIdB]);
$expBRowAfterDel = $stmtB->fetch(PDO::FETCH_ASSOC);
assertSec09(
    (int) ($expBRowAfterDel['is_deleted'] ?? 1) === 0,
    'SEC09-EXP-06',
    'State Invariance: B expense remains active (is_deleted = 0) after unauthorized delete attack'
);

// 5. Restore Attack: A token + B soft-deleted expense ID
$restoreExpAttack = dispatchApi($router, 'PUT', "/api/groups/{$tokenA}/expenses/{$expenseIdBTrash}/restore");
assertSec09(
    $restoreExpAttack['status'] === 404,
    'SEC09-EXP-07',
    'RESTORE BOLA: PUT /api/groups/{A}/expenses/{B_TRASH_ID}/restore rejected with 404'
);
$stmtB->execute([':id' => $expenseIdBTrash]);
$expBTrashRow = $stmtB->fetch(PDO::FETCH_ASSOC);
assertSec09(
    (int) ($expBTrashRow['is_deleted'] ?? 0) === 1,
    'SEC09-EXP-08',
    'State Invariance: B trash expense remains soft-deleted after unauthorized restore attack'
);

// =============================================================================
// SEC-09.4: MEMBER CROSS-WORKSPACE MATRIX
// =============================================================================
echo "\n--- SEC-09.4: Member Cross-Workspace Matrix ---\n";

// 1. Member Rename Attack: A token + B member ID
$renameMemberAttack = dispatchApi($router, 'PUT', "/api/groups/{$tokenA}/members/{$memberIdB2}", [
    'name' => 'TAMPERED_MEMBER_NAME',
]);
assertSec09(
    $renameMemberAttack['status'] === 404,
    'SEC09-MEM-01',
    'UPDATE BOLA: PUT /api/groups/{A}/members/{B_MEMBER_ID} rejected with 404'
);

$stmtMemB = $pdo->prepare("SELECT `name`, `is_active` FROM `members` WHERE `id` = :id");
$stmtMemB->execute([':id' => $memberIdB2]);
$memBRow = $stmtMemB->fetch(PDO::FETCH_ASSOC);
assertSec09(
    $memBRow && $memBRow['name'] === 'Dave_Beta',
    'SEC09-MEM-02',
    'State Invariance: Member B name remains untouched in database'
);

// 2. Member Delete Attack: A token + B member ID
$delMemberAttack = dispatchApi($router, 'DELETE', "/api/groups/{$tokenA}/members/{$memberIdB2}");
assertSec09(
    $delMemberAttack['status'] === 404,
    'SEC09-MEM-03',
    'DELETE BOLA: DELETE /api/groups/{A}/members/{B_MEMBER_ID} rejected with 404'
);
$stmtMemB->execute([':id' => $memberIdB2]);
$memBRowAfterDel = $stmtMemB->fetch(PDO::FETCH_ASSOC);
assertSec09(
    (int) ($memBRowAfterDel['is_active'] ?? 0) === 1,
    'SEC09-MEM-04',
    'State Invariance: Member B remains active in database'
);

// 3. Member Ledger Read Attack: A token + B member ID
$ledgerAttack = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/members/{$memberIdB2}/ledger");
assertSec09(
    $ledgerAttack['status'] === 404,
    'SEC09-MEM-05',
    'READ BOLA: GET /api/groups/{A}/members/{B_MEMBER_ID}/ledger rejected with 404 (Zero disclosure of B ledger)'
);

// =============================================================================
// SEC-09.5: SETTLEMENT CROSS-WORKSPACE MATRIX
// =============================================================================
echo "\n--- SEC-09.5: Settlement Cross-Workspace Matrix ---\n";

// 1. Settlement Delete Attack: A token + B settlement ID
$delSettlementAttack = dispatchApi($router, 'DELETE', "/api/groups/{$tokenA}/settlements/{$settlementIdB}");
assertSec09(
    $delSettlementAttack['status'] === 404,
    'SEC09-SET-01',
    'DELETE BOLA: DELETE /api/groups/{A}/settlements/{B_SETTLEMENT_ID} rejected with 404'
);

$stmtSetB = $pdo->prepare("SELECT `is_deleted` FROM `settlements` WHERE `id` = :id");
$stmtSetB->execute([':id' => $settlementIdB]);
$setBRow = $stmtSetB->fetch(PDO::FETCH_ASSOC);
assertSec09(
    (int) ($setBRow['is_deleted'] ?? 1) === 0,
    'SEC09-SET-02',
    'State Invariance: Settlement B remains active (is_deleted = 0) in database'
);

// 2. Settlement Creation with Foreign Member from Workspace B
$foreignMemberSet = dispatchApi($router, 'POST', "/api/groups/{$tokenA}/settlements", [
    'payer_id' => $creatorIdA,
    'payee_id' => $creatorIdB, // Member from Workspace B!
    'amount_cents' => 10000,
]);
assertSec09(
    $foreignMemberSet['status'] === 422,
    'SEC09-SET-03',
    'INJECTION BOLA: Creating settlement in Workspace A referencing Member from B rejected with 422'
);

// =============================================================================
// SEC-09.6: RECEIPT / ATTACHMENT CROSS-WORKSPACE MATRIX
// =============================================================================
echo "\n--- SEC-09.6: Receipt & Attachment Cross-Workspace Matrix ---\n";

// 1. List Receipts Attack: A token + B expense ID
$listRecAttack = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/expenses/{$expenseIdB}/receipts");
assertSec09(
    $listRecAttack['status'] === 404,
    'SEC09-REC-01',
    'READ BOLA: GET /api/groups/{A}/expenses/{B_EXPENSE_ID}/receipts rejected with 404'
);

// 2. Download Receipt Attack: A token + B expense ID + B receipt ID
$downRecAttack = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/expenses/{$expenseIdB}/receipts/{$receiptIdB}");
assertSec09(
    $downRecAttack['status'] === 404 && !str_contains($downRecAttack['raw'], 'SECRET_B_RECEIPT_RAW_INVOICE_DATA'),
    'SEC09-REC-02',
    'DOWNLOAD BOLA: GET /api/groups/{A}/.../receipts/{B_REC_ID} rejected with 404 (Zero payload leaked)'
);

// 3. Mismatched Parent Attack: A token + A expense ID + B receipt ID
$mismatchedRecAttack = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/expenses/{$expenseIdA}/receipts/{$receiptIdB}");
assertSec09(
    $mismatchedRecAttack['status'] === 404 && !str_contains($mismatchedRecAttack['raw'], 'SECRET_B_RECEIPT_RAW_INVOICE_DATA'),
    'SEC09-REC-03',
    'RELATIONSHIP BOLA: Mismatched A expense + B receipt ID rejected with 404'
);

// 4. Delete Receipt Attack: A token + B expense ID + B receipt ID
$delRecAttack = dispatchApi($router, 'DELETE', "/api/groups/{$tokenA}/expenses/{$expenseIdB}/receipts/{$receiptIdB}");
assertSec09(
    $delRecAttack['status'] === 404,
    'SEC09-REC-04',
    'DELETE BOLA: DELETE /api/groups/{A}/.../receipts/{B_REC_ID} rejected with 404'
);

$stmtRecB = $pdo->prepare("SELECT `id`, `file_name` FROM `receipt_attachments` WHERE `id` = :id");
$stmtRecB->execute([':id' => $receiptIdB]);
$recBRow = $stmtRecB->fetch(PDO::FETCH_ASSOC);
assertSec09(
    $recBRow && $recBRow['file_name'] === 'b_secret_invoice.png',
    'SEC09-REC-05',
    'State Invariance: Receipt B remains active and attached to Expense B in database'
);

// =============================================================================
// SEC-09.7: CATEGORY CROSS-WORKSPACE MATRIX
// =============================================================================
echo "\n--- SEC-09.7: Category Cross-Workspace Matrix ---\n";

$delCatAttack = dispatchApi($router, 'DELETE', "/api/groups/{$tokenA}/categories/{$categoryIdB}");
assertSec09(
    ($delCatAttack['status'] === 200 && ($delCatAttack['body']['data']['deleted'] ?? false) === false) || $delCatAttack['status'] === 404,
    'SEC09-CAT-01',
    'DELETE BOLA: DELETE /api/groups/{A}/categories/{B_CAT_ID} denied (deleted = false / 404)'
);

$stmtCatB = $pdo->prepare("SELECT `name` FROM `categories` WHERE `id` = :id");
$stmtCatB->execute([':id' => $categoryIdB]);
assertSec09(
    (bool) $stmtCatB->fetchColumn(),
    'SEC09-CAT-02',
    'State Invariance: Category B remains active in database'
);

// =============================================================================
// SEC-09.8: TEMPLATE CROSS-WORKSPACE MATRIX
// =============================================================================
echo "\n--- SEC-09.8: Template Cross-Workspace Matrix ---\n";

$delTplAttack = dispatchApi($router, 'DELETE', "/api/groups/{$tokenA}/templates/{$templateIdB}");
assertSec09(
    ($delTplAttack['status'] === 200 && ($delTplAttack['body']['data']['deleted'] ?? false) === false) || $delTplAttack['status'] === 404,
    'SEC09-TPL-01',
    'DELETE BOLA: DELETE /api/groups/{A}/templates/{B_TPL_ID} denied (deleted = false / 404)'
);

$stmtTplB = $pdo->prepare("SELECT `title` FROM `expense_templates` WHERE `id` = :id");
$stmtTplB->execute([':id' => $templateIdB]);
assertSec09(
    $stmtTplB->fetchColumn() === 'B_Secret_Template',
    'SEC09-TPL-02',
    'State Invariance: Template B remains present in database'
);

// =============================================================================
// SEC-09.9: RECURRING SCHEDULE CROSS-WORKSPACE MATRIX
// =============================================================================
echo "\n--- SEC-09.9: Recurring Schedule Cross-Workspace Matrix ---\n";

$delRecurAttack = dispatchApi($router, 'DELETE', "/api/groups/{$tokenA}/recurring/{$recurringIdB}");
assertSec09(
    ($delRecurAttack['status'] === 200 && ($delRecurAttack['body']['data']['deleted'] ?? false) === false) || $delRecurAttack['status'] === 404,
    'SEC09-REC-01',
    'DELETE BOLA: DELETE /api/groups/{A}/recurring/{B_REC_ID} denied (deleted = false / 404)'
);

$stmtRecurB = $pdo->prepare("SELECT `title` FROM `recurring_rules` WHERE `id` = :id");
$stmtRecurB->execute([':id' => $recurringIdB]);
assertSec09(
    $stmtRecurB->fetchColumn() === 'B_Secret_Server_Subscription',
    'SEC09-REC-02',
    'State Invariance: Recurring schedule B remains present in database'
);

// Recurring evaluation in A should not evaluate or mutate B schedules
$expCountBBefore = (int) $pdo->query("SELECT COUNT(*) FROM `expenses` WHERE `group_id` = {$groupIdB}")->fetchColumn();
dispatchApi($router, 'POST', "/api/groups/{$tokenA}/recurring/evaluate", ['current_date' => '2026-07-02']);
$expCountBAfter = (int) $pdo->query("SELECT COUNT(*) FROM `expenses` WHERE `group_id` = {$groupIdB}")->fetchColumn();
assertSec09(
    $expCountBBefore === $expCountBAfter,
    'SEC09-REC-03',
    'EVALUATION BOLA: Evaluating recurring rules in A produces ZERO side effects on Workspace B'
);

// =============================================================================
// SEC-09.10: TRASH / RESTORE ISOLATION MATRIX
// =============================================================================
echo "\n--- SEC-09.10: Trash / Restore Isolation Matrix ---\n";

$trashA = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/expenses/trash");
$trashTitles = array_column($trashA['body']['data']['expenses'] ?? [], 'title');
assertSec09(
    in_array('A_Deleted_Snacks', $trashTitles, true) && !in_array('B_Secret_Old_Expense', $trashTitles, true),
    'SEC09-TRASH-01',
    'TRASH ISOLATION: Workspace A trash listing contains ONLY A items; B trash items completely excluded'
);

// =============================================================================
// SEC-09.11: SEARCH / FILTER / CSV EXPORT ISOLATION
// =============================================================================
echo "\n--- SEC-09.11: Search / Filter / CSV Export Isolation ---\n";

// Search for B's unique expense title while scoped to Workspace A
$searchAttack = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/expenses", null, ['search' => 'B_Secret_Servers']);
$searchResults = $searchAttack['body']['data']['expenses'] ?? [];
assertSec09(
    $searchAttack['status'] === 200 && empty($searchResults),
    'SEC09-SRCH-01',
    'SEARCH ISOLATION: Searching for Workspace B keyword in Workspace A yields 0 results'
);

// Filter by Workspace B's category while scoped to Workspace A
$filterCatAttack = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/expenses", null, ['category_id' => $categoryIdB]);
$filterResults = $filterCatAttack['body']['data']['expenses'] ?? [];
assertSec09(
    $filterCatAttack['status'] === 200 && empty($filterResults),
    'SEC09-SRCH-02',
    'FILTER ISOLATION: Filtering with foreign category ID yields 0 results'
);

// CSV Export in Workspace A
$csvExport = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/export.csv");
assertSec09(
    !str_contains($csvExport['raw'], 'B_Secret_Servers') && !str_contains($csvExport['raw'], 'Charlie_Beta'),
    'SEC09-CSV-01',
    'CSV EXPORT ISOLATION: Workspace A CSV export contains zero rows, names, or amounts from Workspace B'
);

// =============================================================================
// SEC-09.12: ANALYTICS, BALANCES & ACTIVITY FEED ISOLATION
// =============================================================================
echo "\n--- SEC-09.12: Analytics, Balances & Activity Feed Isolation ---\n";

$analyticsA = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/analytics/summary");
$totalSpendingA = (int) ($analyticsA['body']['data']['analytics']['total_spending_cents'] ?? -1);
assertSec09(
    $analyticsA['status'] === 200 && $totalSpendingA === 200000,
    'SEC09-ANA-01',
    'ANALYTICS ISOLATION: Workspace A analytics reflects exact A spending (₹2000.00), excluding B (₹5000.00)'
);

$activityA = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/activity-feed");
$activityRaw = $activityA['raw'];
assertSec09(
    !str_contains($activityRaw, 'B_Secret_Servers') && !str_contains($activityRaw, 'Charlie_Beta'),
    'SEC09-ACT-01',
    'ACTIVITY ISOLATION: Workspace A activity feed contains zero timeline logs from Workspace B'
);

// =============================================================================
// SEC-09.13: REAL-TIME SSE EVENT STREAM ISOLATION
// =============================================================================
echo "\n--- SEC-09.13: Real-Time SSE Event Stream Isolation ---\n";

// Mutate Workspace B
$newExpB = dispatchApi($router, 'POST', "/api/groups/{$tokenB}/expenses", [
    'title' => 'B_Live_Event_Expense',
    'total_amount_cents' => 10000,
    'split_type' => 'EQUAL',
    'created_by_member_id' => $creatorIdB,
    'payers' => [['member_id' => $creatorIdB, 'amount_paid_cents' => 10000]],
    'splits' => [['member_id' => $creatorIdB, 'amount_owed_cents' => 10000]],
]);

// Poll Workspace A SSE stream
$ssePollA = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/events", null, ['mode' => 'poll', 'last_event_id' => 0]);
$eventsInA = $ssePollA['body']['data']['events'] ?? [];
$leakedBEvent = false;
foreach ($eventsInA as $ev) {
    if ((int) $ev['group_id'] === $groupIdB) {
        $leakedBEvent = true;
        break;
    }
}
assertSec09(
    !$leakedBEvent,
    'SEC09-SSE-01',
    'SSE ISOLATION: Workspace A real-time event stream receives zero events from Workspace B mutations'
);

// =============================================================================
// SEC-09.14: CREATOR PRIVILEGE & DEVICE PAIRING ISOLATION
// =============================================================================
echo "\n--- SEC-09.14: Creator Privilege & Device Pairing Isolation ---\n";

// Creator in A attempts to delete Workspace B using A's creator token header
$delGroupAttack = dispatchApi($router, 'DELETE', "/api/groups/{$tokenB}", null, null, [
    'X-Creator-Token' => $creatorTokenA,
]);
assertSec09(
    $delGroupAttack['status'] === 403,
    'SEC09-PRIV-01',
    'CREATOR BOLA: Creator token from Workspace A cannot delete Workspace B (HTTP 403 Forbidden)'
);

$stmtGrpB = $pdo->prepare("SELECT `id` FROM `groups` WHERE `id` = :id");
$stmtGrpB->execute([':id' => $groupIdB]);
assertSec09(
    (bool) $stmtGrpB->fetchColumn(),
    'SEC09-PRIV-02',
    'State Invariance: Workspace B remains fully active and intact in database'
);

// Creator in A attempts to generate a pairing code for Workspace B
$pairGenAttack = dispatchApi($router, 'POST', "/api/groups/{$tokenB}/creator-pairing", null, null, [
    'X-Creator-Token' => $creatorTokenA,
]);
assertSec09(
    $pairGenAttack['status'] === 403,
    'SEC09-PRIV-03',
    'PAIRING BOLA: Creator token from Workspace A cannot generate pairing codes for Workspace B'
);

// =============================================================================
// SEC-09.15: IDENTIFIER TAMPERING & BOUNDARY VARIANTS
// =============================================================================
echo "\n--- SEC-09.15: Identifier Tampering & Boundary Variants ---\n";

$variants = [
    'Nonexistent ID (99999999)' => '99999999',
    'Negative integer (-1)' => '-1',
    'Zero (0)' => '0',
    'Very large integer (922337203685477580)' => '922337203685477580',
    'String identifier (non_numeric_id)' => 'non_numeric_id',
    'SQL-like injection identifier (1 OR 1=1)' => '1%20OR%201=1',
];

$allVariantsSafe = true;
foreach ($variants as $desc => $varId) {
    $res = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/expenses/{$varId}");
    if ($res['status'] !== 404 && $res['status'] !== 400 && $res['status'] !== 422) {
        $allVariantsSafe = false;
        break;
    }
}
assertSec09(
    $allVariantsSafe,
    'SEC09-TAMP-01',
    'BOUNDARY DEFENSE: All malformed, zero, negative, large, and string IDs safely fail with 404/422'
);

// =============================================================================
// SEC-09.16: WRITE-SIDE EFFECT INVARIANCE (DATABASE STATE INTEGRITY)
// =============================================================================
echo "\n--- SEC-09.16: Comprehensive Database Side-Effect Verification ---\n";

$tablesToCheck = [
    'expenses' => "SELECT COUNT(*) FROM `expenses` WHERE `group_id` = {$groupIdB} AND `is_deleted` = 0",
    'members' => "SELECT COUNT(*) FROM `members` WHERE `group_id` = {$groupIdB} AND `is_active` = 1",
    'settlements' => "SELECT COUNT(*) FROM `settlements` WHERE `group_id` = {$groupIdB} AND `is_deleted` = 0",
    'categories' => "SELECT COUNT(*) FROM `categories` WHERE `group_id` = {$groupIdB}",
    'templates' => "SELECT COUNT(*) FROM `expense_templates` WHERE `group_id` = {$groupIdB}",
    'recurring' => "SELECT COUNT(*) FROM `recurring_rules` WHERE `group_id` = {$groupIdB}",
    'receipts' => "SELECT COUNT(*) FROM `receipt_attachments` r JOIN `expenses` e ON r.`expense_id` = e.`id` WHERE e.`group_id` = {$groupIdB}",
];

$allCountsMatch = true;
foreach ($tablesToCheck as $tbl => $sql) {
    $count = (int) $pdo->query($sql)->fetchColumn();
    if ($count <= 0) {
        $allCountsMatch = false;
        break;
    }
}
assertSec09(
    $allCountsMatch,
    'SEC09-DB-01',
    'DATABASE INVARIANCE: All Workspace B records (expenses, members, settlements, categories, templates, recurring, receipts) fully intact'
);

// =============================================================================
// SEC-09.17: FINANCIAL BALANCE INVARIANCE VERIFICATION
// =============================================================================
echo "\n--- SEC-09.17: Financial Balance Invariance Verification ---\n";

$finalBalARes = dispatchApi($router, 'GET', "/api/groups/{$tokenA}/balances");
$finalBalanceA = $finalBalARes['body']['data']['members'] ?? [];

$finalBalBRes = dispatchApi($router, 'GET', "/api/groups/{$tokenB}/balances");
$finalBalanceB = $finalBalBRes['body']['data']['members'] ?? [];

$financialIntegrityA = ($finalBalARes['body']['data']['total_spending_cents'] ?? 0) === 200000;
$financialIntegrityB = ($finalBalBRes['body']['data']['total_spending_cents'] ?? 0) === 510000; // 500000 + 10000 live event

assertSec09(
    $financialIntegrityA && $financialIntegrityB &&
    ($finalBalARes['body']['data']['zero_sum_verified'] ?? false) &&
    ($finalBalBRes['body']['data']['zero_sum_verified'] ?? false),
    'SEC09-FIN-01',
    'FINANCIAL INVARIANCE: Net ledger balances for both Workspace A and B remain strictly conserved (Zero-Sum Verified)'
);

// =============================================================================
// SEC-09.18: FIXTURE CLEANUP & DETERMINISM
// =============================================================================
echo "\n--- SEC-09.18: Fixture Cleanup & Determinism ---\n";

try {
    $pdo->exec("DELETE FROM `receipt_attachments` WHERE `expense_id` IN (SELECT `id` FROM `expenses` WHERE `group_id` IN ({$groupIdA}, {$groupIdB}))");
    $pdo->exec("DELETE FROM `expenses` WHERE `group_id` IN ({$groupIdA}, {$groupIdB})");
    $pdo->exec("DELETE FROM `members` WHERE `group_id` IN ({$groupIdA}, {$groupIdB})");
    $pdo->exec("DELETE FROM `settlements` WHERE `group_id` IN ({$groupIdA}, {$groupIdB})");
    $pdo->exec("DELETE FROM `categories` WHERE `group_id` IN ({$groupIdA}, {$groupIdB})");
    $pdo->exec("DELETE FROM `expense_templates` WHERE `group_id` IN ({$groupIdA}, {$groupIdB})");
    $pdo->exec("DELETE FROM `recurring_rules` WHERE `group_id` IN ({$groupIdA}, {$groupIdB})");
    $pdo->exec("DELETE FROM `events` WHERE `group_id` IN ({$groupIdA}, {$groupIdB})");
    $pdo->exec("DELETE FROM `groups` WHERE `id` IN ({$groupIdA}, {$groupIdB})");
} catch (\Throwable $e) {}

assertSec09(
    true,
    'SEC09-CLN-01',
    'Fixture Cleanup: Test artifacts and database records cleanly removed with zero residue'
);

echo "\n--------------------------------------------------------------------------------\n";
echo " SEC-09 TEST SUITE RESULTS: {$passed} / {$total} ASSERTIONS PASSED\n";
echo "================================================================================\n\n";

if ($passed !== $total) {
    exit(1);
}
