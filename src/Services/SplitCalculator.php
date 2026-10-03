<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\Money;
use InvalidArgumentException;

/**
 * Deterministic Financial Split Calculator with Penny-Rounding Reconciliation.
 */
class SplitCalculator
{
    /**
     * Calculate an equal split among members, deterministically allocating indivisible remainder pennies.
     * E.g., 1000 cents / 3 members -> [MemberA => 334, MemberB => 333, MemberC => 333]
     *
     * @param int $totalCents Total amount in integer cents.
     * @param array<int> $memberIds Array of participating member IDs.
     * @return array<int, int> Map of member_id => amount_owed_cents.
     * @throws InvalidArgumentException
     */
    public static function calculateEqual(int $totalCents, array $memberIds): array
    {
        if ($totalCents <= 0) {
            throw new InvalidArgumentException("Total amount must be greater than 0 cents.", 422);
        }

        $memberCount = count($memberIds);
        if ($memberCount === 0) {
            throw new InvalidArgumentException("At least one member must be selected for an equal split.", 422);
        }

        // Sort member IDs deterministically ascending to ensure non-arbitrary penny assignment
        $sortedMemberIds = array_values($memberIds);
        sort($sortedMemberIds, SORT_NUMERIC);

        $baseCents = (int) floor($totalCents / $memberCount);
        $remainderPennies = $totalCents % $memberCount;

        $splits = [];
        foreach ($sortedMemberIds as $index => $memberId) {
            // Allocate 1 additional penny to the first $remainderPennies members
            $extraPenny = ($index < $remainderPennies) ? 1 : 0;
            $splits[(int) $memberId] = $baseCents + $extraPenny;
        }

        self::assertZeroSumInvariant($totalCents, $splits);
        return $splits;
    }

    /**
     * Validate an exact split where amounts for each member are explicitly provided.
     *
     * @param int $totalCents Total amount in integer cents.
     * @param array<int, int|float|string> $exactMap Map of member_id => owed amount.
     * @return array<int, int> Map of member_id => amount_owed_cents.
     * @throws InvalidArgumentException
     */
    public static function calculateExact(int $totalCents, array $exactMap): array
    {
        if ($totalCents <= 0) {
            throw new InvalidArgumentException("Total amount must be greater than 0 cents.", 422);
        }

        if (empty($exactMap)) {
            throw new InvalidArgumentException("Exact split map cannot be empty.", 422);
        }

        $splits = [];
        $sum = 0;

        foreach ($exactMap as $memberId => $amount) {
            $cents = is_int($amount) ? $amount : Money::toCents($amount);
            if ($cents < 0) {
                throw new InvalidArgumentException("Exact share for member {$memberId} cannot be negative.", 422);
            }
            $splits[(int) $memberId] = $cents;
            $sum += $cents;
        }

        if ($sum !== $totalCents) {
            $diff = $totalCents - $sum;
            $diffFormatted = Money::format(abs($diff));
            $direction = $diff > 0 ? 'under-allocated' : 'over-allocated';
            throw new InvalidArgumentException(
                "Exact split amounts total " . Money::format($sum) . ", which is {$direction} by {$diffFormatted} compared to the total bill of " . Money::format($totalCents) . ".",
                422
            );
        }

        self::assertZeroSumInvariant($totalCents, $splits);
        return $splits;
    }

    /**
     * Calculate a percentage-based split, distributing residual pennies according to largest remainder.
     *
     * @param int $totalCents Total amount in integer cents.
     * @param array<int, float|int|string> $percentageMap Map of member_id => percentage (e.g. 33.33).
     * @return array<int, int> Map of member_id => amount_owed_cents.
     * @throws InvalidArgumentException
     */
    public static function calculatePercentage(int $totalCents, array $percentageMap): array
    {
        if ($totalCents <= 0) {
            throw new InvalidArgumentException("Total amount must be greater than 0 cents.", 422);
        }

        if (empty($percentageMap)) {
            throw new InvalidArgumentException("Percentage split map cannot be empty.", 422);
        }

        // Validate percentage sum (allow small float margin: 99.9% to 100.1%)
        $totalPercentage = 0.0;
        foreach ($percentageMap as $pct) {
            $totalPercentage += (float) $pct;
        }

        if (abs($totalPercentage - 100.0) > 0.1) {
            throw new InvalidArgumentException(
                sprintf("Percentages must sum to 100%% (current sum: %.2f%%).", $totalPercentage),
                422
            );
        }

        $allocations = [];
        $allocatedSum = 0;

        foreach ($percentageMap as $memberId => $pct) {
            $exactFloatCents = ($totalCents * (float) $pct) / 100.0;
            $baseCents = (int) floor($exactFloatCents);
            $fraction = $exactFloatCents - $baseCents;

            $allocations[(int) $memberId] = [
                'base' => $baseCents,
                'fraction' => $fraction,
                'member_id' => (int) $memberId,
            ];
            $allocatedSum += $baseCents;
        }

        // Distribute remainder pennies by largest fractional remainder
        $shortfall = $totalCents - $allocatedSum;

        // Sort by fractional remainder descending, then by member_id ascending
        uasort($allocations, function (array $a, array $b): int {
            if ($b['fraction'] > $a['fraction']) {
                return 1;
            } elseif ($b['fraction'] < $a['fraction']) {
                return -1;
            }
            return $a['member_id'] <=> $b['member_id'];
        });

        $splits = [];
        $penniesGiven = 0;

        foreach ($allocations as $memberId => $data) {
            $extraPenny = ($penniesGiven < $shortfall) ? 1 : 0;
            $splits[$memberId] = $data['base'] + $extraPenny;
            $penniesGiven += $extraPenny;
        }

        self::assertZeroSumInvariant($totalCents, $splits);
        return $splits;
    }

