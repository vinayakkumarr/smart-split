<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Data Access Repository for Recurring Expense Rules.
 */
class RecurringRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Create a new recurring expense rule.
     *
     * @param int $groupId
     * @param string $title
     * @param int $totalAmountCents
     * @param string $splitType
     * @param int|null $categoryId
     * @param string $frequency E.g. 'WEEKLY', 'BIWEEKLY', 'MONTHLY', 'YEARLY'
     * @param string $nextRunDate YYYY-MM-DD
     * @param string|null $endDate YYYY-MM-DD or null
     * @param array<string, mixed> $payload Complete expense payload for evaluation
     * @param int $createdByMemberId
     * @return int Created Rule ID
     */
    public function createRule(
        int $groupId,
        string $title,
        int $totalAmountCents,
        string $splitType,
        ?int $categoryId,
        string $frequency,
        string $nextRunDate,
        ?string $endDate,
        array $payload,
        int $createdByMemberId
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO `recurring_rules` (
                `group_id`, `title`, `total_amount_cents`, `split_type`, `category_id`,
                `frequency`, `next_run_date`, `end_date`, `is_active`, `payload_json`,
                `created_by_member_id`
            ) VALUES (
                :group_id, :title, :total_amount_cents, :split_type, :category_id,
                :frequency, :next_run_date, :end_date, 1, :payload_json,
                :created_by_member_id
            )
        ");

        $stmt->execute([
            ':group_id' => $groupId,
            ':title' => $title,
            ':total_amount_cents' => $totalAmountCents,
            ':split_type' => strtoupper($splitType),
            ':category_id' => $categoryId,
            ':frequency' => strtoupper($frequency),
            ':next_run_date' => $nextRunDate,
            ':end_date' => $endDate,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':created_by_member_id' => $createdByMemberId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Fetch all recurring rules for a group.
     *
     * @param int $groupId
     * @param bool $onlyActive
     * @return array<array<string, mixed>>
     */
    public function findByGroupId(int $groupId, bool $onlyActive = false): array
    {
        $sql = "
            SELECT r.`id`, r.`group_id`, r.`title`, r.`total_amount_cents`, r.`split_type`,
                   r.`category_id`, c.`slug` AS `category_slug`, c.`name` AS `category_name`, c.`icon` AS `category_icon`,
                   r.`frequency`, r.`next_run_date`, r.`end_date`, r.`is_active`,
                   r.`payload_json`, r.`created_by_member_id`, m.`name` AS `creator_name`,
                   r.`created_at`, r.`updated_at`
            FROM `recurring_rules` r
            JOIN `members` m ON r.`created_by_member_id` = m.`id`
            LEFT JOIN `categories` c ON r.`category_id` = c.`id`
            WHERE r.`group_id` = :group_id
        ";

        if ($onlyActive) {
            $sql .= " AND r.`is_active` = 1";
        }

        $sql .= " ORDER BY r.`is_active` DESC, r.`next_run_date` ASC, r.`id` DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':group_id' => $groupId]);
        $rows = $stmt->fetchAll();

        return array_map(function (array $r) {
            return [
                'id' => (int) $r['id'],
                'group_id' => (int) $r['group_id'],
                'title' => (string) $r['title'],
                'total_amount_cents' => (int) $r['total_amount_cents'],
                'split_type' => (string) $r['split_type'],
                'category' => $r['category_id'] ? [
                    'id' => (int) $r['category_id'],
                    'slug' => (string) $r['category_slug'],
                    'name' => (string) $r['category_name'],
                    'icon' => (string) $r['category_icon'],
                ] : null,
                'frequency' => (string) $r['frequency'],
                'next_run_date' => (string) $r['next_run_date'],
                'end_date' => $r['end_date'] ? (string) $r['end_date'] : null,
                'is_active' => (bool) $r['is_active'],
                'payload' => json_decode((string) $r['payload_json'], true) ?: [],
                'created_by' => [
                    'id' => (int) $r['created_by_member_id'],
                    'name' => (string) $r['creator_name'],
                ],
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'],
            ];
        }, $rows);
    }

    /**
     * Find single rule by ID.
     *
     * @param int $ruleId
     * @return array<string, mixed>|null
     */
    public function findById(int $ruleId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT r.`id`, r.`group_id`, r.`title`, r.`total_amount_cents`, r.`split_type`,
                   r.`category_id`, c.`slug` AS `category_slug`, c.`name` AS `category_name`, c.`icon` AS `category_icon`,
                   r.`frequency`, r.`next_run_date`, r.`end_date`, r.`is_active`,
                   r.`payload_json`, r.`created_by_member_id`, m.`name` AS `creator_name`,
                   r.`created_at`, r.`updated_at`
            FROM `recurring_rules` r
            JOIN `members` m ON r.`created_by_member_id` = m.`id`
            LEFT JOIN `categories` c ON r.`category_id` = c.`id`
            WHERE r.`id` = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $ruleId]);
        $r = $stmt->fetch();

        if (!$r) {
            return null;
        }

        return [
            'id' => (int) $r['id'],
            'group_id' => (int) $r['group_id'],
            'title' => (string) $r['title'],
            'total_amount_cents' => (int) $r['total_amount_cents'],
            'split_type' => (string) $r['split_type'],
            'category' => $r['category_id'] ? [
                'id' => (int) $r['category_id'],
                'slug' => (string) $r['category_slug'],
                'name' => (string) $r['category_name'],
                'icon' => (string) $r['category_icon'],
            ] : null,
            'frequency' => (string) $r['frequency'],
            'next_run_date' => (string) $r['next_run_date'],
            'end_date' => $r['end_date'] ? (string) $r['end_date'] : null,
            'is_active' => (bool) $r['is_active'],
            'payload' => json_decode((string) $r['payload_json'], true) ?: [],
            'created_by' => [
                'id' => (int) $r['created_by_member_id'],
                'name' => (string) $r['creator_name'],
            ],
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ];
    }

    /**
     * Find active rules that are due for execution (next_run_date <= $currentDate).
     *
     * @param int $groupId
     * @param string $currentDate YYYY-MM-DD
     * @return array<array<string, mixed>>
     */
    public function findDueRules(int $groupId, string $currentDate): array
    {
        $stmt = $this->pdo->prepare("
            SELECT `id`, `group_id`, `title`, `total_amount_cents`, `split_type`, `category_id`,
                   `frequency`, `next_run_date`, `end_date`, `is_active`, `payload_json`,
                   `created_by_member_id`
            FROM `recurring_rules`
            WHERE `group_id` = :group_id
              AND `is_active` = 1
              AND `next_run_date` <= :current_date
            ORDER BY `next_run_date` ASC, `id` ASC
        ");
        $stmt->execute([
            ':group_id' => $groupId,
            ':current_date' => $currentDate,
        ]);
        $rows = $stmt->fetchAll();

        return array_map(function (array $r) {
            return [
                'id' => (int) $r['id'],
                'group_id' => (int) $r['group_id'],
                'title' => (string) $r['title'],
                'total_amount_cents' => (int) $r['total_amount_cents'],
                'split_type' => (string) $r['split_type'],
                'category_id' => $r['category_id'] ? (int) $r['category_id'] : null,
                'frequency' => (string) $r['frequency'],
                'next_run_date' => (string) $r['next_run_date'],
                'end_date' => $r['end_date'] ? (string) $r['end_date'] : null,
                'is_active' => (bool) $r['is_active'],
                'payload' => json_decode((string) $r['payload_json'], true) ?: [],
                'created_by_member_id' => (int) $r['created_by_member_id'],
            ];
        }, $rows);
    }

    /**
     * Advance the next run date of a rule, and optionally deactivate it if expired.
     *
     * @param int $ruleId
     * @param string $nextRunDate
     * @param bool $deactivate
     * @return bool
     */
    public function advanceNextRunDate(int $ruleId, string $nextRunDate, bool $deactivate = false): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE `recurring_rules`
            SET `next_run_date` = :next_run_date,
                `is_active` = :is_active
            WHERE `id` = :id
        ");

        return $stmt->execute([
            ':id' => $ruleId,
            ':next_run_date' => $nextRunDate,
            ':is_active' => $deactivate ? 0 : 1,
        ]);
    }

    /**
     * Toggle active state or deactivate a rule.
     *
     * @param int $ruleId
     * @param int $groupId
     * @param bool $isActive
     * @return bool
     */
    public function setActive(int $ruleId, int $groupId, bool $isActive): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE `recurring_rules`
            SET `is_active` = :is_active
            WHERE `id` = :id AND `group_id` = :group_id
        ");

        return $stmt->execute([
            ':id' => $ruleId,
            ':group_id' => $groupId,
            ':is_active' => $isActive ? 1 : 0,
        ]);
    }

    /**
     * Delete a recurring rule.
     *
     * @param int $ruleId
     * @param int $groupId
     * @return bool
     */
    public function deleteRule(int $ruleId, int $groupId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `recurring_rules` WHERE `id` = :id AND `group_id` = :group_id");
        $stmt->execute([':id' => $ruleId, ':group_id' => $groupId]);
        return $stmt->rowCount() > 0;
    }
}
