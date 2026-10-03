<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Data Access Repository for Workspace Real-Time Event Stream Log.
 */
class EventRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Record a lightweight workspace event for SSE broadcasting.
     *
     * @param int $groupId
     * @param string $eventType E.g. expense.created, expense.updated, settlement.created, member.updated
     * @param int|null $entityId
     * @param int $version
     * @return int Inserted Event ID
     */
    public function record(
        int $groupId,
        string $eventType,
        ?int $entityId = null,
        int $version = 1
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO `workspace_events` (`group_id`, `event_type`, `entity_id`, `version`, `created_at`)
            VALUES (:group_id, :event_type, :entity_id, :version, NOW())
        ");

        $stmt->execute([
            ':group_id' => $groupId,
            ':event_type' => strtolower(trim($eventType)),
            ':entity_id' => $entityId,
            ':version' => max(1, $version),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Fetch all events for a workspace occurring strictly after a given event ID.
     *
     * @param int $groupId
     * @param int $lastEventId
     * @param int $limit
     * @return array<array<string, mixed>>
     */
    public function getEventsSince(int $groupId, int $lastEventId = 0, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("
            SELECT `id`, `group_id`, `event_type`, `entity_id`, `version`, `created_at`
            FROM `workspace_events`
            WHERE `group_id` = :group_id AND `id` > :last_event_id
            ORDER BY `id` ASC
            LIMIT :limit
        ");

        $stmt->bindValue(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindValue(':last_event_id', $lastEventId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete expired workspace events older than retention period.
     *
     * @param int $retentionSeconds Default 86400 (24 hours)
     * @return int Deleted row count
     */
    public function cleanupOldEvents(int $retentionSeconds = 86400): int
    {
        $threshold = date('Y-m-d H:i:s', time() - max(300, $retentionSeconds));
        $stmt = $this->pdo->prepare("
            DELETE FROM `workspace_events`
            WHERE `created_at` < :threshold
        ");
        $stmt->execute([':threshold' => $threshold]);

        return $stmt->rowCount();
    }
}