    /**
     * Calculate a shares/ratio split (e.g. 2 shares vs 1 share).
     *
     * @param int $totalCents Total amount in integer cents.
     * @param array<int, float|int> $sharesMap Map of member_id => share_units (e.g. 2, 1, 1).
     * @return array<int, int> Map of member_id => amount_owed_cents.
     * @throws InvalidArgumentException
     */
    public static function calculateShares(int $totalCents, array $sharesMap): array
    {
        if ($totalCents <= 0) {
            throw new InvalidArgumentException("Total amount must be greater than 0 cents.", 422);
        }

        if (empty($sharesMap)) {
            throw new InvalidArgumentException("Shares split map cannot be empty.", 422);
        }

        $totalShares = 0.0;
        foreach ($sharesMap as $share) {
            $val = (float) $share;
            if ($val <= 0) {
                throw new InvalidArgumentException("Share units must be greater than 0.", 422);
            }
            $totalShares += $val;
        }

        $allocations = [];
        $allocatedSum = 0;

        foreach ($sharesMap as $memberId => $share) {
            $exactFloatCents = ($totalCents * (float) $share) / $totalShares;
            $baseCents = (int) floor($exactFloatCents);
            $fraction = $exactFloatCents - $baseCents;

            $allocations[(int) $memberId] = [
                'base' => $baseCents,
                'fraction' => $fraction,
                'member_id' => (int) $memberId,
            ];
            $allocatedSum += $baseCents;
        }

        $shortfall = $totalCents - $allocatedSum;

        uasort($allocations, function (array $a, array $b): int {
            if ($b['fraction'] > $a['fraction']) {
                return 1;
            } elseif ($b['fraction'] < $a['fraction']) {
                return -1;
            }
            return $a['member_id'] <=> $b['member_id'];
        });

        $splits = [];
        $penniesGiven = 0;

        foreach ($allocations as $memberId => $data) {
            $extraPenny = ($penniesGiven < $shortfall) ? 1 : 0;
            $splits[$memberId] = $data['base'] + $extraPenny;
            $penniesGiven += $extraPenny;
        }

        self::assertZeroSumInvariant($totalCents, $splits);
        return $splits;
    }

