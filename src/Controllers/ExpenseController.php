<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Request;
use App\Repositories\CategoryRepository;
use App\Repositories\ExpenseRepository;
use App\Repositories\GroupRepository;
use App\Services\ExpenseService;

/**
 * Controller handling Expense logging, updates, details retrieval, categories, and CSV export.
 */
class ExpenseController extends BaseController
{
    private GroupRepository $groupRepo;
    private ExpenseRepository $expenseRepo;
    private ExpenseService $expenseService;
    private CategoryRepository $categoryRepo;

    public function __construct(
        ?GroupRepository $groupRepo = null,
        ?ExpenseRepository $expenseRepo = null,
        ?ExpenseService $expenseService = null,
        ?CategoryRepository $categoryRepo = null
    ) {
        $this->groupRepo = $groupRepo ?? new GroupRepository();
        $this->expenseRepo = $expenseRepo ?? new ExpenseRepository();
        $this->expenseService = $expenseService ?? new ExpenseService($this->expenseRepo);
        $this->categoryRepo = $categoryRepo ?? new CategoryRepository();
    }

    /**
     * GET /api/categories
     * Retrieve list of standard expense categories.
     */
    public function categories(Request $request): void
    {
        $categories = $this->categoryRepo->getAll();

        $this->json([
            'categories' => $categories,
        ]);
    }

    /**
     * POST /api/groups/{token}/expenses
     * Create and atomically persist a new expense.
     */
    public function create(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $idempotencyKey = $request->getHeader('X-Idempotency-Key') ?? ($request->getBody()['idempotency_key'] ?? null);
        if ($idempotencyKey !== null) {
            $idempotencyKey = trim((string) $idempotencyKey);
            if ($idempotencyKey === '') {
                $idempotencyKey = null;
            }
        }

        $expenseId = $this->expenseService->createExpense((int) $group['id'], $request->getBody(), $idempotencyKey);
        \App\Services\EventService::broadcast((int) $group['id'], 'expense.created', $expenseId);
        $expense = $this->expenseRepo->findDetailsById($expenseId);

        $this->json([
            'expense' => $expense,
        ], 201);
    }

    /**
     * PUT /api/groups/{token}/expenses/{id}
     * Update an existing expense and rebalance participant splits.
     */
    public function update(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $expenseId = (int) $request->getParam('id');
        $updated = $this->expenseService->updateExpense((int) $group['id'], $expenseId, $request->getBody());
        \App\Services\EventService::broadcast((int) $group['id'], 'expense.updated', $expenseId);

        $this->json([
            'expense' => $updated,
        ]);
    }

    /**
     * GET /api/groups/{token}/expenses
     * Retrieve active expenses for a group with optional multi-dimensional filtering.
     */
    public function index(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $filters = [
            'search' => $request->getQuery('search'),
            'category_id' => $request->getQuery('category_id'),
            'split_type' => $request->getQuery('split_type'),
            'from_date' => $request->getQuery('from_date'),
            'to_date' => $request->getQuery('to_date'),
            'min_amount_cents' => $request->getQuery('min_amount_cents'),
            'max_amount_cents' => $request->getQuery('max_amount_cents'),
            'member_id' => $request->getQuery('member_id'),
            'payer_id' => $request->getQuery('payer_id'),
            'debtor_id' => $request->getQuery('debtor_id'),
        ];

        // Clean out null/empty filters
        $activeFilters = array_filter($filters, function ($v) {
            return $v !== null && $v !== '';
        });

        $expenses = $this->expenseRepo->findByGroupId((int) $group['id'], false, $activeFilters);

        $this->json([
            'expenses' => $expenses,
            'total_count' => count($expenses),
            'filters' => $activeFilters,
        ]);
    }

    /**
     * GET /api/groups/{token}/expenses/{id}
     * Retrieve detailed breakdown of a single expense.
     */
    public function show(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $expenseId = (int) $request->getParam('id');
        $expense = $this->expenseRepo->findDetailsById($expenseId);

        if (!$expense || $expense['group_id'] !== (int) $group['id'] || $expense['is_deleted']) {
            $this->error("Expense not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $this->json([
            'expense' => $expense,
        ]);
    }

    /**
     * DELETE /api/groups/{token}/expenses/{id}
     * Soft-delete an expense and maintain historical audit trail.
     */
    public function delete(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $expenseId = (int) $request->getParam('id');
        $expense = $this->expenseRepo->findDetailsById($expenseId);

        if (!$expense || $expense['group_id'] !== (int) $group['id'] || $expense['is_deleted']) {
            $this->error("Expense not found or already deleted.", 'NOT_FOUND', null, 404);
            return;
        }

        $deleted = $this->expenseRepo->softDelete($expenseId, (int) $group['id']);
        \App\Services\EventService::broadcast((int) $group['id'], 'expense.deleted', $expenseId);

        $this->json([
            'deleted' => $deleted,
            'expense_id' => $expenseId,
        ]);
    }

    /**
     * GET /api/groups/{token}/expenses/trash
     * Retrieve list of soft-deleted expenses for a group.
     */
    public function trash(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $expenses = $this->expenseRepo->getDeletedByGroupId((int) $group['id']);

        $this->json([
            'expenses' => $expenses,
            'total_count' => count($expenses),
        ]);
    }

    /**
     * PUT /api/groups/{token}/expenses/{id}/restore
     * Restore a soft-deleted expense.
     */
    public function restore(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $expenseId = (int) $request->getParam('id');
        $expense = $this->expenseRepo->findDetailsById($expenseId);

        if (!$expense || $expense['group_id'] !== (int) $group['id'] || !$expense['is_deleted']) {
            $this->error("Expense not found in trash.", 'NOT_FOUND', null, 404);
            return;
        }

        $restored = $this->expenseRepo->restoreExpense($expenseId, (int) $group['id']);
        \App\Services\EventService::broadcast((int) $group['id'], 'expense.restored', $expenseId);

        $this->json([
            'restored' => $restored,
            'expense_id' => $expenseId,
        ]);
    }

    /**
     * GET /api/groups/{token}/export.csv
     * Stream download complete financial ledger as an RFC 4180 CSV file.
     */
    public function exportCsv(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $csv = $this->expenseService->generateCsv((int) $group['id'], (string) $group['currency_code']);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $group['name']);
        $filename = "smartsplit_{$safeName}_export.csv";
        \App\Core\Response::$lastStatusCode = 200;
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        echo $csv;
        if (php_sapi_name() !== 'cli') {
            exit;
        }
    }
}
