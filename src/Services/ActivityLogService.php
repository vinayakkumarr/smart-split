<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ActivityLogRepository;
use App\Repositories\MemberRepository;
use App\Utils\Money;
use DateTime;

/**
 * Domain Service for generating enriched, human-readable Activity and Audit Timelines.
 */
class ActivityLogService
{
    private ActivityLogRepository $logRepo;
    private MemberRepository $memberRepo;

    public function __construct(
        ?ActivityLogRepository $logRepo = null,
        ?MemberRepository $memberRepo = null
    ) {
        $this->logRepo = $logRepo ?? new ActivityLogRepository();
        $this->memberRepo = $memberRepo ?? new MemberRepository();
    }

    /**
     * Retrieve enriched activity timeline for a workspace.
     *
     * @param int $groupId
     * @param string $currencyCode
     * @param int $limit
     * @param int $offset
     * @param string|null $entityType
     * @return array<string, mixed>
     */
    public function getActivityTimeline(
        int $groupId,
        string $currencyCode = 'INR',
        int $limit = 50,
        int $offset = 0,
        ?string $entityType = null
    ): array {
        $rawLogs = $this->logRepo->findByGroupId($groupId, $limit, $offset, $entityType);
        $totalCount = $this->logRepo->countByGroupId($groupId, $entityType);

        if (empty($rawLogs)) {
            return [
                'activities' => [],
                'total_count' => $totalCount,
                'limit' => $limit,
                'offset' => $offset,
            ];
        }

        // Fetch all members in this group to resolve names for participant IDs
        $members = $this->memberRepo->findByGroupId($groupId, false);
        $memberMap = [];
        foreach ($members as $m) {
            $memberMap[(int) $m['id']] = (string) $m['name'];
        }

        $enriched = array_map(function (array $log) use ($memberMap, $currencyCode) {
            return $this->enrichLogEntry($log, $memberMap, $currencyCode);
        }, $rawLogs);

        return [
            'activities' => $enriched,
            'total_count' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Enrich a single raw log entry with iconography, narrative description, and formatted values.
     *
     * @param array<string, mixed> $log
     * @param array<int, string> $memberMap
     * @param string $currencyCode
     * @return array<string, mixed>
     */
    private function enrichLogEntry(array $log, array $memberMap, string $currencyCode): array
    {
        $action = (string) ($log['action'] ?? 'UNKNOWN');
        $actorId = isset($log['actor_member_id']) && $log['actor_member_id'] !== null ? (int) $log['actor_member_id'] : null;
        $actorName = !empty($log['actor_name']) ? (string) $log['actor_name'] : 'Someone';
        $payload = $log['payload'] ?? [];
        $createdAt = (string) ($log['created_at'] ?? date('Y-m-d H:i:s'));

        $icon = '📌';
        $badge = 'badge-secondary';
        $title = '';
        $narrative = '';
        $formattedAmount = null;

        switch ($action) {
            case 'EXPENSE_ADDED':
                $expTitle = (string) ($payload['title'] ?? 'Expense');
                $cents = (int) ($payload['amount_cents'] ?? 0);
                $splitType = ucfirst(strtolower((string) ($payload['split_type'] ?? 'Equal')));
                $formattedAmount = Money::format($cents, $currencyCode);

                $origStr = (!empty($payload['original_currency_code']) && !empty($payload['original_amount_cents']))
                    ? Money::format((int) $payload['original_amount_cents'], (string) $payload['original_currency_code']) . " ({$formattedAmount})"
                    : $formattedAmount;

                $icon = '➕';
                $badge = 'badge-credit';
                $title = 'Expense Added';
                $narrative = "{$actorName} added '{$expTitle}' of {$origStr} ({$splitType} split).";
                break;

            case 'EXPENSE_UPDATED':
                $expTitle = (string) ($payload['title'] ?? 'Expense');
                $cents = (int) ($payload['amount_cents'] ?? 0);
                $formattedAmount = Money::format($cents, $currencyCode);

                $origStr = (!empty($payload['original_currency_code']) && !empty($payload['original_amount_cents']))
                    ? Money::format((int) $payload['original_amount_cents'], (string) $payload['original_currency_code']) . " ({$formattedAmount})"
                    : $formattedAmount;

                $icon = '✏️';
                $badge = 'badge-secondary';
                $title = 'Expense Updated';
                $narrative = "{$actorName} updated '{$expTitle}' to {$origStr}.";
                break;

            case 'EXPENSE_DELETED':
                $expTitle = (string) ($payload['title'] ?? 'Expense');
                $cents = (int) ($payload['amount_cents'] ?? 0);
                $formattedAmount = $cents > 0 ? Money::format($cents, $currencyCode) : null;

                $icon = '🗑️';
                $badge = 'badge-debt';
                $title = 'Expense Deleted';
                $narrative = $formattedAmount
                    ? "{$actorName} deleted expense '{$expTitle}' ({$formattedAmount})."
                    : "{$actorName} deleted expense '{$expTitle}'.";
                break;

            case 'SETTLEMENT_RECORDED':
                $payerId = (int) ($payload['payer_id'] ?? 0);
                $payeeId = (int) ($payload['payee_id'] ?? 0);
                $payerName = $memberMap[$payerId] ?? "Member #{$payerId}";
                $payeeName = $memberMap[$payeeId] ?? "Member #{$payeeId}";
                $cents = (int) ($payload['amount_cents'] ?? 0);
                $formattedAmount = Money::format($cents, $currencyCode);
                $method = (string) ($payload['payment_method'] ?? 'OTHER');
                $status = (string) ($payload['status'] ?? 'CONFIRMED');
                $methodLabel = match ($method) {
                    'UPI' => 'via UPI',
                    'CASH' => 'in Cash',
                    'BANK_TRANSFER' => 'via Bank Transfer',
                    default => '',
                };

                $icon = $status === 'PENDING' ? '⏳' : '🤝';
                $badge = $status === 'PENDING' ? 'badge-warning' : 'badge-settled';
                $title = $status === 'PENDING' ? 'Payment Submitted (Pending)' : 'Settlement Recorded';
                if ($actorId !== null && $actorId !== $payerId && $actorId !== $payeeId) {
                    $narrative = "{$actorName} recorded payment of {$formattedAmount} from {$payerName} to {$payeeName} {$methodLabel}.";
                } else {
                    $narrative = "{$payerName} paid {$formattedAmount} to {$payeeName} {$methodLabel}.";
                }
                break;

            case 'SETTLEMENT_CONFIRMED':
                $payerId = (int) ($payload['payer_id'] ?? 0);
                $payeeId = (int) ($payload['payee_id'] ?? 0);
                $payerName = $memberMap[$payerId] ?? "Member #{$payerId}";
                $payeeName = $memberMap[$payeeId] ?? "Member #{$payeeId}";
                $cents = (int) ($payload['amount_cents'] ?? 0);
                $formattedAmount = Money::format($cents, $currencyCode);

                $icon = '✅';
                $badge = 'badge-settled';
                $title = 'Settlement Confirmed';
                $narrative = "{$actorName} confirmed receipt of {$formattedAmount} from {$payerName}.";
                break;

            case 'SETTLEMENT_DISPUTED':
                $payerId = (int) ($payload['payer_id'] ?? 0);
                $payeeId = (int) ($payload['payee_id'] ?? 0);
                $payerName = $memberMap[$payerId] ?? "Member #{$payerId}";
                $cents = (int) ($payload['amount_cents'] ?? 0);
                $formattedAmount = Money::format($cents, $currencyCode);
                $reason = !empty($payload['dispute_reason']) ? " (Reason: {$payload['dispute_reason']})" : '';

                $icon = '⚠️';
                $badge = 'badge-debt';
                $title = 'Settlement Disputed';
                $narrative = "{$actorName} disputed payment of {$formattedAmount} from {$payerName}{$reason}.";
                break;

            case 'SETTLEMENT_REVERSED':
                $payerId = (int) ($payload['payer_id'] ?? 0);
                $payeeId = (int) ($payload['payee_id'] ?? 0);
                $payerName = $memberMap[$payerId] ?? "Member #{$payerId}";
                $payeeName = $memberMap[$payeeId] ?? "Member #{$payeeId}";
                $cents = (int) ($payload['amount_cents'] ?? 0);
                $formattedAmount = Money::format($cents, $currencyCode);
                $reason = !empty($payload['reversal_reason']) ? " (Reason: {$payload['reversal_reason']})" : '';

                $icon = '↩️';
                $badge = 'badge-debt';
                $title = 'Settlement Reversed';
                $narrative = "{$actorName} reversed settlement of {$formattedAmount} between {$payerName} and {$payeeName}{$reason}.";
                break;

            case 'SETTLEMENT_DELETED':
                $payerId = (int) ($payload['payer_id'] ?? 0);
                $payeeId = (int) ($payload['payee_id'] ?? 0);
                $payerName = $memberMap[$payerId] ?? "Member #{$payerId}";
                $payeeName = $memberMap[$payeeId] ?? "Member #{$payeeId}";
                $cents = (int) ($payload['amount_cents'] ?? 0);
                $formattedAmount = Money::format($cents, $currencyCode);

                $icon = '↩️';
                $badge = 'badge-debt';
                $title = 'Settlement Undone';
                $narrative = "{$actorName} undone settlement of {$formattedAmount} between {$payerName} and {$payeeName}.";
                break;

            case 'MEMBER_ADDED':
                $memberName = (string) ($payload['name'] ?? 'New Member');
                $icon = '👤';
                $badge = 'badge-secondary';
                $title = 'Member Joined';
                $narrative = "{$actorName} added {$memberName} to the workspace.";
                break;

            case 'CATEGORY_CREATED':
                $catName = (string) ($payload['name'] ?? 'Category');
                $catIcon = (string) ($payload['icon'] ?? '🏷️');
                $icon = '🏷️';
                $badge = 'badge-secondary';
                $title = 'Category Created';
                $narrative = "{$actorName} created custom category {$catIcon} {$catName}.";
                break;

            case 'RECURRING_RULE_CREATED':
                $ruleTitle = (string) ($payload['title'] ?? 'Recurring Expense');
                $cents = (int) ($payload['amount_cents'] ?? 0);
                $freq = ucfirst(strtolower((string) ($payload['frequency'] ?? 'monthly')));
                $formattedAmount = Money::format($cents, $currencyCode);

                $icon = '🔄';
                $badge = 'badge-secondary';
                $title = 'Recurring Schedule Added';
                $narrative = "{$actorName} scheduled {$freq} recurring expense '{$ruleTitle}' of {$formattedAmount}.";
                break;

            case 'RECEIPT_ATTACHED':
                $fileName = (string) ($payload['file_name'] ?? 'Receipt image');
                $icon = '🧾';
                $badge = 'badge-settled';
                $title = 'Receipt Attached';
                $narrative = "{$actorName} attached receipt '{$fileName}'.";
                break;

            case 'RECEIPT_DELETED':
                $fileName = (string) ($payload['file_name'] ?? 'Receipt image');
                $icon = '🗑️';
                $badge = 'badge-debt';
                $title = 'Receipt Removed';
                $narrative = "{$actorName} removed receipt '{$fileName}'.";
                break;

            default:
                $icon = '📌';
                $badge = 'badge-secondary';
                $title = ucwords(str_replace('_', ' ', strtolower($action)));
                $narrative = "{$actorName} performed {$title}.";
                break;
        }

        return [
            'id' => (int) $log['id'],
            'group_id' => (int) $log['group_id'],
            'actor_member_id' => $log['actor_member_id'] !== null ? (int) $log['actor_member_id'] : null,
            'actor_name' => $actorName,
            'action' => $action,
            'entity_type' => (string) $log['entity_type'],
            'entity_id' => (int) $log['entity_id'],
            'title' => $title,
            'narrative' => $narrative,
            'icon' => $icon,
            'badge' => $badge,
            'amount_cents' => isset($payload['amount_cents']) ? (int) $payload['amount_cents'] : null,
            'formatted_amount' => $formattedAmount,
            'time_ago' => $this->formatTimeAgo($createdAt),
            'created_at' => $createdAt,
            'payload' => $payload,
        ];
    }

    /**
     * Compute human-readable relative time string.
     *
     * @param string $datetimeStr
     * @return string
     */
    private function formatTimeAgo(string $datetimeStr): string
    {
        $timestamp = strtotime($datetimeStr);
        if ($timestamp === false) {
            return $datetimeStr;
        }

        $now = time();
        $diff = max(0, $now - $timestamp);

        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            $mins = (int) floor($diff / 60);
            return "{$mins}m ago";
        }
        if ($diff < 86400) {
            $hours = (int) floor($diff / 3600);
            return "{$hours}h ago";
        }
        if ($diff < 172800) {
            return 'Yesterday';
        }
        if ($diff < 604800) {
            $days = (int) floor($diff / 86400);
            return "{$days}d ago";
        }

        return date('j M Y', $timestamp);
    }
}
