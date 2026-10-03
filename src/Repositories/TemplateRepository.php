<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Repository for managing reusable expense templates.
 */
class TemplateRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Create a new expense template.
     *
     * @param int $groupId
     * @param string $title
     * @param int $totalAmountCents
     * @param string $splitType
     * @param int|null $categoryId
     * @param array<string, mixed> $payload
     * @param int $createdByMemberId
     * @return int
     */
    public function createTemplate(
        int $groupId,
        string $title,
        int $totalAmountCents,
        string $splitType,
        ?int $categoryId,
        array $payload,
        int $createdByMemberId
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO `expense_templates` (
                `group_id`, `title`, `total_amount_cents`, `split_type`, `category_id`,
                `payload_json`, `created_by_member_id`
            ) VALUES (
                :group_id, :title, :total_amount_cents, :split_type, :category_id,
                :payload_json, :created_by_member_id
            )
        ");

        $stmt->execute([
            ':group_id' => $groupId,
            ':title' => $title,
            ':total_amount_cents' => $totalAmountCents,
            ':split_type' => strtoupper($splitType),
            ':category_id' => $categoryId,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':created_by_member_id' => $createdByMemberId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Fetch all templates for a group.
     *
     * @param int $groupId
     * @return array<array<string, mixed>>
     */
    public function findByGroupId(int $groupId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT t.`id`, t.`group_id`, t.`title`, t.`total_amount_cents`, t.`split_type`,
                   t.`category_id`, c.`slug` AS `category_slug`, c.`name` AS `category_name`, c.`icon` AS `category_icon`,
                   t.`payload_json`, t.`created_by_member_id`, m.`name` AS `creator_name`,
                   t.`created_at`, t.`updated_at`
            FROM `expense_templates` t
            JOIN `members` m ON t.`created_by_member_id` = m.`id`
            LEFT JOIN `categories` c ON t.`category_id` = c.`id`
            WHERE t.`group_id` = :group_id
            ORDER BY t.`title` ASC
        ");
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
     * Delete a template.
     *
     * @param int $templateId
     * @param int $groupId
     * @return bool
     */
    public function deleteTemplate(int $templateId, int $groupId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `expense_templates` WHERE `id` = :id AND `group_id` = :group_id");
        $stmt->execute([':id' => $templateId, ':group_id' => $groupId]);
        return $stmt->rowCount() > 0;
    }
}
