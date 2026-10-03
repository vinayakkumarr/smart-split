<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Request;
use App\Repositories\GroupRepository;
use App\Repositories\TemplateRepository;
use App\Repositories\MemberRepository;
use App\Utils\Money;
use InvalidArgumentException;

/**
 * Controller handling Reusable Expense Templates.
 */
class TemplateController extends BaseController
{
    private GroupRepository $groupRepo;
    private TemplateRepository $templateRepo;
    private MemberRepository $memberRepo;

    public function __construct(
        ?GroupRepository $groupRepo = null,
        ?TemplateRepository $templateRepo = null,
        ?MemberRepository $memberRepo = null
    ) {
        $this->groupRepo = $groupRepo ?? new GroupRepository();
        $this->templateRepo = $templateRepo ?? new TemplateRepository();
        $this->memberRepo = $memberRepo ?? new MemberRepository();
    }

    /**
     * GET /api/groups/{token}/templates
     * Retrieve all saved expense templates for a workspace.
     */
    public function index(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $templates = $this->templateRepo->findByGroupId((int) $group['id']);

        $this->json([
            'templates' => $templates,
            'total_count' => count($templates),
        ]);
    }

    /**
     * POST /api/groups/{token}/templates
     * Save a new reusable expense template preset.
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
        if (empty($body['title']) || !is_string($body['title']) || trim($body['title']) === '') {
            throw new InvalidArgumentException("Template title cannot be empty.", 422);
        }
        $title = trim(strip_tags((string) $body['title']));

        $totalCents = isset($body['total_amount_cents'])
            ? (int) $body['total_amount_cents']
            : (isset($body['amount_cents']) ? (int) $body['amount_cents'] : Money::toCents($body['amount'] ?? 0));

        $splitType = strtoupper((string) ($body['split_type'] ?? 'EQUAL'));
        $categoryId = isset($body['category_id']) && !empty($body['category_id']) ? (int) $body['category_id'] : null;

        $groupMembers = $this->memberRepo->findByGroupId((int) $group['id']);
        $validMemberIds = array_map('intval', array_column($groupMembers, 'id'));
        $createdByMemberId = isset($body['created_by_member_id']) ? (int) $body['created_by_member_id'] : (int) ($validMemberIds[0] ?? 1);

        $payload = [
            'title' => $title,
            'amount_cents' => $totalCents,
            'split_type' => $splitType,
            'category_id' => $categoryId,
            'payers' => $body['payers'] ?? null,
            'paid_by_member_id' => $body['paid_by_member_id'] ?? null,
            'splits' => $body['splits'] ?? null,
            'split_members' => $body['split_members'] ?? null,
            'items' => $body['items'] ?? null,
            'notes' => $body['notes'] ?? null,
            'tax_cents' => (int) ($body['tax_cents'] ?? 0),
            'tip_cents' => (int) ($body['tip_cents'] ?? 0),
            'discount_cents' => (int) ($body['discount_cents'] ?? 0),
        ];

        $templateId = $this->templateRepo->createTemplate(
            (int) $group['id'],
            $title,
            $totalCents,
            $splitType,
            $categoryId,
            $payload,
            $createdByMemberId
        );

        $this->json([
            'template_id' => $templateId,
            'message' => 'Template saved successfully.',
        ], 201);
    }

    /**
     * DELETE /api/groups/{token}/templates/{id}
     * Delete an expense template preset.
     */
    public function delete(Request $request): void
    {
        $token = $request->getParam('token');
        $group = $this->groupRepo->findByInviteToken((string) $token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $templateId = (int) $request->getParam('id');
        $deleted = $this->templateRepo->deleteTemplate($templateId, (int) $group['id']);

        if (!$deleted) {
            $this->error("Expense template not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $this->json([
            'deleted' => true,
            'template_id' => $templateId,
        ]);
    }
}
