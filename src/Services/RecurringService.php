<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RecurringRepository;
use App\Repositories\MemberRepository;
use App\Repositories\ActivityLogRepository;
use App\Utils\Money;
use InvalidArgumentException;

/**
 * Domain Service for Scheduling, Rule Management, and Evaluating Due Recurring Expenses.
 */
class RecurringService
{
    private RecurringRepository $recurringRepo;
    private ExpenseService $expenseService;
    private MemberRepository $memberRepo;
    private ActivityLogRepository $activityLogRepo;

    public function __construct(
        ?RecurringRepository $recurringRepo = null,
        ?ExpenseService $expenseService = null,
        ?MemberRepository $memberRepo = null,
        ?ActivityLogRepository $activityLogRepo = null
    ) {
        $this->recurringRepo = $recurringRepo ?? new RecurringRepository();
        $this->expenseService = $expenseService ?? new ExpenseService();
        $this->memberRepo = $memberRepo ?? new MemberRepository();
        $this->activityLogRepo = $activityLogRepo ?? new ActivityLogRepository();
    }

    /**
     * Create a new recurring rule.
     *
     * @param int $groupId
     * @param array<string, mixed> $payload
     * @return int
     * @throws InvalidArgumentException
     */
    public function createRule(int $groupId, array $payload): int
    {
        if (empty($payload['title']) || !is_string($payload['title']) || trim($payload['title']) === '') {
            throw new InvalidArgumentException("Recurring rule title cannot be empty.", 422);
        }
        $title = trim(strip_tags((string) $payload['title']));

        $splitType = strtoupper((string) ($payload['split_type'] ?? 'EQUAL'));
        $validSplitTypes = ['EQUAL', 'EXACT', 'PERCENTAGE', 'SHARES', 'ITEMIZED'];
        if (!in_array($splitType, $validSplitTypes, true)) {
            throw new InvalidArgumentException("Invalid split type '{$splitType}'.", 422);
        }

        $totalCents = 0;
        if (isset($payload['total_amount_cents'])) {
            $totalCents = (int) $payload['total_amount_cents'];
        } elseif (isset($payload['amount_cents'])) {
            $totalCents = (int) $payload['amount_cents'];
        } elseif (isset($payload['amount'])) {
            $totalCents = Money::toCents($payload['amount']);
        } elseif ($splitType === 'ITEMIZED' && !empty($payload['items'])) {
            $itemSubtotal = array_sum(array_map(function ($i) {
                return (int) ($i['amount_cents'] ?? Money::toCents($i['amount'] ?? 0));
            }, $payload['items']));
            $totalCents = $itemSubtotal + (int) ($payload['tax_cents'] ?? 0) + (int) ($payload['tip_cents'] ?? 0) - (int) ($payload['discount_cents'] ?? 0);
        }

        if ($totalCents <= 0) {
            throw new InvalidArgumentException("Recurring expense amount must be greater than zero.", 422);
        }

        $frequency = strtoupper((string) ($payload['frequency'] ?? 'MONTHLY'));
        $validFrequencies = ['WEEKLY', 'BIWEEKLY', 'MONTHLY', 'YEARLY'];
        if (!in_array($frequency, $validFrequencies, true)) {
            throw new InvalidArgumentException("Invalid frequency '{$frequency}'. Supported: WEEKLY, BIWEEKLY, MONTHLY, YEARLY.", 422);
        }

        $nextRunDate = isset($payload['next_run_date']) && !empty($payload['next_run_date'])
            ? (string) $payload['next_run_date']
            : date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextRunDate) || !checkdate((int) substr($nextRunDate, 5, 2), (int) substr($nextRunDate, 8, 2), (int) substr($nextRunDate, 0, 4))) {
            throw new InvalidArgumentException("Invalid next run date '{$nextRunDate}'. Must be a valid YYYY-MM-DD date.", 422);
        }

        $endDate = isset($payload['end_date']) && !empty($payload['end_date'])
            ? (string) $payload['end_date']
            : null;

        if ($endDate !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) || !checkdate((int) substr($endDate, 5, 2), (int) substr($endDate, 8, 2), (int) substr($endDate, 0, 4))) {
                throw new InvalidArgumentException("Invalid end date '{$endDate}'. Must be a valid YYYY-MM-DD date.", 422);
            }
            if ($endDate < $nextRunDate) {
                throw new InvalidArgumentException("End date cannot be earlier than start/next run date.", 422);
            }
        }

        $categoryId = isset($payload['category_id']) && !empty($payload['category_id'])
            ? (int) $payload['category_id']
            : null;

        $groupMembers = $this->memberRepo->findByGroupId($groupId);
        $validMemberIds = array_map('intval', array_column($groupMembers, 'id'));
        if (empty($validMemberIds)) {
            throw new InvalidArgumentException("Group has no members.", 422);
        }

        $createdByMemberId = isset($payload['created_by_member_id'])
            ? (int) $payload['created_by_member_id']
            : (int) $validMemberIds[0];

        // Store entire template payload for automated expense generation
        $expensePayload = [
            'title' => $title,
            'total_amount_cents' => $totalCents,
            'split_type' => $splitType,
            'category_id' => $categoryId,
            'payers' => $payload['payers'] ?? null,
            'paid_by_member_id' => $payload['paid_by_member_id'] ?? $createdByMemberId,
            'splits' => $payload['splits'] ?? null,
            'split_members' => $payload['split_members'] ?? null,
            'items' => $payload['items'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'tax_cents' => (int) ($payload['tax_cents'] ?? 0),
            'tip_cents' => (int) ($payload['tip_cents'] ?? 0),
            'discount_cents' => (int) ($payload['discount_cents'] ?? 0),
            '_anchor_date' => $nextRunDate,
        ];

        $ruleId = $this->recurringRepo->createRule(
            $groupId,
            $title,
            $totalCents,
            $splitType,
            $categoryId,
            $frequency,
            $nextRunDate,
            $endDate,
            $expensePayload,
            $createdByMemberId
        );

        $this->activityLogRepo->record(
            $groupId,
            $createdByMemberId,
            'RECURRING_RULE_CREATED',
            'recurring',
            $ruleId,
            [
                'title' => $title,
                'amount_cents' => $totalCents,
                'frequency' => $frequency,
            ]
        );

        return $ruleId;
    }

    /**
     * Retrieve all recurring rules for a group.
     *
     * @param int $groupId
     * @param bool $onlyActive
     * @return array<array<string, mixed>>
     */
    public function getRules(int $groupId, bool $onlyActive = false): array
    {
        return $this->recurringRepo->findByGroupId($groupId, $onlyActive);
    }

    /**
     * Delete a recurring rule.
     *
     * @param int $groupId
     * @param int $ruleId
     * @return bool
     */
    public function deleteRule(int $groupId, int $ruleId): bool
    {
        return $this->recurringRepo->deleteRule($ruleId, $groupId);
    }

    /**
     * Evaluate and materialize all due recurring rules for a group.
     *
     * @param int $groupId
     * @param string|null $currentDate YYYY-MM-DD (defaults to today)
     * @return array{evaluated_count: int, created_expenses_count: int, expenses: array<int>}
     */
    public function evaluateDueRules(int $groupId, ?string $currentDate = null): array
    {
        $today = $currentDate ?? date('Y-m-d');
        $dueRules = $this->recurringRepo->findDueRules($groupId, $today);

        $createdExpenseIds = [];

        foreach ($dueRules as $rule) {
            $ruleId = (int) $rule['id'];
            $nextRunDate = $rule['next_run_date'];
            $frequency = $rule['frequency'];
            $endDate = $rule['end_date'];
            $payload = $rule['payload'];
            $anchorDate = (string) ($payload['_anchor_date'] ?? $payload['next_run_date'] ?? $nextRunDate);

            // Evaluate all occurrences up to today (handles missed runs if workspace was idle)
            while ($nextRunDate <= $today) {
                // Prepare expense payload
                $expPayload = $payload;
                $expPayload['expense_date'] = $nextRunDate;
                $expPayload['notes'] = ($expPayload['notes'] ? $expPayload['notes'] . ' ' : '') . "[Recurring: {$frequency}]";

                try {
                    $recurringIdempKey = "recurring_rule_{$ruleId}_{$nextRunDate}";
                    $expenseId = $this->expenseService->createExpense($groupId, $expPayload, $recurringIdempKey);
                    if (!in_array($expenseId, $createdExpenseIds, true)) {
                        $createdExpenseIds[] = $expenseId;
                    }
                } catch (\Throwable $e) {
                    // Log or advance if payload had obsolete member, but don't break loop
                    break;
                }

                // Compute next interval date
                $nextRunDate = $this->computeNextDate($nextRunDate, $frequency, $anchorDate);

                $isExpired = false;
                if ($endDate !== null && $nextRunDate > $endDate) {
                    $isExpired = true;
                }

                $this->recurringRepo->advanceNextRunDate($ruleId, $nextRunDate, $isExpired);

                if ($isExpired) {
                    break;
                }
            }
        }

        return [
            'evaluated_count' => count($dueRules),
            'created_expenses_count' => count($createdExpenseIds),
            'expenses' => $createdExpenseIds,
        ];
    }

    /**
     * Compute next date string given current date, frequency, and optional anchor date.
     * Preserves target day across varying month lengths and leap years without permanent schedule drift.
     *
     * @param string $date YYYY-MM-DD
     * @param string $frequency
     * @param string|null $anchorDate YYYY-MM-DD Original scheduled anchor date
     * @return string YYYY-MM-DD
     */
    public function computeNextDate(string $date, string $frequency, ?string $anchorDate = null): string
    {
        $anchor = $anchorDate ?? $date;
        $anchorParts = explode('-', $anchor);
        $anchorMonth = (int) ($anchorParts[1] ?? 1);
        $anchorDay = (int) ($anchorParts[2] ?? 1);

        $currParts = explode('-', $date);
        $currYear = (int) ($currParts[0] ?? 2026);
        $currMonth = (int) ($currParts[1] ?? 1);

        return match (strtoupper($frequency)) {
            'WEEKLY' => (new \DateTimeImmutable($date))->modify('+7 days')->format('Y-m-d'),
            'BIWEEKLY' => (new \DateTimeImmutable($date))->modify('+14 days')->format('Y-m-d'),
            'MONTHLY' => (function () use ($currYear, $currMonth, $anchorDay) {
                $nextMonth = $currMonth + 1;
                $nextYear = $currYear;
                if ($nextMonth > 12) {
                    $nextMonth = 1;
                    $nextYear++;
                }
                $daysInNextMonth = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $nextYear, $nextMonth)))->format('t');
                $targetDay = min($anchorDay, $daysInNextMonth);
                return sprintf('%04d-%02d-%02d', $nextYear, $nextMonth, $targetDay);
            })(),
            'YEARLY' => (function () use ($currYear, $anchorMonth, $anchorDay) {
                $nextYear = $currYear + 1;
                $daysInMonth = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $nextYear, $anchorMonth)))->format('t');
                $targetDay = min($anchorDay, $daysInMonth);
                return sprintf('%04d-%02d-%02d', $nextYear, $anchorMonth, $targetDay);
            })(),
            default => (new \DateTimeImmutable($date))->modify('+1 month')->format('Y-m-d'),
        };
    }
}
