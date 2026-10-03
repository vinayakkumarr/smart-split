<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Request;
use App\Repositories\GroupRepository;
use App\Services\EventService;

/**
 * Controller for Server-Sent Events (SSE) Real-Time Workspace Synchronization.
 */
class EventController extends BaseController
{
    private GroupRepository $groupRepo;
    private EventService $eventService;

    public function __construct(
        ?GroupRepository $groupRepo = null,
        ?EventService $eventService = null
    ) {
        $this->groupRepo = $groupRepo ?? new GroupRepository();
        $this->eventService = $eventService ?? EventService::getInstance();
    }

    /**
     * GET /api/groups/{token}/events
     * Stream real-time workspace mutations using native SSE.
     */
    public function stream(Request $request): void
    {
        $token = (string) $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken($token);

        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $groupId = (int) $group['id'];

        // Determine starting event ID
        $lastEventId = 0;
        $headerLastId = $request->getHeader('Last-Event-ID') ?? $request->getHeader('last-event-id');
        if ($headerLastId !== null && is_numeric($headerLastId)) {
            $lastEventId = max(0, (int) $headerLastId);
        } else {
            $queryLastId = $request->getQueryParam('last_event_id') ?? $request->getQueryParam('lastEventId');
            if ($queryLastId !== null && is_numeric($queryLastId)) {
                $lastEventId = max(0, (int) $queryLastId);
            }
        }

        // Test/Poll Mode Support: Returns JSON immediately without holding connection
        if ($request->getQueryParam('poll') === '1' || $request->getQueryParam('mode') === 'poll') {
            $events = $this->eventService->getEventsSince($groupId, $lastEventId, 50);
            $this->json([
                'group_id' => $groupId,
                'last_event_id' => $lastEventId,
                'events' => $events,
            ]);
            return;
        }

        // Set SSE streaming headers
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-transform, must-revalidate');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
        }

        // Clear output buffering for immediate flush in production server
        if (php_sapi_name() !== 'cli') {
            while (ob_get_level()) {
                ob_end_clean();
            }
        }

        // Send initial connection ACK and reconnection retry directive (2s for dev/polling fallback)
        echo "retry: 2000\n";
        echo ": connected\n\n";
        if (function_exists('flush')) {
            flush();
        }

        $startTime = time();
        $maxDuration = 25; // 25 seconds bounded connection lifetime
        $heartbeatInterval = 5;
        $lastHeartbeat = time();

        // If once=1 or single-worker CLI dev server (prevents blocking single-threaded php -S)
        $isCliServer = php_sapi_name() === 'cli-server' && (empty($_SERVER['PHP_CLI_SERVER_WORKERS']) || (int) $_SERVER['PHP_CLI_SERVER_WORKERS'] <= 1);
        $isOnce = $request->getQueryParam('once') === '1' || $isCliServer;

        while (true) {
            if (connection_aborted()) {
                break;
            }

            $events = $this->eventService->getEventsSince($groupId, $lastEventId, 50);

            if (!empty($events)) {
                foreach ($events as $ev) {
                    $eventId = (int) $ev['id'];
                    $lastEventId = $eventId;
                    $payload = json_encode([
                        'type' => (string) $ev['event_type'],
                        'entity_id' => $ev['entity_id'] !== null ? (int) $ev['entity_id'] : null,
                        'version' => (int) $ev['version'],
                        'group_id' => (int) $ev['group_id'],
                        'created_at' => (string) $ev['created_at'],
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                    echo "id: {$eventId}\n";
                    echo "event: message\n";
                    echo "data: {$payload}\n\n";
                }

                if (function_exists('flush')) {
                    flush();
                }
            }

            if ($isOnce) {
                break;
            }

            // Periodic keep-alive comment
            $now = time();
            if ($now - $lastHeartbeat >= $heartbeatInterval) {
                echo ": keepalive\n\n";
                if (function_exists('flush')) {
                    flush();
                }
                $lastHeartbeat = $now;
            }

            // Connection lifetime limit
            if ($now - $startTime >= $maxDuration) {
                break;
            }

            usleep(500000); // 500ms
        }

        if (php_sapi_name() !== 'cli') {
            exit();
        }
    }
}
