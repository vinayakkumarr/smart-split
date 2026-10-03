<?php

declare(strict_types=1);

/**
 * Smart Split V2 — Test Suite: P3 Final Audit Remediations (Server-Side Idempotency & Invariants)
 * 
 * Validates:
 * 1. Server-authoritative idempotency key enforcement on Expense creation
 * 2. Deduplication safety: repeated requests with the same key produce at most 1 database record
 * 3. Payload mismatch detection: reusing the same key with different amounts/splits is rejected (HTTP 409)
 * 4. Workspace isolation: keys are strictly scoped per group_id
 * 5. Backward compatibility: standard creation without idempotency key continues to operate cleanly
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use App\Core\Env;
use App\Core\Database;

Env::load(dirname(__DIR__) . '/.env');

$pdo = Database::getConnection();

$passed = 0;
$failed = 0;

function assertCondition(bool $cond, string $message): void {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$message}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$message}\n";
        $failed++;
        throw new \RuntimeException("Assertion failed: {$message}");
    }
}

echo "================================================================================\n";
echo " Smart Split V2: P3 Server-Side Idempotency & Financial Deduplication Suite\n";
echo "================================================================================\n\n";

$groupRepo = new \App\Repositories\GroupRepository($pdo);
$memberRepo = new \App\Repositories\MemberRepository($pdo);
$expenseRepo = new \App\Repositories\ExpenseRepository($pdo);
$expenseService = new \App\Services\ExpenseService($expenseRepo, $memberRepo, null, $groupRepo);

// Setup test workspace 1
$group1 = $groupRepo->create('Idempotency Workspace 1', 'INR', null);
$groupId1 = (int) $group1['id'];
$memberA1 = (int) $memberRepo->create($groupId1, 'Alice')['id'];
$memberB1 = (int) $memberRepo->create($groupId1, 'Bob')['id'];

// Setup test workspace 2
$group2 = $groupRepo->create('Idempotency Workspace 2', 'INR', null);
$groupId2 = (int) $group2['id'];
$memberA2 = (int) $memberRepo->create($groupId2, 'Charlie')['id'];
$memberB2 = (int) $memberRepo->create($groupId2, 'Dave')['id'];

// -----------------------------------------------------------------------------
// TEST 1: First Request Creates Expense with Idempotency Record
// -----------------------------------------------------------------------------
echo "--- Test 1: Initial creation with idempotency key ---\n";
$key1 = 'offline_uuid_' . bin2hex(random_bytes(8));
$payload1 = [
    'title' => 'Team Lunch Offsite',
    'total_amount_cents' => 300000,
    'split_type' => 'EQUAL',
    'expense_date' => '2026-06-01',
    'created_by_member_id' => $memberA1,
    'payers' => [['member_id' => $memberA1, 'amount_paid_cents' => 300000]],
    'splits' => [
        ['member_id' => $memberA1, 'amount_owed_cents' => 150000],
        ['member_id' => $memberB1, 'amount_owed_cents' => 150000],
    ],
];

$expenseId1 = $expenseService->createExpense($groupId1, $payload1, $key1);
assertCondition($expenseId1 > 0, "Created expense ID {$expenseId1} returned on first request");

$stmt = $pdo->prepare("SELECT COUNT(*) FROM `expenses` WHERE `group_id` = :group_id");
$stmt->execute([':group_id' => $groupId1]);
assertCondition((int)$stmt->fetchColumn() === 1, "Exactly 1 expense record in database for workspace 1");

$idempStmt = $pdo->prepare("SELECT `expense_id` FROM `idempotency_keys` WHERE `group_id` = :group_id AND `idempotency_key` = :k");
$idempStmt->execute([':group_id' => $groupId1, ':k' => $key1]);
assertCondition((int)$idempStmt->fetchColumn() === $expenseId1, "Idempotency table recorded mapping to expense ID {$expenseId1}");

// -----------------------------------------------------------------------------
// TEST 2: Duplicate Retry with Same Key Returns Existing Expense (No Duplicate)
// -----------------------------------------------------------------------------
echo "\n--- Test 2: Retry with identical idempotency key and payload ---\n";
$expenseIdRetry = $expenseService->createExpense($groupId1, $payload1, $key1);
assertCondition($expenseIdRetry === $expenseId1, "Retry returned original expense ID {$expenseId1}");

$stmt->execute([':group_id' => $groupId1]);
assertCondition((int)$stmt->fetchColumn() === 1, "Database still contains exactly 1 expense (deduplication confirmed)");

// -----------------------------------------------------------------------------
// TEST 3: Reusing Same Key with Mismatched Payload is Rejected (HTTP 409)
// -----------------------------------------------------------------------------
echo "\n--- Test 3: Reusing key with different amount/payload ---\n";
$mismatchedPayload = $payload1;
$mismatchedPayload['total_amount_cents'] = 999900;
$mismatchedPayload['payers'][0]['amount_paid_cents'] = 999900;
$mismatchedPayload['splits'][0]['amount_owed_cents'] = 499950;
$mismatchedPayload['splits'][1]['amount_owed_cents'] = 499950;

$threwMismatch = false;
try {
    $expenseService->createExpense($groupId1, $mismatchedPayload, $key1);
} catch (\InvalidArgumentException $e) {
    if ($e->getCode() === 409 || str_contains($e->getMessage(), 'mismatched')) {
        $threwMismatch = true;
    }
}
assertCondition($threwMismatch, "Reusing idempotency key with conflicting payload threw 409 Conflict exception");

// -----------------------------------------------------------------------------
// TEST 4: Cross-Workspace Isolation for Same Idempotency Key
// -----------------------------------------------------------------------------
echo "\n--- Test 4: Same key used in a different workspace ---\n";
$payload2 = [
    'title' => 'Workspace 2 Dinner',
    'total_amount_cents' => 200000,
    'split_type' => 'EQUAL',
    'expense_date' => '2026-06-01',
    'created_by_member_id' => $memberA2,
    'payers' => [['member_id' => $memberA2, 'amount_paid_cents' => 200000]],
    'splits' => [
        ['member_id' => $memberA2, 'amount_owed_cents' => 100000],
        ['member_id' => $memberB2, 'amount_owed_cents' => 100000],
    ],
];

$expenseId2 = $expenseService->createExpense($groupId2, $payload2, $key1);
assertCondition($expenseId2 > 0 && $expenseId2 !== $expenseId1, "Created distinct expense ID {$expenseId2} in workspace 2 with same key string");

$stmt2 = $pdo->prepare("SELECT COUNT(*) FROM `expenses` WHERE `group_id` = :group_id");
$stmt2->execute([':group_id' => $groupId2]);
assertCondition((int)$stmt2->fetchColumn() === 1, "Workspace 2 contains exactly 1 expense record");

// -----------------------------------------------------------------------------
// TEST 5: Backward Compatibility without Idempotency Key
// -----------------------------------------------------------------------------
echo "\n--- Test 5: Expense creation without idempotency key ---\n";
$legacyPayload = [
    'title' => 'Direct Web Expense',
    'total_amount_cents' => 100000,
    'split_type' => 'EQUAL',
    'expense_date' => '2026-06-01',
    'created_by_member_id' => $memberA1,
    'payers' => [['member_id' => $memberA1, 'amount_paid_cents' => 100000]],
    'splits' => [
        ['member_id' => $memberA1, 'amount_owed_cents' => 50000],
        ['member_id' => $memberB1, 'amount_owed_cents' => 50000],
    ],
];

$legacyId = $expenseService->createExpense($groupId1, $legacyPayload, null);
assertCondition($legacyId > 0, "Created expense ID {$legacyId} without idempotency key");

$stmt->execute([':group_id' => $groupId1]);
assertCondition((int)$stmt->fetchColumn() === 2, "Workspace 1 now has 2 total expenses as expected");

// Cleanup test groups
$pdo->exec("DELETE FROM `groups` WHERE `id` IN ({$groupId1}, {$groupId2})");

echo "\n================================================================================\n";
echo " P3 Server Idempotency Suite Completed: {$passed} Passed, {$failed} Failed.\n";
echo "================================================================================\n\n";

exit($failed > 0 ? 1 : 0);
