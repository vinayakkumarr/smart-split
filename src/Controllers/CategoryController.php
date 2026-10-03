<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Request;
use App\Repositories\ActivityLogRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\GroupRepository;
use InvalidArgumentException;

/**
 * Controller handling standard and workspace custom categories.
 */
class CategoryController extends BaseController
{
    private CategoryRepository $categoryRepo;
    private GroupRepository $groupRepo;
    private ActivityLogRepository $activityLogRepo;

    public function __construct(
        ?CategoryRepository $categoryRepo = null,
        ?GroupRepository $groupRepo = null,
        ?ActivityLogRepository $activityLogRepo = null
    ) {
        $this->categoryRepo = $categoryRepo ?? new CategoryRepository();
        $this->groupRepo = $groupRepo ?? new GroupRepository();
        $this->activityLogRepo = $activityLogRepo ?? new ActivityLogRepository();
    }

    /**
     * GET /api/categories or GET /api/groups/{token}/categories
     * Retrieve available categories for a workspace.
     */
    public function index(Request $request): void
    {
        $token = $request->getParam('token');
        $groupId = null;

        if ($token) {
            $group = $this->groupRepo->findByInviteToken((string) $token);
            if (!$group) {
                $this->error("Group not found.", 'NOT_FOUND', null, 404);
                return;
            }
            $groupId = (int) $group['id'];
        }

        $categories = $this->categoryRepo->getAll($groupId);

        $this->json([
            'categories' => $categories,
            'total_count' => count($categories),
        ]);
    }

    /**
     * POST /api/groups/{token}/categories
     * Create a new custom category for a workspace.
     */
    public function create(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $body = $request->getBody();
        if (empty($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
            throw new InvalidArgumentException("Category name cannot be empty.", 422);
        }

        $name = trim(strip_tags((string) $body['name']));
        $icon = trim(strip_tags((string) ($body['icon'] ?? '🏷️')));
        $colorHex = trim((string) ($body['color_hex'] ?? '#475569'));

        $categoryId = $this->categoryRepo->createCustom((int) $group['id'], $name, $icon, $colorHex);
        $category = $this->categoryRepo->findById($categoryId);

        // Record Activity Log
        $this->activityLogRepo->record(
            (int) $group['id'],
            null,
            'CATEGORY_CREATED',
            'categories',
            $categoryId,
            [
                'name' => $name,
                'icon' => $icon,
                'color_hex' => $colorHex,
            ]
        );

        $this->json([
            'category' => $category,
            'message' => 'Custom category created successfully.',
        ], 201);
    }

    /**
     * DELETE /api/groups/{token}/categories/{id}
     * Delete a custom category from a workspace.
     */
    public function delete(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $categoryId = (int) $request->getParam('id');
        $deleted = $this->categoryRepo->deleteCustom($categoryId, (int) $group['id']);

        if (!$deleted) {
            $this->error("Category not found in workspace.", 'NOT_FOUND', null, 404);
            return;
        }

        $this->json([
            'deleted' => true,
            'category_id' => $categoryId,
            'message' => 'Custom category deleted successfully.',
        ]);
    }
}
