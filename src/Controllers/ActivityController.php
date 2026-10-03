<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Request;
use App\Repositories\GroupRepository;
use App\Services\ActivityLogService;

/**
 * Controller handling Activity Feed and Audit Timeline endpoints.
 */
class ActivityController extends BaseController
{
    private GroupRepository $groupRepo;
    private ActivityLogService $activityService;

    public function __construct(
        ?GroupRepository $groupRepo = null,
        ?ActivityLogService $activityService = null
    ) {
        $this->groupRepo = $groupRepo ?? new GroupRepository();
        $this->activityService = $activityService ?? new ActivityLogService();
    }

    /**
     * GET /api/groups/{token}/activity-feed
     * Retrieve chronologically ordered and human-readable activity timeline.
     */
    public function index(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $limit = max(1, min(100, (int) $request->getQuery('limit', 50)));
        $offset = max(0, (int) $request->getQuery('offset', 0));
        $entityType = $request->getQuery('entity_type', null);
        if ($entityType !== null && trim((string) $entityType) === '') {
            $entityType = null;
        }

        $currency = (string) $group['currency_code'];
        $feed = $this->activityService->getActivityTimeline(
            (int) $group['id'],
            $currency,
            $limit,
            $offset,
            $entityType ? (string) $entityType : null
        );

        $this->json([
            'group' => [
                'id' => (int) $group['id'],
                'name' => $group['name'],
                'currency_code' => $currency,
            ],
            'activities' => $feed['activities'],
            'total_count' => $feed['total_count'],
            'limit' => $feed['limit'],
            'offset' => $feed['offset'],
        ]);
    }
}
