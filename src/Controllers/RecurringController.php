<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Request;
use App\Repositories\GroupRepository;
use App\Services\RecurringService;

/**
 * Controller handling Recurring Expense Schedules and Evaluation Triggers.
 */
class RecurringController extends BaseController
{
    private GroupRepository $groupRepo;
    private RecurringService $recurringService;

    public function __construct(
        ?GroupRepository $groupRepo = null,
        ?RecurringService $recurringService = null
    ) {
        $this->groupRepo = $groupRepo ?? new GroupRepository();
        $this->recurringService = $recurringService ?? new RecurringService();
    }

    /**
     * GET /api/groups/{token}/recurring
     * Retrieve all recurring expense schedules for a workspace.
     */
    public function index(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $rules = $this->recurringService->getRules((int) $group['id']);

        $this->json([
            'rules' => $rules,
            'total_count' => count($rules),
        ]);
    }

    /**
     * POST /api/groups/{token}/recurring
     * Create a new scheduled recurring expense rule.
     */
    public function create(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $ruleId = $this->recurringService->createRule((int) $group['id'], $request->getBody());

        $this->json([
            'rule_id' => $ruleId,
            'message' => 'Recurring schedule created successfully.',
        ], 201);
    }

    /**
     * DELETE /api/groups/{token}/recurring/{id}
     * Delete or cancel a recurring rule schedule.
     */
    public function delete(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $ruleId = (int) $request->getParam('id');
        $deleted = $this->recurringService->deleteRule((int) $group['id'], $ruleId);

        if (!$deleted) {
            $this->error("Recurring expense rule not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $this->json([
            'deleted' => true,
            'rule_id' => $ruleId,
        ]);
    }

    /**
     * POST /api/groups/{token}/recurring/evaluate
     * Trigger evaluation of all due recurring schedules and materialize expenses.
     */
    public function evaluate(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $body = $request->getBody();
        $targetDate = isset($body['current_date']) && is_string($body['current_date'])
            ? $body['current_date']
            : null;

        $result = $this->recurringService->evaluateDueRules((int) $group['id'], $targetDate);

        $this->json([
            'evaluation' => $result,
        ]);
    }
}
