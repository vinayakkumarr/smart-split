<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Request;
use App\Repositories\GroupRepository;
use App\Services\BalanceService;

/**
 * Controller handling Group Balance Aggregation and Member Ledgers.
 */
class BalanceController extends BaseController
{
    private GroupRepository $groupRepo;
    private BalanceService $balanceService;

    public function __construct(
        ?GroupRepository $groupRepo = null,
        ?BalanceService $balanceService = null
    ) {
        $this->groupRepo = $groupRepo ?? new GroupRepository();
        $this->balanceService = $balanceService ?? new BalanceService();
    }

    /**
     * GET /api/groups/{token}/balances
     * Retrieve aggregated group balances, member credit/debt statuses, and zero-sum verification.
     */
    public function index(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $balanceReport = $this->balanceService->calculateGroupBalances((int) $group['id']);

        $this->json([
            'group' => [
                'id' => (int) $group['id'],
                'name' => $group['name'],
                'currency_code' => $group['currency_code'],
            ],
            'total_spending_cents' => $balanceReport['total_spending_cents'],
            'total_settled_cents' => $balanceReport['total_settled_cents'],
            'zero_sum_verified' => $balanceReport['zero_sum_verified'],
            'members' => $balanceReport['members'],
        ]);
    }

    /**
     * GET /api/groups/{token}/settlement-plan
     * Compute debt simplification plan for the group.
     */
    public function settlementPlan(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $balanceReport = $this->balanceService->calculateGroupBalances((int) $group['id']);
        $currencyCode = (string) $group['currency_code'];

        $plan = \App\Services\SettlementEngine::simplifyDebts(
            $balanceReport['members'],
            $currencyCode
        );

        $this->json([
            'group' => [
                'id' => (int) $group['id'],
                'name' => $group['name'],
                'currency_code' => $currencyCode,
            ],
            'total_spending_cents' => $balanceReport['total_spending_cents'],
            'total_transactions' => $plan['total_transactions'],
            'total_settlement_volume_cents' => $plan['total_settlement_volume_cents'],
            'transactions' => $plan['transactions'],
        ]);
    }

    /**
     * GET /api/groups/{token}/members/{memberId}/ledger
     * Retrieve complete itemized personal ledger for a single group participant.
     */
    public function memberLedger(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $memberId = (int) $request->getParam('memberId');
        $ledger = $this->balanceService->getMemberItemizedLedger((int) $group['id'], $memberId);

        $this->json([
            'group' => [
                'id' => (int) $group['id'],
                'name' => $group['name'],
                'currency_code' => $group['currency_code'],
            ],
            'ledger' => $ledger,
        ]);
    }

    /**
     * GET /api/groups/{token}/analytics/summary
     * Retrieve category breakdown and daily spending trends.
     */
    public function analyticsSummary(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $analytics = $this->balanceService->getAnalyticsSummary((int) $group['id']);

        $this->json([
            'group' => [
                'id' => (int) $group['id'],
                'name' => $group['name'],
                'currency_code' => $group['currency_code'],
            ],
            'analytics' => $analytics,
        ]);
    }

    /**
     * GET /api/groups/{token}/bilateral-balances
     * Retrieve 1-on-1 direct pairwise debt relationships before graph simplification.
     */
    public function bilateralBalances(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $bilateral = $this->balanceService->getBilateralBalances((int) $group['id']);

        $this->json([
            'group' => [
                'id' => (int) $group['id'],
                'name' => $group['name'],
                'currency_code' => $group['currency_code'],
            ],
            'pairs' => $bilateral['pairs'],
        ]);
    }
}

