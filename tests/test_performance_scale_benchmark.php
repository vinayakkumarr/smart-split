<?php

declare(strict_types=1);

/**
 * Smart Split V2 — Final Production Readiness: Scale & Performance Benchmark Suite
 *
 * Measures execution latency, memory footprint, and financial invariant preservation
 * across bounded datasets: 10, 100, 500, and 1,000 expenses.
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Repositories\GroupRepository;
use App\Repositories\MemberRepository;
use App\Repositories\ExpenseRepository;
use App\Repositories\SettlementRepository;
use App\Services\BalanceService;
use App\Services\SettlementEngine;
use App\Utils\Money;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

echo "\n================================================================================\n";
echo " FINAL PRODUCTION GATE: SCALE & PERFORMANCE BENCHMARK SUITE\n";
echo "================================================================================\n\n";

$groupRepo = new GroupRepository($pdo);
$memberRepo = new MemberRepository($pdo);
$expenseRepo = new ExpenseRepository($pdo);
$settlementRepo = new SettlementRepository($pdo);
$settlementEngine = new SettlementEngine();
$balanceService = new BalanceService($pdo, $memberRepo);

$group = $groupRepo->create('Benchmark Scale Group ' . bin2hex(random_bytes(3)), 'INR');
$groupId = (int) $group['id'];
$token = (string) $group['invite_token'];

// Create 10 distinct members in the group
$members = [];
for ($i = 1; $i <= 10; $i++) {
    $m = $memberRepo->create($groupId, "Member {$i}");
    $members[] = (int) $m['id'];
}

$scales = [10, 100, 500, 1000];
$benchmarks = [];

$currentCount = 0;

foreach ($scales as $targetCount) {
    $needed = $targetCount - $currentCount;
    
    // Seed expenses in batch
    $pdo->beginTransaction();
    for ($e = 0; $e < $needed; $e++) {
        $payerIdx = ($currentCount + $e) % count($members);
        $payerId = $members[$payerIdx];
        $amount = 1000 + (($currentCount + $e) * 10); // in cents
        
        $splits = [];
        $splitCount = 5; // Split among 5 members
        $share = (int) intdiv($amount, $splitCount);
        $rem = $amount % $splitCount;
        
        for ($s = 0; $s < $splitCount; $s++) {
            $memberIdx = ($payerIdx + $s) % count($members);
            $owed = $share + ($s < $rem ? 1 : 0);
            $splits[] = [
                'member_id' => $members[$memberIdx],
                'amount_owed_cents' => $owed,
                'split_value' => null
            ];
        }
        
        $expenseRepo->createExpense(
            $groupId,
            "Expense #" . ($currentCount + $e + 1),
            $amount,
            'EQUAL',
            date('Y-m-d', strtotime("-".($e % 30)." days")),
            $payerId,
            [['member_id' => $payerId, 'amount_paid_cents' => $amount]],
            $splits
        );
    }
    $pdo->commit();
    $currentCount = $targetCount;
    
    // Benchmark 1: Balance Calculation
    $memStart = memory_get_usage(true);
    $t0 = microtime(true);
    $balanceResult = $balanceService->calculateGroupBalances($groupId);
    $t1 = microtime(true);
    $memEnd = memory_get_usage(true);
    
    $balanceDurationMs = ($t1 - $t0) * 1000.0;
    $memUsedKb = ($memEnd - $memStart) / 1024.0;
    
    // Verify Zero-Sum Conservation Invariant: sum of net balances == 0
    $netSum = 0;
    $netBalancesForEngine = [];
    foreach ($balanceResult['members'] as $b) {
        $netSum += (int) $b['net_balance_cents'];
        $netBalancesForEngine[(int) $b['member_id']] = (int) $b['net_balance_cents'];
    }
    
    // Benchmark 2: Debt Simplification
    $t2 = microtime(true);
    $simplifiedPlan = $settlementEngine->simplifyDebts($netBalancesForEngine);
    $t3 = microtime(true);
    $simplificationDurationMs = ($t3 - $t2) * 1000.0;
    
    // Benchmark 3: Expense List Retrieval (Paginated/Full)
    $t4 = microtime(true);
    $expenseList = $expenseRepo->findByGroupId($groupId);
    $t5 = microtime(true);
    $listDurationMs = ($t5 - $t4) * 1000.0;
    
    $benchmarks[$targetCount] = [
        'count' => $targetCount,
        'balance_ms' => round($balanceDurationMs, 2),
        'simplification_ms' => round($simplificationDurationMs, 2),
        'list_ms' => round($listDurationMs, 2),
        'total_compute_ms' => round($balanceDurationMs + $simplificationDurationMs + $listDurationMs, 2),
        'memory_peak_kb' => round(memory_get_peak_usage(true) / 1024.0, 2),
        'zero_sum_preserved' => ($netSum === 0),
        'settlement_plan_transfers' => count($simplifiedPlan),
    ];
    
    echo sprintf(
        " Scale: %4d expenses | Balance Calc: %6.2f ms | Debt Simplification: %5.2f ms | List Fetch: %6.2f ms | Total: %6.2f ms | Net Sum: %d [OK]\n",
        $targetCount,
        $balanceDurationMs,
        $simplificationDurationMs,
        $listDurationMs,
        $balanceDurationMs + $simplificationDurationMs + $listDurationMs,
        $netSum
    );
}

// Cleanup benchmark group
try {
    $pdo->exec("DELETE FROM `expense_splits` WHERE `expense_id` IN (SELECT `id` FROM `expenses` WHERE `group_id` = {$groupId})");
    $pdo->exec("DELETE FROM `expenses` WHERE `group_id` = {$groupId}");
    $pdo->exec("DELETE FROM `members` WHERE `group_id` = {$groupId}");
    $pdo->exec("DELETE FROM `groups` WHERE `id` = {$groupId}");
} catch (\Throwable $e) {}

echo "\n================================================================================\n";
echo " BENCHMARK SUMMARY & PERFORMANCE TARGET VERIFICATION\n";
echo "================================================================================\n";
$allPassed = true;
foreach ($benchmarks as $count => $res) {
    $status = ($res['zero_sum_preserved'] && $res['total_compute_ms'] < 2000.0) ? 'PASS' : 'FAIL';
    if ($status === 'FAIL') {
        $allPassed = false;
    }
    echo sprintf(
        " [%s] %4d Expenses: Total Compute = %6.2f ms (Balance: %6.2f ms, Simplify: %5.2f ms, List: %6.2f ms), Memory Peak = %6.2f KB, Zero-Sum = %s\n",
        $status,
        $count,
        $res['total_compute_ms'],
        $res['balance_ms'],
        $res['simplification_ms'],
        $res['list_ms'],
        $res['memory_peak_kb'],
        $res['zero_sum_preserved'] ? 'TRUE' : 'FALSE'
    );
}

echo "================================================================================\n";
if ($allPassed) {
    echo ">>> VERDICT: SCALE & PERFORMANCE BENCHMARK 100% PASS <<<\n";
    exit(0);
} else {
    echo ">>> VERDICT: PERFORMANCE BENCHMARK FAILED <<<\n";
    exit(1);
}