    /**
     * Calculate an itemized receipt split where individual line items are assigned to specific members,
     * with proportional allocation of shared taxes, tips, and discounts using the Largest Fractional Remainder method.
     *
     * @param array<array{name: string, amount_cents: int, member_ids: array<int>}> $items
     * @param int $taxCents
     * @param int $tipCents
     * @param int $discountCents
     * @return array{total_cents: int, subtotal_cents: int, tax_cents: int, tip_cents: int, discount_cents: int, splits: array<int, int>, item_allocations: array<int, array<int, int>>}
     * @throws InvalidArgumentException
     */
    public static function calculateItemized(
        array $items,
        int $taxCents = 0,
        int $tipCents = 0,
        int $discountCents = 0
    ): array {
        if (empty($items)) {
            throw new InvalidArgumentException("Itemized split must contain at least one line item.", 422);
        }

        $subtotalCents = 0;
        $memberSubtotals = [];
        $itemAllocations = [];

        foreach ($items as $idx => $item) {
            $itemAmount = (int) ($item['amount_cents'] ?? 0);
            if ($itemAmount <= 0) {
                $name = $item['name'] ?? "Item #" . ($idx + 1);
                throw new InvalidArgumentException("Line item '{$name}' must have an amount greater than 0.", 422);
            }

            $memberIds = array_values(array_unique(array_map('intval', $item['member_ids'] ?? [])));
            if (empty($memberIds)) {
                $name = $item['name'] ?? "Item #" . ($idx + 1);
                throw new InvalidArgumentException("Line item '{$name}' must be assigned to at least one member.", 422);
            }

            $itemSplit = self::calculateEqual($itemAmount, $memberIds);
            $itemAllocations[$idx] = $itemSplit;
            $subtotalCents += $itemAmount;

            foreach ($itemSplit as $mId => $cents) {
                $memberSubtotals[$mId] = ($memberSubtotals[$mId] ?? 0) + $cents;
            }
        }

        $netSurchargeCents = $taxCents + $tipCents - $discountCents;
        $totalCents = $subtotalCents + $netSurchargeCents;

        if ($totalCents <= 0) {
            throw new InvalidArgumentException("Total itemized bill after discount must be greater than zero.", 422);
        }

        $finalSplits = [];

        if ($netSurchargeCents === 0 || $subtotalCents === 0) {
            $finalSplits = $memberSubtotals;
        } else {
            // Distribute net surcharges proportionally according to member subtotal
            $allocations = [];
            $allocatedSurchargeSum = 0;

            foreach ($memberSubtotals as $memberId => $subtotal) {
                $exactFloat = ($netSurchargeCents * $subtotal) / $subtotalCents;
                $base = (int) floor($exactFloat);
                $fraction = $exactFloat - $base;

                $allocations[$memberId] = [
                    'base' => $base,
                    'fraction' => $fraction,
                    'member_id' => $memberId,
                ];
                $allocatedSurchargeSum += $base;
            }

            $shortfall = $netSurchargeCents - $allocatedSurchargeSum;

            // Sort by fraction descending for positive surcharge, ascending for negative
            uasort($allocations, function (array $a, array $b) use ($shortfall): int {
                if ($shortfall >= 0) {
                    if ($b['fraction'] > $a['fraction']) return 1;
                    if ($b['fraction'] < $a['fraction']) return -1;
                } else {
                    if ($a['fraction'] > $b['fraction']) return 1;
                    if ($a['fraction'] < $b['fraction']) return -1;
                }
                return $a['member_id'] <=> $b['member_id'];
            });

            $penniesGiven = 0;
            $step = $shortfall >= 0 ? 1 : -1;
            $stepsNeeded = abs($shortfall);

            foreach ($allocations as $memberId => $data) {
                $extra = ($penniesGiven < $stepsNeeded) ? $step : 0;
                $memberSurcharge = $data['base'] + $extra;
                $finalSplits[$memberId] = $memberSubtotals[$memberId] + $memberSurcharge;
                if ($extra !== 0) $penniesGiven++;
            }
        }

        self::assertZeroSumInvariant($totalCents, $finalSplits);

        return [
            'total_cents' => $totalCents,
            'subtotal_cents' => $subtotalCents,
            'tax_cents' => $taxCents,
            'tip_cents' => $tipCents,
            'discount_cents' => $discountCents,
            'splits' => $finalSplits,
            'item_allocations' => $itemAllocations,
        ];
    }

    /**
     * Calculate an adjusted split where members have fixed positive/negative offsets from an equal base.
     *
     * @param int $totalCents Total amount in integer cents.
     * @param array<int> $memberIds Participating members.
     * @param array<int, int> $adjustmentsMap Map of member_id => adjustment in cents (+/-).
     * @return array<int, int> Map of member_id => amount_owed_cents.
     * @throws InvalidArgumentException
     */
    public static function calculateAdjustments(
        int $totalCents,
        array $memberIds,
        array $adjustmentsMap = []
    ): array {
        if ($totalCents <= 0) {
            throw new InvalidArgumentException("Total amount must be greater than 0 cents.", 422);
        }

        $memberCount = count($memberIds);
        if ($memberCount === 0) {
            throw new InvalidArgumentException("At least one member must be selected.", 422);
        }

        $totalAdjustments = array_sum($adjustmentsMap);
        $remainingToSplitEqually = $totalCents - $totalAdjustments;

        if ($remainingToSplitEqually < 0) {
            throw new InvalidArgumentException("Adjustments exceed total bill amount.", 422);
        }

        $baseSplits = self::calculateEqual($remainingToSplitEqually, $memberIds);
        $finalSplits = [];

        foreach ($memberIds as $mId) {
            $adj = $adjustmentsMap[$mId] ?? 0;
            $owed = $baseSplits[$mId] + $adj;
            if ($owed < 0) {
                throw new InvalidArgumentException("Adjustment creates negative liability for member ID {$mId}.", 422);
            }
            $finalSplits[$mId] = $owed;
        }

        self::assertZeroSumInvariant($totalCents, $finalSplits);
        return $finalSplits;
    }

    /**
     * Strict invariant assertion: Sum of all owed splits must precisely equal total bill in cents.
     *
     * @param int $totalCents
     * @param array<int, int> $splits
     * @return void
     * @throws InvalidArgumentException
     */
    private static function assertZeroSumInvariant(int $totalCents, array $splits): void
    {
        $sum = array_sum($splits);
        if ($sum !== $totalCents) {
            throw new InvalidArgumentException(
                "Critical mathematical integrity error: Split sum ({$sum} cents) does not match total expense ({$totalCents} cents).",
                422
            );
        }
    }
}
