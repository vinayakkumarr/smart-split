<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\EventRepository;

/**
 * Domain Service for Workspace Real-Time Event Stream Dispatch & Retrieval.
 */
class EventService
{
    private EventRepository $eventRepo;
    private static ?self $instance = null;

    public function __construct(?EventRepository $eventRepo = null)
    {
        $this->eventRepo = $eventRepo ?? new EventRepository();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Emit and persist a workspace event.
     *
     * @param int $groupId
     * @param string $eventType E.g. 'expense.created', 'expense.updated', 'settlement.created'
     * @param int|null $entityId
     * @param int $version
     * @return int
     */
    public function emit(int $groupId, string $eventType, ?int $entityId = null, int $version = 1): int
    {
        return $this->eventRepo->record($groupId, $eventType, $entityId, $version);
    }

    /**
     * Static shortcut to emit an event.
     */
    public static function broadcast(int $groupId, string $eventType, ?int $entityId = null, int $version = 1): int
    {
        return self::getInstance()->emit($groupId, $eventType, $entityId, $version);
    }

    /**
     * Retrieve events after a given ID for streaming.
     *
     * @param int $groupId
     * @param int $lastEventId
     * @param int $limit
     * @return array<array<string, mixed>>
     */
    public function getEventsSince(int $groupId, int $lastEventId = 0, int $limit = 50): array
    {
        return $this->eventRepo->getEventsSince($groupId, $lastEventId, $limit);
    }

    /**
     * Clean up stale events older than retention period.
     */
    public function cleanupOldEvents(int $retentionSeconds = 86400): int
    {
        return $this->eventRepo->cleanupOldEvents($retentionSeconds);
    }
}
