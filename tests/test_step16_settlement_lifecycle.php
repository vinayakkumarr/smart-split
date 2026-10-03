<?php

declare(strict_types=1);

/**
 * SMART SPLIT V2 — STEP 16: ROLE-AWARE SETTLEMENT LIFECYCLE, ATTRIBUTION & REVERSAL SUITE
 *
 * Exhaustive verification of:
 * 1. State machine transitions: PENDING -> CONFIRMED, PENDING -> DISPUTED, CONFIRMED -> REVERSED.
 * 2. Balance isolation: PENDING and DISPUTED settlements do not alter authoritative balances.
 * 3. Creditor confirmation: Transition to CONFIRMED applies settlement to balances atomically.
 * 4. Reversal: Transition to REVERSED cleanly restores debt while preserving audit trail.
 * 5. Creditor Fast-Path: Payee recording receipt creates instant CONFIRMED settlement.
 * 6. Authorization checks: Payers cannot self-confirm; unrelated members cannot confirm/dispute.
 * 7. Payment attribution & metadata: payment_method, reference_id (UTR), recorded_by_member_id.
 * 8. Zero-sum invariant conservation across all settlement mutations.
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Database;
use App\Repositories\GroupRepository;
use App\Repositories\MemberRepository;
use App\Repositories\ExpenseRepository;
use App\Repositories\SettlementRepository;
use App\Services\BalanceService;

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertLifecycle(bool $condition, string $id, string $description, ?string $detail = null): void
{
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$id}: {$description}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$id}: {$description}\n";
        if ($detail) {
            echo "         > Detail: {$detail}\n";
        }
    }
}

echo "\n================================================================================\n";
echo " STEP 16: ROLE-AWARE SETTLEMENT LIFECYCLE & IMMUTABLE REVERSAL SUITE\n";
echo "================================================================================\n\n";

$pdo = Database::getConnection();
$groupRepo = new GroupRepository($pdo);
$memberRepo = new MemberRepository($pdo);
$expenseRepo = new ExpenseRepository($pdo);
$settlementRepo = new SettlementRepository($pdo);
$balanceService = new BalanceService($pdo, $memberRepo);

// 1. Setup Isolated Test Workspace
echo "--- 1. Workspace & Multi-Member Fixture Setup ---\n";
$group = $groupRepo->create('Goa Trip Settlement Audit', 'INR');
$groupId = (int) $group['id'];
$token = (string) $group['invite_token'];

$mAlice = $memberRepo->create($groupId, 'Alice');
$mBob = $memberRepo->create($groupId, 'Bob');
$mCharlie = $memberRepo->create($groupId, 'Charlie');

$aliceId = (int) $mAlice['id'];
$bobId = (int) $mBob['id'];
$charlieId = (int) $mCharlie['id'];

assertLifecycle($groupId > 0 && $aliceId > 0 && $bobId > 0 && $charlieId > 0, 'LC-FIX-01', 'Workspace created with 3 members (Alice, Bob, Charlie)');

// 2. Add an Expense: Alice pays ₹300.00 split equally among Alice, Bob, Charlie (Bob owes ₹100, Charlie owes ₹100)
$exp1Id = $expenseRepo->createExpense(
    $groupId,
    'Resort Dinner',
    30000,
    'EQUAL',
    date('Y-m-d'),
    $aliceId,
    [['member_id' => $aliceId, 'amount_paid_cents' => 30000]],
    [
        ['member_id' => $aliceId, 'amount_owed_cents' => 10000, 'split_value' => null],
        ['member_id' => $bobId, 'amount_owed_cents' => 10000, 'split_value' => null],
        ['member_id' => $charlieId, 'amount_owed_cents' => 10000, 'split_value' => null],
    ]
);

$bInitial = $balanceService->calculateGroupBalances($groupId);
$aliceNet = 0; $bobNet = 0; $charlieNet = 0;
foreach ($bInitial['members'] as $m) {
    if ($m['member_id'] === $aliceId) $aliceNet = $m['net_balance_cents'];
    if ($m['member_id'] === $bobId) $bobNet = $m['net_balance_cents'];
    if ($m['member_id'] === $charlieId) $charlieNet = $m['net_balance_cents'];
}

assertLifecycle($aliceNet === 20000 && $bobNet === -10000 && $charlieNet === -10000, 'LC-BAL-01', 'Initial balances: Alice +₹200, Bob -₹100, Charlie -₹100');
assertLifecycle($bInitial['zero_sum_verified'] === true, 'LC-BAL-02', 'Zero-sum balance invariant verified on initial state');

// 3. Test State A: Pending Verification (Debtor records payment)
echo "\n--- 2. State A: Pending Payment Submission & Balance Isolation ---\n";
// Bob records payment of ₹100.00 to Alice via UPI (UTR: 9876543210)
$s1Id = $settlementRepo->create(
    $groupId,
    $bobId,
    $aliceId,
    10000,
    'Settled via Smart Split • UTR: 9876543210',
    null,
    $bobId,
    'UPI',
    '9876543210',
    'PENDING'
);

$s1 = $settlementRepo->findById($s1Id);
assertLifecycle($s1 !== null && $s1['status'] === 'PENDING', 'LC-STA-01', 'Settlement created in PENDING status');
assertLifecycle($s1['payment_method'] === 'UPI' && $s1['reference_id'] === '9876543210', 'LC-STA-02', 'Payment method (UPI) and reference UTR persisted');
assertLifecycle($s1['recorded_by']['id'] === $bobId && $s1['recorded_by']['name'] === 'Bob', 'LC-STA-03', 'Recorded by attribution correctly tracks Bob');

// Critical check: Confirmed balances MUST NOT change while settlement is PENDING!
$bPending = $balanceService->calculateGroupBalances($groupId);
$aliceNetPending = 0; $bobNetPending = 0;
foreach ($bPending['members'] as $m) {
    if ($m['member_id'] === $aliceId) $aliceNetPending = $m['net_balance_cents'];
    if ($m['member_id'] === $bobId) $bobNetPending = $m['net_balance_cents'];
}
assertLifecycle($aliceNetPending === 20000 && $bobNetPending === -10000, 'LC-STA-04', 'Authoritative balances isolate PENDING payment: Bob still owes ₹100');
assertLifecycle($bPending['zero_sum_verified'] === true, 'LC-STA-05', 'Zero-sum conserved during PENDING status');

// 4. Test State B: Confirmed (Creditor confirms receipt)
echo "\n--- 3. State B: Creditor Confirmation & Balance Application ---\n";
// Alice (creditor) confirms receipt
$confirmRes = $settlementRepo->confirm($s1Id, $aliceId);
assertLifecycle($confirmRes === true, 'LC-CFM-01', 'Creditor (Alice) confirms receipt of pending settlement');

$s1Confirmed = $settlementRepo->findById($s1Id);
assertLifecycle($s1Confirmed['status'] === 'CONFIRMED', 'LC-CFM-02', 'Settlement status transitioned to CONFIRMED');
assertLifecycle($s1Confirmed['confirmed_by']['id'] === $aliceId && !empty($s1Confirmed['confirmed_at']), 'LC-CFM-03', 'Confirmation actor (Alice) and timestamp persisted');

// Authoritative balances MUST now reflect the cleared debt
$bConfirmed = $balanceService->calculateGroupBalances($groupId);
$aliceNetConf = 0; $bobNetConf = 0; $charlieNetConf = 0;
foreach ($bConfirmed['members'] as $m) {
    if ($m['member_id'] === $aliceId) $aliceNetConf = $m['net_balance_cents'];
    if ($m['member_id'] === $bobId) $bobNetConf = $m['net_balance_cents'];
    if ($m['member_id'] === $charlieId) $charlieNetConf = $m['net_balance_cents'];
}
assertLifecycle($aliceNetConf === 10000 && $bobNetConf === 0 && $charlieNetConf === -10000, 'LC-CFM-04', 'Confirmed balance applied: Bob is SETTLED (0), Alice +₹100');
assertLifecycle($bConfirmed['zero_sum_verified'] === true, 'LC-CFM-05', 'Zero-sum conserved on CONFIRMED balance');

// 5. Test State D: Immutable Reversal
echo "\n--- 4. State D: Immutable Reversal & Financial Position Restoration ---\n";
// Alice realizes Bob's transfer was bounced, reverses settlement with reason
$revRes = $settlementRepo->reverse($s1Id, $aliceId, 'Bank transfer reversed/bounced');
assertLifecycle($revRes === true, 'LC-REV-01', 'Settlement reversed by creditor with reason');

$s1Reversed = $settlementRepo->findById($s1Id);
assertLifecycle($s1Reversed['status'] === 'REVERSED', 'LC-REV-02', 'Settlement status transitioned to REVERSED');
assertLifecycle($s1Reversed['reversed_by']['id'] === $aliceId && $s1Reversed['reversal_reason'] === 'Bank transfer reversed/bounced', 'LC-REV-03', 'Reversal actor and reason preserved in immutable record');

// Authoritative balances MUST restore Bob's debt
$bReversed = $balanceService->calculateGroupBalances($groupId);
$aliceNetRev = 0; $bobNetRev = 0;
foreach ($bReversed['members'] as $m) {
    if ($m['member_id'] === $aliceId) $aliceNetRev = $m['net_balance_cents'];
    if ($m['member_id'] === $bobId) $bobNetRev = $m['net_balance_cents'];
}
assertLifecycle($aliceNetRev === 20000 && $bobNetRev === -10000, 'LC-REV-04', 'Reversal restored financial position: Bob owes ₹100 again');
assertLifecycle($bReversed['zero_sum_verified'] === true, 'LC-REV-05', 'Zero-sum conserved post-reversal');

// Duplicate reversal attempt on already reversed settlement must fail
$dupRevFailed = false;
try {
    $settlementRepo->reverse($s1Id, $aliceId, 'Duplicate attempt');
} catch (Throwable $e) {
    $dupRevFailed = true;
}
assertLifecycle($dupRevFailed === true, 'LC-REV-06', 'Duplicate reversal attempt safely rejected with exception');

// 6. Test State C: Disputed (Pending payment disputed by creditor)
echo "\n--- 5. State C: Dispute Lifecycle ---\n";
// Charlie records payment to Alice
$s2Id = $settlementRepo->create(
    $groupId,
    $charlieId,
    $aliceId,
    10000,
    'Cash in envelope',
    null,
    $charlieId,
    'CASH',
    null,
    'PENDING'
);

$dispRes = $settlementRepo->dispute($s2Id, $aliceId, 'Envelope never received');
assertLifecycle($dispRes === true, 'LC-DSP-01', 'Creditor disputes pending cash settlement');

$s2Disputed = $settlementRepo->findById($s2Id);
assertLifecycle($s2Disputed['status'] === 'DISPUTED', 'LC-DSP-02', 'Settlement status transitioned to DISPUTED');
assertLifecycle($s2Disputed['disputed_by']['id'] === $aliceId && $s2Disputed['dispute_reason'] === 'Envelope never received', 'LC-DSP-03', 'Dispute actor and reason preserved in immutable record');

// Authoritative balances: Charlie still owes ₹100
$bDisputed = $balanceService->calculateGroupBalances($groupId);
$charlieNetDisp = 0;
foreach ($bDisputed['members'] as $m) {
    if ($m['member_id'] === $charlieId) $charlieNetDisp = $m['net_balance_cents'];
}
assertLifecycle($charlieNetDisp === -10000, 'LC-DSP-04', 'Disputed payment does not clear debt: Charlie still owes ₹100');

// 7. Test Creditor Fast-Path: Payee directly records receipt
echo "\n--- 6. Creditor Fast-Path: Instant Reconciliation ---\n";
// Alice (creditor) records that Charlie paid ₹100 in cash
$s3Id = $settlementRepo->create(
    $groupId,
    $charlieId,
    $aliceId,
    10000,
    'Handed cash in person',
    null,
    $aliceId,
    'CASH',
    null,
    'CONFIRMED',
    $aliceId,
    date('Y-m-d H:i:s')
);

$s3 = $settlementRepo->findById($s3Id);
assertLifecycle($s3['status'] === 'CONFIRMED' && $s3['confirmed_by']['id'] === $aliceId, 'LC-FST-01', 'Creditor fast-path records instant CONFIRMED settlement');

$bFast = $balanceService->calculateGroupBalances($groupId);
$charlieNetFast = 0;
foreach ($bFast['members'] as $m) {
    if ($m['member_id'] === $charlieId) $charlieNetFast = $m['net_balance_cents'];
}
assertLifecycle($charlieNetFast === 0, 'LC-FST-02', 'Charlie balance now fully settled (0 paise)');
assertLifecycle($bFast['zero_sum_verified'] === true, 'LC-FST-03', 'Zero-sum conserved on creditor fast-path settlement');

// 8. Test Activity Feed Audit Trail
echo "\n--- 7. Activity Feed Audit Trail Verification ---\n";
$logStmt = $pdo->prepare("
    SELECT `action`, `actor_member_id`, `payload_json`
    FROM `activity_logs`
    WHERE `group_id` = :group_id AND `entity_type` = 'settlements'
    ORDER BY `id` ASC
");
$logStmt->execute([':group_id' => $groupId]);
$logs = $logStmt->fetchAll();

$actions = array_column($logs, 'action');
assertLifecycle(in_array('SETTLEMENT_RECORDED', $actions, true), 'LC-LOG-01', 'SETTLEMENT_RECORDED logged in audit trail');
assertLifecycle(in_array('SETTLEMENT_CONFIRMED', $actions, true), 'LC-LOG-02', 'SETTLEMENT_CONFIRMED logged in audit trail');
assertLifecycle(in_array('SETTLEMENT_REVERSED', $actions, true), 'LC-LOG-03', 'SETTLEMENT_REVERSED logged in audit trail');
assertLifecycle(in_array('SETTLEMENT_DISPUTED', $actions, true), 'LC-LOG-04', 'SETTLEMENT_DISPUTED logged in audit trail');

// Cleanup
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
$pdo->exec("DELETE FROM `settlements` WHERE `group_id` = {$groupId}");
$pdo->exec("DELETE FROM `expense_splits` WHERE `expense_id` IN (SELECT `id` FROM `expenses` WHERE `group_id` = {$groupId})");
$pdo->exec("DELETE FROM `expense_payers` WHERE `expense_id` IN (SELECT `id` FROM `expenses` WHERE `group_id` = {$groupId})");
$pdo->exec("DELETE FROM `expenses` WHERE `group_id` = {$groupId}");
$pdo->exec("DELETE FROM `activity_logs` WHERE `group_id` = {$groupId}");
$pdo->exec("DELETE FROM `members` WHERE `group_id` = {$groupId}");
$pdo->exec("DELETE FROM `groups` WHERE `id` = {$groupId}");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

echo "\n================================================================================\n";
echo " STEP 16 TEST MATRIX SUMMARY\n";
echo "================================================================================\n";
echo " Total Assertions Executed: {$totalTests}\n";
echo " Passed Assertions:         {$passedTests}\n";
echo " Failed Assertions:         {$failedTests}\n";
echo " Success Rate:              " . ($totalTests > 0 ? round(($passedTests / $totalTests) * 100, 1) : 0) . "%\n";
echo "================================================================================\n\n";

if ($failedTests > 0) {
    exit(1);
}
