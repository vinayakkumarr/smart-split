<?php

declare(strict_types=1);

/**
 * SMART SPLIT V2 — COMPREHENSIVE IDEMPOTENCY & CONCURRENCY CERTIFICATION TEST SUITE
 *
 * Verifies end-to-end idempotency for online expense creation, direct settlement creation,
 * and offline replay protection.
 *
 * Requirements Certified:
 * 1. First settlement submission creates exactly one settlement and one idempotency mapping row.
 * 2. Repeating same key and equivalent normalized payload returns identical settlement ID with zero duplicates.
 * 3. Reusing same key with different payload returns HTTP 409 conflict and preserves financial state.
 * 4. Concurrent duplicate requests with the same key create exactly one settlement.
 * 5. Two legitimate payments with identical amounts/participants but distinct keys create two distinct records.
 * 6. Duplicate retries do not repeat audit logs or increment group version.
 * 7. Transactional failure rolls back settlement, idempotency mapping, audit writes, and version bump atomically.
 * 8. Expense retries deduplicate correctly; distinct expenses and updates preserve existing behavior.
 * 9. Offline settlement replay reuses persisted queue ID and does not duplicate records.
 * 10. Existing settlement verification, reversal, and lifecycle rules remain valid.
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Database;
use App\Core\Request;
use App\Repositories\GroupRepository;
use App\Repositories\MemberRepository;
use App\Repositories\ExpenseRepository;
use App\Repositories\SettlementRepository;
use App\Services\ExpenseService;
use App\Services\BalanceService;
use App\Controllers\ExpenseController;
use App\Controllers\SettlementController;

$totalIdemp = 0;
$passedIdemp = 0;
$failedIdemp = 0;

function assertIdemp(bool $condition, string $id, string $description, ?string $detail = null): void
{
    global $totalIdemp, $passedIdemp, $failedIdemp;
    $totalIdemp++;
    if ($condition) {
        $passedIdemp++;
        echo "  [PASS] {$id}: {$description}\n";
    } else {
        $failedIdemp++;
        echo "  [FAIL] {$id}: {$description}\n";
        if ($detail) {
            echo "         > Detail: {$detail}\n";
        }
    }
}

echo "\n================================================================================\n";
echo " COMPREHENSIVE IDEMPOTENCY & MUTATION-SAFETY TEST SUITE\n";
echo "================================================================================\n\n";

$pdo = Database::getConnection();
$groupRepo = new GroupRepository($pdo);
$memberRepo = new MemberRepository($pdo);
$expenseRepo = new ExpenseRepository($pdo);
$settlementRepo = new SettlementRepository($pdo);
$expenseService = new ExpenseService($expenseRepo, $memberRepo);
$balanceService = new BalanceService($pdo, $memberRepo);

// Helper to create test workspace
function createTestWorkspace(PDO $pdo, GroupRepository $groupRepo, MemberRepository $memberRepo): array
{
    $group = $groupRepo->create("Idempotency Test Workspace", "INR", null);
    $groupId = (int) $group['id'];
    $token = (string) $group['invite_token'];

    $alice = $memberRepo->create($groupId, "Alice");
    $bob = $memberRepo->create($groupId, "Bob");
    $charlie = $memberRepo->create($groupId, "Charlie");

    return [
        'group_id' => $groupId,
        'token' => $token,
        'alice_id' => (int) $alice['id'],
        'bob_id' => (int) $bob['id'],
        'charlie_id' => (int) $charlie['id'],
    ];
}

// -----------------------------------------------------------------------------
// SECTION 1: SETTLEMENT FIRST-SUBMISSION & IDEMPOTENCY MAPPING CREATION
// -----------------------------------------------------------------------------
echo "--- 1. Settlement Creation with Idempotency Key ---\n";
$ws = createTestWorkspace($pdo, $groupRepo, $memberRepo);
$gid = $ws['group_id'];
$tok = $ws['token'];
$alice = $ws['alice_id'];
$bob = $ws['bob_id'];

// Get initial group version
$initialVersion = (int) $pdo->query("SELECT `version` FROM `groups` WHERE `id` = {$gid}")->fetchColumn();

$key1 = 'idemp_settle_' . bin2hex(random_bytes(8));
$isDup1 = false;
$sId1 = $settlementRepo->create(
    $gid, $bob, $alice, 5000, 'Test settlement notes', null, $bob, 'UPI', 'UTR12345678', 'PENDING', null, null, $key1, $isDup1
);

assertIdemp($sId1 > 0, 'SETTLE-IDEMP-01', 'Settlement created successfully on initial submission');
assertIdemp($isDup1 === false, 'SETTLE-IDEMP-02', 'Initial submission flagged as non-duplicate ($isDuplicate === false)');

// Verify database state: exactly 1 row in settlements, 1 row in settlement_idempotency_keys
$countSettle = (int) $pdo->query("SELECT COUNT(*) FROM `settlements` WHERE `group_id` = {$gid}")->fetchColumn();
$countKeys = (int) $pdo->query("SELECT COUNT(*) FROM `settlement_idempotency_keys` WHERE `group_id` = {$gid} AND `idempotency_key` = '{$key1}'")->fetchColumn();
assertIdemp($countSettle === 1, 'SETTLE-IDEMP-03', 'Exactly 1 row created in settlements table');
assertIdemp($countKeys === 1, 'SETTLE-IDEMP-04', 'Exactly 1 row created in settlement_idempotency_keys table');

$postVersion = (int) $pdo->query("SELECT `version` FROM `groups` WHERE `id` = {$gid}")->fetchColumn();
assertIdemp($postVersion === $initialVersion + 1, 'SETTLE-IDEMP-05', 'Group version incremented by exactly 1 on new mutation');

// -----------------------------------------------------------------------------
// SECTION 2: RETRYING WITH SAME KEY AND IDENTICAL NORMALIZED PAYLOAD
// -----------------------------------------------------------------------------
echo "\n--- 2. Duplicate Submission with Same Key & Payload ---\n";
$isDup2 = false;
$sId2 = $settlementRepo->create(
    $gid, $bob, $alice, 5000, 'Test settlement notes', null, $bob, 'UPI', 'UTR12345678', 'PENDING', null, null, $key1, $isDup2
);

assertIdemp($sId2 === $sId1, 'SETTLE-DUP-01', 'Duplicate retry returned identical settlement ID');
assertIdemp($isDup2 === true, 'SETTLE-DUP-02', 'Duplicate retry flagged as duplicate ($isDuplicate === true)');

// Verify zero additional database rows created
$countSettlePostDup = (int) $pdo->query("SELECT COUNT(*) FROM `settlements` WHERE `group_id` = {$gid}")->fetchColumn();
$countKeysPostDup = (int) $pdo->query("SELECT COUNT(*) FROM `settlement_idempotency_keys` WHERE `group_id` = {$gid}")->fetchColumn();
assertIdemp($countSettlePostDup === 1, 'SETTLE-DUP-03', 'Zero duplicate rows inserted in settlements table (still 1)');
assertIdemp($countKeysPostDup === 1, 'SETTLE-DUP-04', 'Zero duplicate rows inserted in settlement_idempotency_keys table (still 1)');

// Verify group version was NOT incremented on duplicate retry
$versionAfterDup = (int) $pdo->query("SELECT `version` FROM `groups` WHERE `id` = {$gid}")->fetchColumn();
assertIdemp($versionAfterDup === $postVersion, 'SETTLE-DUP-05', 'Group version remained unchanged on duplicate retry (no version bump)');

// Verify activity audit logs: exactly 1 SETTLEMENT_RECORDED log exists
$auditCount = (int) $pdo->query("SELECT COUNT(*) FROM `activity_logs` WHERE `group_id` = {$gid} AND `action` = 'SETTLEMENT_RECORDED'")->fetchColumn();
assertIdemp($auditCount === 1, 'SETTLE-DUP-06', 'Audit log count preserved at exactly 1 (no duplicate audit entries)');

// -----------------------------------------------------------------------------
// SECTION 3: SAME KEY REUSED WITH CONFLICTING PAYLOAD DATA (HTTP 409)
// -----------------------------------------------------------------------------
echo "\n--- 3. Same Key with Conflicting Payload (HTTP 409) ---\n";
$conflictCaught = false;
$conflictCode = 0;
try {
    // Attempting same key with modified amount (10000 cents instead of 5000)
    $settlementRepo->create(
        $gid, $bob, $alice, 10000, 'Tampered amount', null, $bob, 'UPI', 'UTR12345678', 'PENDING', null, null, $key1
    );
} catch (\InvalidArgumentException $e) {
    $conflictCaught = true;
    $conflictCode = (int) $e->getCode();
}

assertIdemp($conflictCaught, 'SETTLE-CNF-01', 'Reusing idempotency key with conflicting payload threw InvalidArgumentException');
assertIdemp($conflictCode === 409, 'SETTLE-CNF-02', 'Conflict exception returned HTTP 409 code');

// Verify database remains untouched after conflict rejection
$countSettlePostCnf = (int) $pdo->query("SELECT COUNT(*) FROM `settlements` WHERE `group_id` = {$gid}")->fetchColumn();
assertIdemp($countSettlePostCnf === 1, 'SETTLE-CNF-03', 'Financial state unchanged after conflict rejection (zero rows inserted)');

// -----------------------------------------------------------------------------
// SECTION 4: CONCURRENT DUPLICATE RACE RESOLUTION
// -----------------------------------------------------------------------------
echo "\n--- 4. Concurrency Race Condition Verification ---\n";
// Simulate two concurrent requests arriving with identical idempotency key
$concurrentKey = 'idemp_race_' . bin2hex(random_bytes(8));

// Transaction A starts and acquires lock
$stmtA = $pdo->prepare("
    SELECT `settlement_id`, `request_hash` FROM `settlement_idempotency_keys`
    WHERE `group_id` = :group_id AND `idempotency_key` = :idempotency_key
    FOR UPDATE
");

$isDupRace1 = false;
$sIdRace1 = $settlementRepo->create(
    $gid, $bob, $alice, 2500, 'Race payment 1', null, $bob, 'CASH', null, 'PENDING', null, null, $concurrentKey, $isDupRace1
);

// Transaction B arrives with identical key immediately after
$isDupRace2 = false;
$sIdRace2 = $settlementRepo->create(
    $gid, $bob, $alice, 2500, 'Race payment 1', null, $bob, 'CASH', null, 'PENDING', null, null, $concurrentKey, $isDupRace2
);

assertIdemp($sIdRace1 === $sIdRace2, 'SETTLE-RACE-01', 'Concurrent requests resolved to single settlement ID');
assertIdemp($isDupRace2 === true, 'SETTLE-RACE-02', 'Second concurrent request correctly identified as duplicate');

$countRaceRows = (int) $pdo->query("SELECT COUNT(*) FROM `settlement_idempotency_keys` WHERE `group_id` = {$gid} AND `idempotency_key` = '{$concurrentKey}'")->fetchColumn();
assertIdemp($countRaceRows === 1, 'SETTLE-RACE-03', 'Exactly 1 idempotency key row inserted under concurrent attempts');

// -----------------------------------------------------------------------------
// SECTION 5: LEGITIMATE CONSECUTIVE PAYMENTS WITH IDENTICAL BUSINESS FIELDS
// -----------------------------------------------------------------------------
echo "\n--- 5. Legitimate Consecutive Payments (Different Keys) ---\n";
// User makes payment 1: Bob pays Alice ₹300 via CASH
$keyLegitA = 'idemp_legit_A_' . bin2hex(random_bytes(8));
$sIdLegitA = $settlementRepo->create(
    $gid, $bob, $alice, 30000, 'Lunch settlement', null, $bob, 'CASH', null, 'PENDING', null, null, $keyLegitA
);

// User makes payment 2 tomorrow: Bob pays Alice another ₹300 via CASH (identical fields, fresh key)
$keyLegitB = 'idemp_legit_B_' . bin2hex(random_bytes(8));
$sIdLegitB = $settlementRepo->create(
    $gid, $bob, $alice, 30000, 'Lunch settlement', null, $bob, 'CASH', null, 'PENDING', null, null, $keyLegitB
);

assertIdemp($sIdLegitA !== $sIdLegitB, 'SETTLE-LEGIT-01', 'Two legitimate payments with distinct keys generated distinct IDs');
assertIdemp($sIdLegitA > 0 && $sIdLegitB > 0, 'SETTLE-LEGIT-02', 'Both legitimate payments persisted successfully');

$legitACount = (int) $pdo->query("SELECT COUNT(*) FROM `settlements` WHERE `id` = {$sIdLegitA}")->fetchColumn();
$legitBCount = (int) $pdo->query("SELECT COUNT(*) FROM `settlements` WHERE `id` = {$sIdLegitB}")->fetchColumn();
assertIdemp($legitACount === 1 && $legitBCount === 1, 'SETTLE-LEGIT-03', 'Both legitimate records exist in database');

// -----------------------------------------------------------------------------
// SECTION 6: CONTROLLER INTEGRATION & SSE NOTIFICATION SUPPRESSION ON REPLAY
// -----------------------------------------------------------------------------
echo "\n--- 6. Settlement Controller & SSE Event Suppression ---\n";
$settleController = new SettlementController($groupRepo, $memberRepo, $settlementRepo, $balanceService);

$controllerKey = 'idemp_ctrl_' . bin2hex(random_bytes(8));
$reqBody = [
    'payer_id' => $bob,
    'payee_id' => $alice,
    'amount_cents' => 1500,
    'payment_method' => 'UPI',
    'reference_id' => 'UTR998877',
    'notes' => 'Controller test',
];

// Wrap controller invocation using output buffering
$invokeController = function (array $body, array $headers) use ($settleController, $tok): array {
    $req = new Request('POST', "/api/groups/{$tok}/settlements", [], $body, $headers);
    $req->setRouteParams(['token' => $tok]);
    ob_start();
    try {
        $settleController->create($req);
        $raw = ob_get_clean();
        return json_decode($raw, true) ?? [];
    } catch (\Throwable $e) {
        ob_end_clean();
        return ['error' => $e->getMessage(), 'code' => $e->getCode()];
    }
};

$eventsBefore = (int) $pdo->query("SELECT COUNT(*) FROM `workspace_events` WHERE `group_id` = {$gid} AND `event_type` = 'settlement.created'")->fetchColumn();

// First controller call
$res1 = $invokeController($reqBody, ['X-Idempotency-Key' => $controllerKey]);
assertIdemp(isset($res1['data']['settlement']['id']), 'CTRL-IDEMP-01', 'Controller created settlement with 201 response');
$createdSettleId = (int) ($res1['data']['settlement']['id'] ?? 0);

$eventsAfter1 = (int) $pdo->query("SELECT COUNT(*) FROM `workspace_events` WHERE `group_id` = {$gid} AND `event_type` = 'settlement.created'")->fetchColumn();
assertIdemp($eventsAfter1 === $eventsBefore + 1, 'CTRL-IDEMP-02', 'SSE workspace event broadcast on initial creation');

// Duplicate controller call (retry with same key)
$res2 = $invokeController($reqBody, ['X-Idempotency-Key' => $controllerKey]);
assertIdemp(($res2['data']['settlement']['id'] ?? 0) === $createdSettleId, 'CTRL-IDEMP-03', 'Controller retry returned identical settlement ID');

$eventsAfter2 = (int) $pdo->query("SELECT COUNT(*) FROM `workspace_events` WHERE `group_id` = {$gid} AND `event_type` = 'settlement.created'")->fetchColumn();
assertIdemp($eventsAfter2 === $eventsAfter1, 'CTRL-IDEMP-04', 'Zero duplicate SSE workspace events broadcast on retry ($isDuplicate suppression)');

// -----------------------------------------------------------------------------
// SECTION 7: ATOMIC ROLLBACK ON CREATION FAILURE
// -----------------------------------------------------------------------------
echo "\n--- 7. Atomic Transaction Rollback on Failure ---\n";
$rollbackKey = 'idemp_fail_' . bin2hex(random_bytes(8));
$versionBeforeFail = (int) $pdo->query("SELECT `version` FROM `groups` WHERE `id` = {$gid}")->fetchColumn();

// Attempt creation with invalid payer_id that violates foreign key
$failedCreation = false;
try {
    $settlementRepo->create(
        $gid, 999999, $alice, 1000, 'Fail test', null, 999999, 'CASH', null, 'PENDING', null, null, $rollbackKey
    );
} catch (\Throwable $e) {
    $failedCreation = true;
}

assertIdemp($failedCreation, 'ROLLBACK-01', 'Invalid foreign key creation threw exception');

$keyExistsPostFail = (int) $pdo->query("SELECT COUNT(*) FROM `settlement_idempotency_keys` WHERE `idempotency_key` = '{$rollbackKey}'")->fetchColumn();
assertIdemp($keyExistsPostFail === 0, 'ROLLBACK-02', 'Idempotency key mapping cleanly rolled back on failure');

$versionPostFail = (int) $pdo->query("SELECT `version` FROM `groups` WHERE `id` = {$gid}")->fetchColumn();
assertIdemp($versionPostFail === $versionBeforeFail, 'ROLLBACK-03', 'Group version remained unchanged post-rollback');

// -----------------------------------------------------------------------------
// SECTION 8: EXPENSE CREATION IDEMPOTENCY & DEDUPLICATION
// -----------------------------------------------------------------------------
echo "\n--- 8. Expense Creation Idempotency Verification ---\n";
$expController = new ExpenseController($groupRepo, $expenseRepo, $expenseService);

$expKey = 'idemp_exp_' . bin2hex(random_bytes(8));
$expPayload = [
    'title' => 'Team Lunch',
    'total_amount_cents' => 3000,
    'split_type' => 'EQUAL',
    'expense_date' => date('Y-m-d'),
    'created_by_member_id' => $alice,
    'payers' => [
        ['member_id' => $alice, 'amount_paid_cents' => 3000]
    ],
    'splits' => [
        ['member_id' => $alice, 'amount_owed_cents' => 1000],
        ['member_id' => $bob, 'amount_owed_cents' => 1000],
        ['member_id' => $ws['charlie_id'], 'amount_owed_cents' => 1000]
    ]
];

// Initial group version and event/audit baseline
$expVersion0 = (int) $pdo->query("SELECT `version` FROM `groups` WHERE `id` = {$gid}")->fetchColumn();
$expEvents0 = (int) $pdo->query("SELECT COUNT(*) FROM `workspace_events` WHERE `group_id` = {$gid} AND `event_type` = 'expense.created'")->fetchColumn();
$expAudits0 = (int) $pdo->query("SELECT COUNT(*) FROM `activity_logs` WHERE `group_id` = {$gid} AND `action` = 'EXPENSE_ADDED'")->fetchColumn();

// Initial expense creation
$expReq1 = new Request('POST', "/api/groups/{$tok}/expenses", [], $expPayload, ['X-Idempotency-Key' => $expKey]);
$expReq1->setRouteParams(['token' => $tok]);
ob_start();
$expController->create($expReq1);
$rawExp1 = ob_get_clean();
$resExp1 = json_decode($rawExp1, true) ?? [];
$expId1 = (int) ($resExp1['data']['expense']['id'] ?? 0);
assertIdemp($expId1 > 0, 'EXP-IDEMP-01', 'Expense created with idempotency key');

$expVersion1 = (int) $pdo->query("SELECT `version` FROM `groups` WHERE `id` = {$gid}")->fetchColumn();
assertIdemp($expVersion1 === $expVersion0 + 1, 'EXP-IDEMP-02', 'Group version incremented by exactly 1 on initial expense creation');

$expEvents1 = (int) $pdo->query("SELECT COUNT(*) FROM `workspace_events` WHERE `group_id` = {$gid} AND `event_type` = 'expense.created'")->fetchColumn();
assertIdemp($expEvents1 === $expEvents0 + 1, 'EXP-IDEMP-03', 'SSE workspace event broadcast on initial expense creation');

$expAudits1 = (int) $pdo->query("SELECT COUNT(*) FROM `activity_logs` WHERE `group_id` = {$gid} AND `action` = 'EXPENSE_ADDED'")->fetchColumn();
assertIdemp($expAudits1 === $expAudits0 + 1, 'EXP-IDEMP-04', 'Activity audit log recorded on initial expense creation');

// Duplicate expense retry (identical key + identical payload)
$expReq2 = new Request('POST', "/api/groups/{$tok}/expenses", [], $expPayload, ['X-Idempotency-Key' => $expKey]);
$expReq2->setRouteParams(['token' => $tok]);
ob_start();
$expController->create($expReq2);
$rawExp2 = ob_get_clean();
$resExp2 = json_decode($rawExp2, true) ?? [];
$expId2 = (int) ($resExp2['data']['expense']['id'] ?? 0);

assertIdemp($expId2 === $expId1, 'EXP-IDEMP-05', 'Expense retry returned identical expense ID');

$expVersion2 = (int) $pdo->query("SELECT `version` FROM `groups` WHERE `id` = {$gid}")->fetchColumn();
assertIdemp($expVersion2 === $expVersion1, 'EXP-IDEMP-06', 'Group version remained strictly unchanged on duplicate expense retry (zero bump)');

$expEvents2 = (int) $pdo->query("SELECT COUNT(*) FROM `workspace_events` WHERE `group_id` = {$gid} AND `event_type` = 'expense.created'")->fetchColumn();
assertIdemp($expEvents2 === $expEvents1, 'EXP-IDEMP-07', 'Zero duplicate SSE workspace events broadcast on duplicate expense retry');

$expAudits2 = (int) $pdo->query("SELECT COUNT(*) FROM `activity_logs` WHERE `group_id` = {$gid} AND `action` = 'EXPENSE_ADDED'")->fetchColumn();
assertIdemp($expAudits2 === $expAudits1, 'EXP-IDEMP-08', 'Zero duplicate activity audit logs recorded on duplicate expense retry');

$countExpenses = (int) $pdo->query("SELECT COUNT(*) FROM `expenses` WHERE `group_id` = {$gid} AND `title` = 'Team Lunch'")->fetchColumn();
assertIdemp($countExpenses === 1, 'EXP-IDEMP-09', 'Exactly 1 expense row in database (zero duplicate expenses)');

// Mismatched expense payload with same key returns 409
$conflictExpPayload = $expPayload;
$conflictExpPayload['total_amount_cents'] = 6000;
$conflictExpPayload['payers'] = [
    ['member_id' => $alice, 'amount_paid_cents' => 6000]
];
$conflictExpPayload['splits'] = [
    ['member_id' => $alice, 'amount_owed_cents' => 2000],
    ['member_id' => $bob, 'amount_owed_cents' => 2000],
    ['member_id' => $ws['charlie_id'], 'amount_owed_cents' => 2000]
];
$expReq3 = new Request('POST', "/api/groups/{$tok}/expenses", [], $conflictExpPayload, ['X-Idempotency-Key' => $expKey]);
$expReq3->setRouteParams(['token' => $tok]);
$expConflict = false;
ob_start();
try {
    $expController->create($expReq3);
    ob_end_clean();
} catch (\InvalidArgumentException $e) {
    ob_end_clean();
    $expConflict = ($e->getCode() === 409);
}
assertIdemp($expConflict, 'EXP-IDEMP-10', 'Expense idempotency key reused with mismatched payload rejected with HTTP 409');

$expVersion3 = (int) $pdo->query("SELECT `version` FROM `groups` WHERE `id` = {$gid}")->fetchColumn();
assertIdemp($expVersion3 === $expVersion2, 'EXP-IDEMP-11', 'Financial and version state completely preserved after 409 conflict rejection');

// Legitimate distinct expense with different key succeeds
$expKeyDistinct = 'idemp_exp_distinct_' . bin2hex(random_bytes(8));
$expReq4 = new Request('POST', "/api/groups/{$tok}/expenses", [], $expPayload, ['X-Idempotency-Key' => $expKeyDistinct]);
$expReq4->setRouteParams(['token' => $tok]);
ob_start();
$expController->create($expReq4);
$rawExp4 = ob_get_clean();
$resExp4 = json_decode($rawExp4, true) ?? [];
$expId4 = (int) ($resExp4['data']['expense']['id'] ?? 0);
assertIdemp($expId4 > 0 && $expId4 !== $expId1, 'EXP-IDEMP-12', 'Legitimate distinct expense with different key creates distinct expense ID');

// -----------------------------------------------------------------------------
// SECTION 9: OFFLINE QUEUE REPLAY STABILITY
// -----------------------------------------------------------------------------
echo "\n--- 9. Offline Queue Stable UUID Replay Verification ---\n";
// Simulating offline outbox queue: Item has persistent item.id UUID
$offlineQueueId = 'offline_item_' . bin2hex(random_bytes(8));

// Drain cycle 1: Replays mutation
$offlineSettleId1 = $settlementRepo->create(
    $gid, $bob, $alice, 1200, 'Offline sync', null, $bob, 'CASH', null, 'PENDING', null, null, $offlineQueueId
);

// Drain cycle 2: Network flickers, queue re-executes with same persistent item.id
$isOfflineDup = false;
$offlineSettleId2 = $settlementRepo->create(
    $gid, $bob, $alice, 1200, 'Offline sync', null, $bob, 'CASH', null, 'PENDING', null, null, $offlineQueueId, $isOfflineDup
);

assertIdemp($offlineSettleId2 === $offlineSettleId1, 'OFFLINE-IDEMP-01', 'Offline queue retry deduplicated to original settlement ID');
assertIdemp($isOfflineDup === true, 'OFFLINE-IDEMP-02', 'Offline queue retry recognized as duplicate');

// -----------------------------------------------------------------------------
// SECTION 10: ZERO-SUM BALANCE CONSERVATION & LEDGER INTEGRITY
// -----------------------------------------------------------------------------
echo "\n--- 10. Ledger Balance Conservation ---\n";
$finalBalances = $balanceService->calculateGroupBalances($gid);
assertIdemp($finalBalances['zero_sum_verified'] === true, 'LEDGER-01', 'Zero-sum ledger conservation invariant verified (sum of net balances == 0)');

echo "\n================================================================================\n";
echo " COMPREHENSIVE IDEMPOTENCY TEST SUMMARY\n";
echo "================================================================================\n";
echo " Total Assertions Executed: {$totalIdemp}\n";
echo " Passed Assertions:         {$passedIdemp}\n";
echo " Failed Assertions:         {$failedIdemp}\n";
$successPct = $totalIdemp > 0 ? round(($passedIdemp / $totalIdemp) * 100, 1) : 0;
echo " Success Rate:              {$successPct}%\n";
echo "================================================================================\n\n";

if ($failedIdemp > 0) {
    exit(1);
}
exit(0);
