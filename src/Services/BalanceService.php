<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\MemberRepository;
use PDO;
use RuntimeException;

/**
 * Service calculating Net Balances, Zero-Sum Integrity Assertions, and Itemized Ledgers.
 */
class BalanceService
{
    private PDO $pdo;
    private MemberRepository $memberRepo;

    public function __construct(?PDO $pdo = null, ?MemberRepository $memberRepo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->memberRepo = $memberRepo ?? new MemberRepository($this->pdo);
    }

    /**
     * Compute net balances for all group members with zero-sum invariant check.
     *
     * @param int $groupId
     * @return array{
     *   group_id: int,
     *   total_spending_cents: int,
     *   total_settled_cents: int,
     *   zero_sum_verified: bool,
     *   members: array<array<string, mixed>>
     * }
     * @throws RuntimeException If zero-sum integrity is violated.
     */
    public function calculateGroupBalances(int $groupId): array
    {
        return Database::transaction(function (PDO $pdo) use ($groupId): array {
            $members = $this->memberRepo->findByGroupId($groupId);
            if (empty($members)) {
                return [
                    'group_id' => $groupId,
                    'total_spending_cents' => 0,
                    'total_settled_cents' => 0,
                    'zero_sum_verified' => true,
                    'members' => [],
                ];
            }

            $memberMap = [];
            foreach ($members as $m) {
                $memberId = (int) $m['id'];
                $memberMap[$memberId] = [
                    'member_id' => $memberId,
                    'name' => $m['name'],
                    'upi_id' => $m['upi_id'] ?? null,
                    'total_paid_cents' => 0,
                    'total_owed_cents' => 0,
                    'settlements_sent_cents' => 0,
                    'settlements_received_cents' => 0,
                    'net_balance_cents' => 0,
                    'status' => 'SETTLED',
                ];
            }

            // 1. Total Paid per Member
            $paidStmt = $pdo->prepare("
                SELECT p.`member_id`, COALESCE(SUM(p.`amount_paid_cents`), 0) AS `total_paid`
                FROM `expense_payers` p
                JOIN `expenses` e ON p.`expense_id` = e.`id`
                WHERE e.`group_id` = :group_id AND e.`is_deleted` = 0
                GROUP BY p.`member_id`
            ");
            $paidStmt->execute([':group_id' => $groupId]);
            $paidRows = $paidStmt->fetchAll();

            foreach ($paidRows as $row) {
                $mId = (int) $row['member_id'];
                if (isset($memberMap[$mId])) {
                    $memberMap[$mId]['total_paid_cents'] = (int) $row['total_paid'];
                }
            }

            // 2. Total Owed per Member
            $owedStmt = $pdo->prepare("
                SELECT s.`member_id`, COALESCE(SUM(s.`amount_owed_cents`), 0) AS `total_owed`
                FROM `expense_splits` s
                JOIN `expenses` e ON s.`expense_id` = e.`id`
                WHERE e.`group_id` = :group_id AND e.`is_deleted` = 0
                GROUP BY s.`member_id`
            ");
            $owedStmt->execute([':group_id' => $groupId]);
            $owedRows = $owedStmt->fetchAll();

            foreach ($owedRows as $row) {
                $mId = (int) $row['member_id'];
                if (isset($memberMap[$mId])) {
                    $memberMap[$mId]['total_owed_cents'] = (int) $row['total_owed'];
                }
            }

            // 3. Settlements Sent (Payer)
            $sentStmt = $pdo->prepare("
                SELECT `payer_member_id`, COALESCE(SUM(`amount_cents`), 0) AS `total_sent`
                FROM `settlements`
                WHERE `group_id` = :group_id AND `is_deleted` = 0 AND `status` = 'CONFIRMED'
                GROUP BY `payer_member_id`
            ");
            $sentStmt->execute([':group_id' => $groupId]);
            $sentRows = $sentStmt->fetchAll();

            foreach ($sentRows as $row) {
                $mId = (int) $row['payer_member_id'];
                if (isset($memberMap[$mId])) {
                    $memberMap[$mId]['settlements_sent_cents'] = (int) $row['total_sent'];
                }
            }

            // 4. Settlements Received (Payee)
            $recvStmt = $pdo->prepare("
                SELECT `payee_member_id`, COALESCE(SUM(`amount_cents`), 0) AS `total_received`
                FROM `settlements`
                WHERE `group_id` = :group_id AND `is_deleted` = 0 AND `status` = 'CONFIRMED'
                GROUP BY `payee_member_id`
            ");
            $recvStmt->execute([':group_id' => $groupId]);
            $recvRows = $recvStmt->fetchAll();

            foreach ($recvRows as $row) {
                $mId = (int) $row['payee_member_id'];
                if (isset($memberMap[$mId])) {
                    $memberMap[$mId]['settlements_received_cents'] = (int) $row['total_received'];
                }
            }

            // 5. Compute Net Balances & Verify Zero-Sum Invariant
            $netSum = 0;
            $totalSpending = 0;
            $totalSettled = 0;

            foreach ($memberMap as $mId => &$data) {
                $net = $data['total_paid_cents'] - $data['total_owed_cents']
                    + $data['settlements_sent_cents'] - $data['settlements_received_cents'];

                $data['net_balance_cents'] = $net;
                $netSum += $net;
                $totalSpending += $data['total_paid_cents'];
                $totalSettled += $data['settlements_sent_cents'];

                if ($net > 0) {
                    $data['status'] = 'CREDITOR';
                } elseif ($net < 0) {
                    $data['status'] = 'DEBTOR';
                } else {
                    $data['status'] = 'SETTLED';
                }
            }
            unset($data);

            // Strict mathematical zero-sum assertion
            if ($netSum !== 0) {
                throw new RuntimeException(
                    "Critical mathematical integrity violation: Group balances do not sum to zero (net discrepancy: {$netSum} paise)."
                );
            }

            return [
                'group_id' => $groupId,
                'total_spending_cents' => $totalSpending,
                'total_settled_cents' => $totalSettled,
                'zero_sum_verified' => true,
                'members' => array_values($memberMap),
            ];
        });
    }

    /**
     * Fetch an itemized ledger history for a specific group member.
     *
     * @param int $groupId
     * @param int $memberId
     * @return array<string, mixed>
     */
    public function getMemberItemizedLedger(int $groupId, int $memberId): array
    {
        $member = $this->memberRepo->findById($memberId);
        if (!$member || (int) $member['group_id'] !== $groupId) {
            throw new RuntimeException("Member not found in this group.");
        }

        // Expenses where member paid
        $paidStmt = $this->pdo->prepare("
            SELECT e.`id` AS `expense_id`, e.`title`, e.`expense_date`, p.`amount_paid_cents`
            FROM `expense_payers` p
            JOIN `expenses` e ON p.`expense_id` = e.`id`
            WHERE e.`group_id` = :group_id AND p.`member_id` = :member_id AND e.`is_deleted` = 0
            ORDER BY e.`expense_date` DESC
        ");
        $paidStmt->execute([':group_id' => $groupId, ':member_id' => $memberId]);
        $paidItems = $paidStmt->fetchAll();

        // Expenses where member consumed/owed
        $owedStmt = $this->pdo->prepare("
            SELECT e.`id` AS `expense_id`, e.`title`, e.`expense_date`, s.`amount_owed_cents`, s.`split_value`
            FROM `expense_splits` s
            JOIN `expenses` e ON s.`expense_id` = e.`id`
            WHERE e.`group_id` = :group_id AND s.`member_id` = :member_id AND e.`is_deleted` = 0
            ORDER BY e.`expense_date` DESC
        ");
        $owedStmt->execute([':group_id' => $groupId, ':member_id' => $memberId]);
        $owedItems = $owedStmt->fetchAll();

        // Settlements sent
        $sentStmt = $this->pdo->prepare("
            SELECT s.`id` AS `settlement_id`, s.`amount_cents`, s.`settled_date`, s.`notes`, m.`name` AS `payee_name`
            FROM `settlements` s
            JOIN `members` m ON s.`payee_member_id` = m.`id`
            WHERE s.`group_id` = :group_id AND s.`payer_member_id` = :member_id AND s.`is_deleted` = 0
            ORDER BY s.`settled_date` DESC
        ");
        $sentStmt->execute([':group_id' => $groupId, ':member_id' => $memberId]);
        $sentItems = $sentStmt->fetchAll();

        // Settlements received
        $recvStmt = $this->pdo->prepare("
            SELECT s.`id` AS `settlement_id`, s.`amount_cents`, s.`settled_date`, s.`notes`, m.`name` AS `payer_name`
            FROM `settlements` s
            JOIN `members` m ON s.`payer_member_id` = m.`id`
            WHERE s.`group_id` = :group_id AND s.`payee_member_id` = :member_id AND s.`is_deleted` = 0
            ORDER BY s.`settled_date` DESC
        ");
        $recvStmt->execute([':group_id' => $groupId, ':member_id' => $memberId]);
        $recvItems = $recvStmt->fetchAll();

        return [
            'member' => [
                'id' => (int) $member['id'],
                'name' => $member['name'],
            ],
            'paid_expenses' => array_map(function (array $r) {
                return [
                    'expense_id' => (int) $r['expense_id'],
                    'title' => $r['title'],
                    'expense_date' => $r['expense_date'],
                    'amount_paid_cents' => (int) $r['amount_paid_cents'],
                ];
            }, $paidItems),
            'consumed_expenses' => array_map(function (array $r) {
                return [
                    'expense_id' => (int) $r['expense_id'],
                    'title' => $r['title'],
                    'expense_date' => $r['expense_date'],
                    'amount_owed_cents' => (int) $r['amount_owed_cents'],
                    'split_value' => $r['split_value'] !== null ? (float) $r['split_value'] : null,
                ];
            }, $owedItems),
            'settlements_sent' => array_map(function (array $r) {
                return [
                    'settlement_id' => (int) $r['settlement_id'],
                    'payee_name' => $r['payee_name'],
                    'amount_cents' => (int) $r['amount_cents'],
                    'settled_date' => $r['settled_date'],
                    'notes' => $r['notes'],
                ];
            }, $sentItems),
            'settlements_received' => array_map(function (array $r) {
                return [
                    'settlement_id' => (int) $r['settlement_id'],
                    'payer_name' => $r['payer_name'],
                    'amount_cents' => (int) $r['amount_cents'],
                    'settled_date' => $r['settled_date'],
                    'notes' => $r['notes'],
                ];
            }, $recvItems),
        ];
    }

    /**
     * Compute visual spending analytics and category distribution.
     *
     * @param int $groupId
     * @return array<string, mixed>
     */
    public function getAnalyticsSummary(int $groupId): array
    {
        // 1. Total Spend
        $totalStmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(`total_amount_cents`), 0) AS `total_spend`
            FROM `expenses`
            WHERE `group_id` = :group_id AND `is_deleted` = 0
        ");
        $totalStmt->execute([':group_id' => $groupId]);
        $totalSpendCents = (int) $totalStmt->fetchColumn();

        // 2. Category Breakdown
        $catStmt = $this->pdo->prepare("
            SELECT COALESCE(c.`id`, 0) AS `category_id`,
                   COALESCE(c.`slug`, 'general') AS `category_slug`,
                   COALESCE(c.`name`, 'General') AS `category_name`,
                   COALESCE(c.`icon`, '📦') AS `category_icon`,
                   COALESCE(c.`color_hex`, '#475569') AS `category_color`,
                   SUM(e.`total_amount_cents`) AS `spent_cents`,
                   COUNT(e.`id`) AS `expense_count`
            FROM `expenses` e
            LEFT JOIN `categories` c ON e.`category_id` = c.`id`
            WHERE e.`group_id` = :group_id AND e.`is_deleted` = 0
            GROUP BY c.`id`, c.`slug`, c.`name`, c.`icon`, c.`color_hex`
            ORDER BY `spent_cents` DESC
        ");
        $catStmt->execute([':group_id' => $groupId]);
        $categoryRows = $catStmt->fetchAll();

        $categories = array_map(function (array $r) use ($totalSpendCents) {
            $spent = (int) $r['spent_cents'];
            $pct = $totalSpendCents > 0 ? round(($spent / $totalSpendCents) * 100, 1) : 0;
            return [
                'id' => $r['category_id'] ? (int) $r['category_id'] : null,
                'slug' => (string) $r['category_slug'],
                'name' => (string) $r['category_name'],
                'icon' => (string) $r['category_icon'],
                'color' => (string) $r['category_color'],
                'spent_cents' => $spent,
                'total_cents' => $spent,
                'expense_count' => (int) $r['expense_count'],
                'percentage' => $pct,
            ];
        }, $categoryRows);

        // 3. Daily Burn Rate Trends
        $trendStmt = $this->pdo->prepare("
            SELECT `expense_date`, SUM(`total_amount_cents`) AS `daily_cents`, COUNT(`id`) AS `daily_count`
            FROM `expenses`
            WHERE `group_id` = :group_id AND `is_deleted` = 0
            GROUP BY `expense_date`
            ORDER BY `expense_date` ASC
        ");
        $trendStmt->execute([':group_id' => $groupId]);
        $dailyTrends = array_map(function (array $r) {
            return [
                'date' => (string) $r['expense_date'],
                'spent_cents' => (int) $r['daily_cents'],
                'count' => (int) $r['daily_count'],
            ];
        }, $trendStmt->fetchAll());

        // 4. Member Outlay Comparison (Paid upfront vs Share consumed)
        $balances = $this->calculateGroupBalances($groupId);
        $members = $balances['members'];
        $memberOutlay = array_map(function (array $m) {
            return [
                'member_id' => (int) $m['member_id'],
                'name' => (string) $m['name'],
                'paid_cents' => (int) $m['total_paid_cents'],
                'owed_cents' => (int) $m['total_owed_cents'],
                'net_balance_cents' => (int) $m['net_balance_cents'],
                'status' => (string) $m['status'],
            ];
        }, $members);

        // Sort by paid upfront descending
        $sortedPayers = $memberOutlay;
        usort($sortedPayers, fn($a, $b) => $b['paid_cents'] <=> $a['paid_cents']);
        $topFunder = $sortedPayers[0] ?? null;

        $activeDaysCount = max(1, count($dailyTrends));
        $avgDailySpend = (int) round($totalSpendCents / $activeDaysCount);

        return [
            'total_spending_cents' => $totalSpendCents,
            'total_transactions_count' => array_sum(array_column($dailyTrends, 'count')),
            'average_daily_spend_cents' => $avgDailySpend,
            'highest_category' => $categories[0] ?? null,
            'top_funder' => $topFunder,
            'categories' => $categories,
            'category_breakdown' => $categories,
            'daily_trends' => $dailyTrends,
            'member_outlay' => $memberOutlay,
        ];
    }

    /**
     * Compute 1-on-1 bilateral pairwise balances between all member pairs.
     *
     * @param int $groupId
     * @return array{
     *   group_id: int,
     *   pairs: array<array{
     *     from_member_id: int,
     *     from_member_name: string,
     *     to_member_id: int,
     *     to_member_name: string,
     *     amount_cents: int,
     *     status: string
     *   }>
     * }
     */
    public function getBilateralBalances(int $groupId): array
    {
        $members = $this->memberRepo->findByGroupId($groupId);
        $memberNames = [];
        foreach ($members as $m) {
            $memberNames[(int) $m['id']] = (string) $m['name'];
        }

        // Fetch all active expenses
        $expStmt = $this->pdo->prepare("
            SELECT `id`, `total_amount_cents`
            FROM `expenses`
            WHERE `group_id` = :group_id AND `is_deleted` = 0
        ");
        $expStmt->execute([':group_id' => $groupId]);
        $expenses = $expStmt->fetchAll();

        $pairwiseDebt = []; // [DebtorId][CreditorId] => cents

        if (!empty($expenses)) {
            $expIds = array_column($expenses, 'id');
            $inClause = implode(',', array_fill(0, count($expIds), '?'));

            $payerStmt = $this->pdo->prepare("
                SELECT `expense_id`, `member_id`, `amount_paid_cents`
                FROM `expense_payers`
                WHERE `expense_id` IN ({$inClause})
            ");
            $payerStmt->execute($expIds);
            $allPayers = $payerStmt->fetchAll();

            $payersByExp = [];
            foreach ($allPayers as $p) {
                $payersByExp[$p['expense_id']][] = [
                    'member_id' => (int) $p['member_id'],
                    'amount_paid_cents' => (int) $p['amount_paid_cents'],
                ];
            }

            $splitStmt = $this->pdo->prepare("
                SELECT `expense_id`, `member_id`, `amount_owed_cents`
                FROM `expense_splits`
                WHERE `expense_id` IN ({$inClause})
            ");
            $splitStmt->execute($expIds);
            $allSplits = $splitStmt->fetchAll();

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

        // Apply settlements
        $settleStmt = $this->pdo->prepare("
            SELECT `payer_member_id`, `payee_member_id`, `amount_cents`
            FROM `settlements`
            WHERE `group_id` = :group_id AND `is_deleted` = 0
        ");
        $settleStmt->execute([':group_id' => $groupId]);
        $settlements = $settleStmt->fetchAll();

        foreach ($settlements as $s) {
            $payerId = (int) $s['payer_member_id'];
            $payeeId = (int) $s['payee_member_id'];
            $amount = (int) $s['amount_cents'];

            // Settlement reduces debt of payer to payee
            $pairwiseDebt[$payerId][$payeeId] = ($pairwiseDebt[$payerId][$payeeId] ?? 0) - $amount;
        }

        // Build list of unique pairs
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

        return [
            'group_id' => $groupId,
            'pairs' => $pairs,
        ];
    }
}

