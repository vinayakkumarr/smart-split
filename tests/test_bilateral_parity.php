<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Services/BalanceService.php';
require_once __DIR__ . '/../src/Repositories/MemberRepository.php';

use App\Core\Env;
use App\Core\Database;
use App\Services\BalanceService;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

echo "\n====================================================================\n";
echo " BILATERAL BALANCES PARITY & REGRESSION TEST SUITE\n";
echo "====================================================================\n\n";

$passed = 0;
$failed = 0;

$assertCheck = function (bool $condition, string $msg) use (&$passed, &$failed) {
    if ($condition) {
        echo "  [PASS] {$msg}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failed++;
    }
};

/**
 * Executes the EXACT LEGACY (6-query) bilateral balances algorithm.
 */
function calculateBilateralLegacy(PDO $pdo, int $groupId): array {
    $memberRepo = new \App\Repositories\MemberRepository($pdo);
    $members = $memberRepo->findByGroupId($groupId);
    $memberNames = [];
    foreach ($members as $m) {
        $memberNames[(int) $m['id']] = (string) $m['name'];
    }

    // 1. Fetch all active expenses
    $expStmt = $pdo->prepare("
        SELECT `id`, `total_amount_cents`
        FROM `expenses`
        WHERE `group_id` = :group_id AND `is_deleted` = 0
    ");
    $expStmt->execute([':group_id' => $groupId]);
    $expenses = $expStmt->fetchAll(PDO::FETCH_ASSOC);

    $pairwiseDebt = [];

    if (!empty($expenses)) {
        $expIds = array_column($expenses, 'id');
        $inClause = implode(',', array_fill(0, count($expIds), '?'));

        // 2. Payers
        $payerStmt = $pdo->prepare("
            SELECT `expense_id`, `member_id`, `amount_paid_cents`
            FROM `expense_payers`
            WHERE `expense_id` IN ({$inClause})
        ");
        $payerStmt->execute($expIds);
        $allPayers = $payerStmt->fetchAll(PDO::FETCH_ASSOC);

        $payersByExp = [];
        foreach ($allPayers as $p) {
            $payersByExp[$p['expense_id']][] = [
                'member_id' => (int) $p['member_id'],
                'amount_paid_cents' => (int) $p['amount_paid_cents'],
            ];
        }

        // 3. Splits
        $splitStmt = $pdo->prepare("
            SELECT `expense_id`, `member_id`, `amount_owed_cents`
            FROM `expense_splits`
            WHERE `expense_id` IN ({$inClause})
        ");
        $splitStmt->execute($expIds);
        $allSplits = $splitStmt->fetchAll(PDO::FETCH_ASSOC);

        $splitsByExp = [];
        foreach ($allSplits as $s) {
            $splitsByExp[$s['expense_id']][] = [
                'member_id' => (int) $s['member_id'],
                'amount_owed_cents' => (int) $s['amount_owed_cents'],
            ];
        }

        foreach ($expenses as $exp) {
            $expId = $exp['id'];
            $totalAmount = (int) $exp['total_amount_cents'];
            if ($totalAmount <= 0) continue;

            $payers = $payersByExp[$expId] ?? [];
            $splits = $splitsByExp[$expId] ?? [];

            foreach ($splits as $split) {
                $debtorId = $split['member_id'];
                $owedTotal = $split['amount_owed_cents'];

                foreach ($payers as $payer) {
                    $creditorId = $payer['member_id'];
                    if ($debtorId === $creditorId) continue;

                    $paidAmount = $payer['amount_paid_cents'];
                    $shareOwedToPayer = (int) round(($paidAmount * $owedTotal) / $totalAmount);

                    $pairwiseDebt[$debtorId][$creditorId] = ($pairwiseDebt[$debtorId][$creditorId] ?? 0) + $shareOwedToPayer;
                }
            }
        }
    }

    // 4. Settlements
    $settleStmt = $pdo->prepare("
        SELECT `payer_member_id`, `payee_member_id`, `amount_cents`
        FROM `settlements`
        WHERE `group_id` = :group_id AND `is_deleted` = 0
    ");
    $settleStmt->execute([':group_id' => $groupId]);
    $settlements = $settleStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($settlements as $s) {
        $payerId = (int) $s['payer_member_id'];
        $payeeId = (int) $s['payee_member_id'];
        $amount = (int) $s['amount_cents'];
        $pairwiseDebt[$payerId][$payeeId] = ($pairwiseDebt[$payerId][$payeeId] ?? 0) - $amount;
    }

    // 5. Unique pairs
    $memberIds = array_keys($memberNames);
    sort($memberIds);
    $pairs = [];

    for ($i = 0; $i < count($memberIds); $i++) {
        for ($j = $i + 1; $j < count($memberIds); $j++) {
            $m1 = $memberIds[$i];
            $m2 = $memberIds[$j];

            $m1OwesM2 = $pairwiseDebt[$m1][$m2] ?? 0;
            $m2OwesM1 = $pairwiseDebt[$m2][$m1] ?? 0;
            $net = $m1OwesM2 - $m2OwesM1;

            if ($net > 0) {
                $pairs[] = [
                    'from_member_id' => $m1,
                    'from_member_name' => $memberNames[$m1] ?? "Member #{$m1}",
                    'to_member_id' => $m2,
                    'to_member_name' => $memberNames[$m2] ?? "Member #{$m2}",
                    'amount_cents' => $net,
                    'status' => 'OWES',
                ];
            } elseif ($net < 0) {
                $pairs[] = [
                    'from_member_id' => $m2,
                    'from_member_name' => $memberNames[$m2] ?? "Member #{$m2}",
                    'to_member_id' => $m1,
                    'to_member_name' => $memberNames[$m1] ?? "Member #{$m1}",
                    'amount_cents' => abs($net),
                    'status' => 'OWES',
                ];
            } else {
                $pairs[] = [
                    'from_member_id' => $m1,
                    'from_member_name' => $memberNames[$m1] ?? "Member #{$m1}",
                    'to_member_id' => $m2,
                    'to_member_name' => $memberNames[$m2] ?? "Member #{$m2}",
                    'amount_cents' => 0,
                    'status' => 'SETTLED',
                ];
            }
        }
    }

    return ['group_id' => $groupId, 'pairs' => $pairs];
}

/**
 * Asserts full parity between the legacy 6-query result and the live service getBilateralBalances() result.
 */
function assertBilateralParity(PDO $pdo, int $groupId, string $scenarioName, callable $assertCheck): void {
    $service = new BalanceService($pdo);

    $legacy = calculateBilateralLegacy($pdo, $groupId);
    $current = $service->getBilateralBalances($groupId);

    $jsonLegacy = json_encode($legacy);
    $jsonCurrent = json_encode($current);

    $isEqual = ($jsonLegacy === $jsonCurrent);
    $pairCount = count($legacy['pairs']);
    $assertCheck($isEqual, "{$scenarioName} (Pairs: {$pairCount}, Exact Match: " . ($isEqual ? 'YES' : 'NO') . ")");
}

try {
    // -------------------------------------------------------------
    // Test 1: Verify pre-existing groups in database for parity
    // -------------------------------------------------------------
    $allGroups = $pdo->query("SELECT id, name FROM `groups` ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allGroups as $g) {
        $gid = (int) $g['id'];
        assertBilateralParity($pdo, $gid, "Pre-existing Group #{$gid} ('{$g['name']}')", $assertCheck);
    }

    // -------------------------------------------------------------
    // Scenario 2: Empty Workspace (3 members, 0 expenses, 0 settlements)
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Empty Bilateral Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid2 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Alice', UUID())")->execute([':gid' => $gid2]);
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Bob', UUID())")->execute([':gid' => $gid2]);
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Charlie', UUID())")->execute([':gid' => $gid2]);

    assertBilateralParity($pdo, $gid2, "Scenario 2: Empty Workspace (3 members, 0 exp, 3 SETTLED pairs)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 3: Single Payer, Multiple Splits (Standard Equal Split)
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Single Payer Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid3 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Alice', UUID())")->execute([':gid' => $gid3]);
    $mA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Bob', UUID())")->execute([':gid' => $gid3]);
    $mB = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Charlie', UUID())")->execute([':gid' => $gid3]);
    $mC = (int) $pdo->lastInsertId();

    // Alice pays 9000 cents for Alice, Bob, Charlie (3000 each)
    $pdo->prepare("INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `expense_date`, `created_by_member_id`) VALUES (:gid, 'Dinner', 9000, '2026-10-10', :m)")
        ->execute([':gid' => $gid3, ':m' => $mA]);
    $eId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `expense_payers` (`expense_id`, `member_id`, `amount_paid_cents`) VALUES (:eid, :mid, 9000)")
        ->execute([':eid' => $eId, ':mid' => $mA]);

    $pdo->prepare("INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`) VALUES (:eid, :mid, 3000)")
        ->execute([':eid' => $eId, ':mid' => $mA]);
    $pdo->prepare("INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`) VALUES (:eid, :mid, 3000)")
        ->execute([':eid' => $eId, ':mid' => $mB]);
    $pdo->prepare("INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`) VALUES (:eid, :mid, 3000)")
        ->execute([':eid' => $eId, ':mid' => $mC]);

    assertBilateralParity($pdo, $gid3, "Scenario 3: Single Payer Equal Split (Bob owes Alice 3000, Charlie owes Alice 3000)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 4: Multi-Payer Expense (Alice & Bob co-pay 6000 + 4000 = 10000)
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Multi Payer Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid4 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Alice', UUID())")->execute([':gid' => $gid4]);
    $mA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Bob', UUID())")->execute([':gid' => $gid4]);
    $mB = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Charlie', UUID())")->execute([':gid' => $gid4]);
    $mC = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Dana', UUID())")->execute([':gid' => $gid4]);
    $mD = (int) $pdo->lastInsertId();

    // Expense: 10,000 cents. Alice pays 6000, Bob pays 4000. Splits: Charlie 5000, Dana 5000.
    $pdo->prepare("INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `expense_date`, `created_by_member_id`) VALUES (:gid, 'Hotel', 10000, '2026-10-10', :m)")
        ->execute([':gid' => $gid4, ':m' => $mA]);
    $eId4 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `expense_payers` (`expense_id`, `member_id`, `amount_paid_cents`) VALUES (:eid, :mid, 6000)")
        ->execute([':eid' => $eId4, ':mid' => $mA]);
    $pdo->prepare("INSERT INTO `expense_payers` (`expense_id`, `member_id`, `amount_paid_cents`) VALUES (:eid, :mid, 4000)")
        ->execute([':eid' => $eId4, ':mid' => $mB]);

    $pdo->prepare("INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`) VALUES (:eid, :mid, 5000)")
        ->execute([':eid' => $eId4, ':mid' => $mC]);
    $pdo->prepare("INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`) VALUES (:eid, :mid, 5000)")
        ->execute([':eid' => $eId4, ':mid' => $mD]);

    assertBilateralParity($pdo, $gid4, "Scenario 4: Multi-Payer Proportional Division (Co-Payers: 60/40 ratio)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 5: Multiple Expenses + Offsetting Settlement
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Settlement Offset Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid5 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Alice', UUID())")->execute([':gid' => $gid5]);
    $mA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Bob', UUID())")->execute([':gid' => $gid5]);
    $mB = (int) $pdo->lastInsertId();

    // Exp 1: Alice pays 5000 for Bob (Bob owes Alice 5000)
    $pdo->prepare("INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `expense_date`, `created_by_member_id`) VALUES (:gid, 'Exp1', 5000, '2026-10-10', :m)")
        ->execute([':gid' => $gid5, ':m' => $mA]);
    $e1 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `expense_payers` (`expense_id`, `member_id`, `amount_paid_cents`) VALUES (:eid, :mid, 5000)")->execute([':eid' => $e1, ':mid' => $mA]);
    $pdo->prepare("INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`) VALUES (:eid, :mid, 5000)")->execute([':eid' => $e1, ':mid' => $mB]);

    // Settlement: Bob settles 3000 to Alice (reduces debt from 5000 to 2000)
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `status`) VALUES (:gid, :p, :r, 3000, 'CONFIRMED')")
        ->execute([':gid' => $gid5, ':p' => $mB, ':r' => $mA]);

    assertBilateralParity($pdo, $gid5, "Scenario 5: Expenses with Active Settlement Offset (Net remaining 2000)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 6: Soft-Deleted Expenses & Deleted Settlements
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Deleted Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid6 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Alice', UUID())")->execute([':gid' => $gid6]);
    $mA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Bob', UUID())")->execute([':gid' => $gid6]);
    $mB = (int) $pdo->lastInsertId();

    // Active Exp: 3000
    $pdo->prepare("INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `expense_date`, `created_by_member_id`, `is_deleted`) VALUES (:gid, 'Active', 3000, '2026-10-10', :m, 0)")
        ->execute([':gid' => $gid6, ':m' => $mA]);
    $ea = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `expense_payers` (`expense_id`, `member_id`, `amount_paid_cents`) VALUES (:eid, :mid, 3000)")->execute([':eid' => $ea, ':mid' => $mA]);
    $pdo->prepare("INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`) VALUES (:eid, :mid, 3000)")->execute([':eid' => $ea, ':mid' => $mB]);

    // Deleted Exp: 99999
    $pdo->prepare("INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `expense_date`, `created_by_member_id`, `is_deleted`) VALUES (:gid, 'Deleted', 99999, '2026-10-10', :m, 1)")
        ->execute([':gid' => $gid6, ':m' => $mA]);
    $ed = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `expense_payers` (`expense_id`, `member_id`, `amount_paid_cents`) VALUES (:eid, :mid, 99999)")->execute([':eid' => $ed, ':mid' => $mA]);
    $pdo->prepare("INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`) VALUES (:eid, :mid, 99999)")->execute([':eid' => $ed, ':mid' => $mB]);

    // Deleted Settlement: 3000
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `status`, `is_deleted`) VALUES (:gid, :p, :r, 3000, 'CONFIRMED', 1)")
        ->execute([':gid' => $gid6, ':p' => $mB, ':r' => $mA]);

    assertBilateralParity($pdo, $gid6, "Scenario 6: Soft-Deleted Expenses & Settlements Filtered (Active only)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 7: Partial Allocations (Payer with No Splits / Split with No Payers)
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Partial Alloc Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid7 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Alice', UUID())")->execute([':gid' => $gid7]);
    $mA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Bob', UUID())")->execute([':gid' => $gid7]);
    $mB = (int) $pdo->lastInsertId();

    // Exp with Payer only, 0 splits
    $pdo->prepare("INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `expense_date`, `created_by_member_id`) VALUES (:gid, 'PayerOnly', 4000, '2026-10-10', :m)")
        ->execute([':gid' => $gid7, ':m' => $mA]);
    $ePayerOnly = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `expense_payers` (`expense_id`, `member_id`, `amount_paid_cents`) VALUES (:eid, :mid, 4000)")->execute([':eid' => $ePayerOnly, ':mid' => $mA]);

    // Exp with Split only, 0 payers
    $pdo->prepare("INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `expense_date`, `created_by_member_id`) VALUES (:gid, 'SplitOnly', 2000, '2026-10-10', :m)")
        ->execute([':gid' => $gid7, ':m' => $mA]);
    $eSplitOnly = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`) VALUES (:eid, :mid, 2000)")->execute([':eid' => $eSplitOnly, ':mid' => $mB]);

    // Exp with Neither (bare expense)
    $pdo->prepare("INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `expense_date`, `created_by_member_id`) VALUES (:gid, 'Bare', 1000, '2026-10-10', :m)")
        ->execute([':gid' => $gid7, ':m' => $mA]);

    assertBilateralParity($pdo, $gid7, "Scenario 7: Partial Allocations (Missing payers/splits produce 0 debt)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 8: Micro-amounts and Integer Rounding
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Micro Amounts Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid8 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Alice', UUID())")->execute([':gid' => $gid8]);
    $mA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Bob', UUID())")->execute([':gid' => $gid8]);
    $mB = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Charlie', UUID())")->execute([':gid' => $gid8]);
    $mC = (int) $pdo->lastInsertId();

    // 10 cents split 3 ways (3, 3, 4) paid by Alice (10 cents)
    $pdo->prepare("INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `expense_date`, `created_by_member_id`) VALUES (:gid, 'Dime', 10, '2026-10-10', :m)")
        ->execute([':gid' => $gid8, ':m' => $mA]);
    $eMicro = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `expense_payers` (`expense_id`, `member_id`, `amount_paid_cents`) VALUES (:eid, :mid, 10)")->execute([':eid' => $eMicro, ':mid' => $mA]);
    $pdo->prepare("INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`) VALUES (:eid, :mid, 3)")->execute([':eid' => $eMicro, ':mid' => $mA]);
    $pdo->prepare("INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`) VALUES (:eid, :mid, 3)")->execute([':eid' => $eMicro, ':mid' => $mB]);
    $pdo->prepare("INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`) VALUES (:eid, :mid, 4)")->execute([':eid' => $eMicro, ':mid' => $mC]);

    assertBilateralParity($pdo, $gid8, "Scenario 8: Micro-amounts and Integer Rounding (10 cents split 3, 3, 4)", $assertCheck);
    $pdo->rollBack();

} catch (Throwable $e) {
    echo "\n  [CRITICAL EXCEPTION] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\n====================================================================\n";
echo " BILATERAL PARITY RESULTS: {$passed} PASSED | {$failed} FAILED\n";
echo "====================================================================\n\n";

exit($failed > 0 ? 1 : 0);
