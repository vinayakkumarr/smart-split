<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Data Access Repository for Activity and Audit Logs.
 */
class ActivityLogRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Record an audit log entry.
     *
     * @param int $groupId
     * @param int|null $actorMemberId
     * @param string $action E.g. EXPENSE_ADDED, EXPENSE_DELETED, SETTLEMENT_RECORDED
     * @param string $entityType E.g. expenses, settlements, members, categories, recurring
     * @param int $entityId
     * @param array<string, mixed> $payload
     * @return int Inserted log ID.
     */
    public function record(
        int $groupId,
        ?int $actorMemberId,
        string $action,
        string $entityType,
        int $entityId,
        array $payload
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO `activity_logs` (`group_id`, `actor_member_id`, `action`, `entity_type`, `entity_id`, `payload_json`)
            VALUES (:group_id, :actor_member_id, :action, :entity_type, :entity_id, :payload_json)
        ");

        $stmt->execute([
            ':group_id' => $groupId,
            ':actor_member_id' => $actorMemberId,
            ':action' => strtoupper($action),
            ':entity_type' => strtolower($entityType),
            ':entity_id' => $entityId,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Fetch recent activity logs for a group with optional entity filtering and pagination.
     *
     * @param int $groupId
     * @param int $limit
     * @param int $offset
     * @param string|null $entityType
     * @return array<array<string, mixed>>
     */
    public function findByGroupId(int $groupId, int $limit = 50, int $offset = 0, ?string $entityType = null): array
    {
        $sql = "
            SELECT l.`id`, l.`group_id`, l.`actor_member_id`, m.`name` AS `actor_name`,
                   l.`action`, l.`entity_type`, l.`entity_id`, l.`payload_json`, l.`created_at`
            FROM `activity_logs` l
            LEFT JOIN `members` m ON l.`actor_member_id` = m.`id`
            WHERE l.`group_id` = :group_id
        ";

        if ($entityType !== null && $entityType !== '') {
            $sql .= " AND l.`entity_type` = :entity_type";
        }

        $sql .= " ORDER BY l.`created_at` DESC, l.`id` DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':group_id', $groupId, PDO::PARAM_INT);
        if ($entityType !== null && $entityType !== '') {
            $stmt->bindValue(':entity_type', strtolower($entityType), PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return array_map(function (array $row) {
            $row['payload'] = json_decode((string) $row['payload_json'], true) ?? [];
            unset($row['payload_json']);
            return $row;
        }, $rows);
    }

    /**
     * Count total activity logs for a group.
     *
     * @param int $groupId
     * @param string|null $entityType
     * @return int
     */
    public function countByGroupId(int $groupId, ?string $entityType = null): int
    {
        $sql = "SELECT COUNT(*) FROM `activity_logs` WHERE `group_id` = :group_id";
        if ($entityType !== null && $entityType !== '') {
            $sql .= " AND `entity_type` = :entity_type";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':group_id', $groupId, PDO::PARAM_INT);
        if ($entityType !== null && $entityType !== '') {
            $stmt->bindValue(':entity_type', strtolower($entityType), PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
