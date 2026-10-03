<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\Money;
use InvalidArgumentException;
use RuntimeException;

/**
 * Algorithmic Debt Minimization & Graph Simplification Engine.
 */
class SettlementEngine
{
    /**
     * Compute the mathematically optimal minimal settlement transactions using the Greedy Min-Cash-Flow Algorithm.
     *
     * @param array<array{member_id: int, name: string, net_balance_cents: int}> $memberBalances
     * @param string $currencyCode E.g. 'INR'
     * @return array{
     *   total_transactions: int,
     *   total_settlement_volume_cents: int,
     *   transactions: array<array{
     *     from_member_id: int,
     *     from_name: string,
     *     to_member_id: int,
     *     to_name: string,
     *     amount_cents: int,
     *     amount_formatted: string
     *   }>
     * }
     * @throws RuntimeException If zero-sum integrity is violated.
     */
    public static function simplifyDebts(array $memberBalances, string $currencyCode = 'INR'): array
    {
        $debtors = [];
        $creditors = [];
        $netSum = 0;

        foreach ($memberBalances as $m) {
            $net = (int) ($m['net_balance_cents'] ?? 0);
            $netSum += $net;

            if ($net < 0) {
                $debtors[] = [
                    'member_id' => (int) $m['member_id'],
                    'name' => (string) $m['name'],
                    'net' => $net, // Negative value
                ];
            } elseif ($net > 0) {
                $creditors[] = [
                    'member_id' => (int) $m['member_id'],
                    'name' => (string) $m['name'],
                    'upi_id' => isset($m['upi_id']) && $m['upi_id'] !== null ? (string) $m['upi_id'] : null,
                    'net' => $net, // Positive value
                ];
            }
        }

        if ($netSum !== 0) {
            throw new RuntimeException(
                "Cannot compute debt simplification: Member balances do not sum to zero (net sum: {$netSum} paise)."
            );
        }

        if (empty($debtors) || empty($creditors)) {
            return [
                'total_transactions' => 0,
                'total_settlement_volume_cents' => 0,
                'transactions' => [],
            ];
        }

        // Sort Debtors by absolute debt amount descending (e.g. -3500 before -500)
        usort($debtors, function (array $a, array $b): int {
            return abs($b['net']) <=> abs($a['net']);
        });

        // Sort Creditors by credit amount descending (e.g. +4000 before +1000)
        usort($creditors, function (array $a, array $b): int {
            return $b['net'] <=> $a['net'];
        });

        $transactions = [];
        $totalVolumeCents = 0;

        // Greedy Matching Loop
        while (!empty($debtors) && !empty($creditors)) {
            // Get largest debtor and largest creditor
            $debtor = array_shift($debtors);
            $creditor = array_shift($creditors);

            $debtorAmount = abs($debtor['net']);
            $creditorAmount = $creditor['net'];

            // Transfer amount is minimum of the two
            $transferAmount = min($debtorAmount, $creditorAmount);

            $transactions[] = [
                'from_member_id' => $debtor['member_id'],
                'from_name' => $debtor['name'],
                'to_member_id' => $creditor['member_id'],
                'to_name' => $creditor['name'],
                'to_upi_id' => $creditor['upi_id'] ?? null,
                'amount_cents' => $transferAmount,
                'amount_formatted' => Money::format($transferAmount, $currencyCode),
            ];

            $totalVolumeCents += $transferAmount;

            // Adjust remaining balances
            $remainingDebt = $debtorAmount - $transferAmount;
            $remainingCredit = $creditorAmount - $transferAmount;

            if ($remainingDebt > 0) {
                $debtor['net'] = -$remainingDebt;
                // Re-insert debtor and maintain sorted order
                $debtors[] = $debtor;
                usort($debtors, function (array $a, array $b): int {
                    return abs($b['net']) <=> abs($a['net']);
                });
            }

            if ($remainingCredit > 0) {
                $creditor['net'] = $remainingCredit;
                // Re-insert creditor and maintain sorted order
                $creditors[] = $creditor;
                usort($creditors, function (array $a, array $b): int {
                    return $b['net'] <=> $a['net'];
                });
            }
        }

        return [
            'total_transactions' => count($transactions),
            'total_settlement_volume_cents' => $totalVolumeCents,
            'transactions' => $transactions,
        ];
    }
}
