<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Request;
use App\Repositories\GroupRepository;
use App\Services\ReceiptService;
use InvalidArgumentException;

/**
 * Controller handling Receipt Attachments CRUD, File Uploads, and Authorized Streaming.
 */
class ReceiptController extends BaseController
{
    private GroupRepository $groupRepo;
    private ReceiptService $receiptService;

    public function __construct(
        ?GroupRepository $groupRepo = null,
        ?ReceiptService $receiptService = null
    ) {
        $this->groupRepo = $groupRepo ?? new GroupRepository();
        $this->receiptService = $receiptService ?? new ReceiptService();
    }

    /**
     * GET /api/groups/{token}/expenses/{id}/receipts
     * Retrieve all receipts attached to an expense.
     */
    public function index(Request $request): void
    {
        $token = (string) $request->getParam('token');
        $expenseId = (int) $request->getParam('id');

        $group = $this->groupRepo->findByInviteToken($token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $receipts = $this->receiptService->getReceipts((int) $group['id'], $expenseId, $token);
        $this->json(['receipts' => $receipts]);
    }

    /**
     * POST /api/groups/{token}/expenses/{id}/receipts
     * Upload a new receipt attachment (multipart or base64).
     */
    public function create(Request $request): void
    {
        $token = (string) $request->getParam('token');
        $expenseId = (int) $request->getParam('id');

        $group = $this->groupRepo->findByInviteToken($token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $groupId = (int) $group['id'];
        $actorMemberId = $request->getBodyParam('actor_member_id') !== null
            ? (int) $request->getBodyParam('actor_member_id')
            : null;

        // Check multipart $_FILES
        if (!empty($_FILES['receipt'])) {
            $file = $_FILES['receipt'];
            $receipt = $this->receiptService->uploadReceipt($groupId, $expenseId, $file, $actorMemberId, $token);
            $this->json(['receipt' => $receipt], 201);
            return;
        }

        if (!empty($_FILES['file'])) {
            $file = $_FILES['file'];
            $receipt = $this->receiptService->uploadReceipt($groupId, $expenseId, $file, $actorMemberId, $token);
            $this->json(['receipt' => $receipt], 201);
            return;
        }

        // Check JSON Base64 payload
        $base64 = $request->getBodyParam('data_base64')
            ?? $request->getBodyParam('file_base64')
            ?? $request->getBodyParam('base64');

        $fileName = (string) ($request->getBodyParam('file_name') ?? $request->getBodyParam('filename') ?? 'receipt.jpg');

        if (!empty($base64)) {
            $receipt = $this->receiptService->uploadReceiptBase64($groupId, $expenseId, $fileName, (string) $base64, $actorMemberId, $token);
            $this->json(['receipt' => $receipt], 201);
            return;
        }

        throw new InvalidArgumentException("No receipt file provided. Please provide a multipart file or Base64 payload.", 422);
    }

    /**
     * GET /api/groups/{token}/expenses/{id}/receipts/{receiptId}/download
     * GET /api/groups/{token}/expenses/{id}/receipts/{receiptId}/view
     * Securely stream authorized receipt attachment.
     */
    public function download(Request $request): void
    {
        $token = (string) $request->getParam('token');
        $expenseId = (int) $request->getParam('id');
        $receiptId = (int) $request->getParam('receiptId');

        $group = $this->groupRepo->findByInviteToken($token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        try {
            $fileInfo = $this->receiptService->getReceiptFile((int) $group['id'], $expenseId, $receiptId);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage(), 'NOT_FOUND', null, $e->getCode() ?: 404);
            return;
        }

        $fullPath = $fileInfo['full_path'];
        $fileName = $fileInfo['file_name'];
        $mimeType = $fileInfo['mime_type'];
        $fileSize = $fileInfo['file_size_bytes'] > 0 ? $fileInfo['file_size_bytes'] : (int) filesize($fullPath);

        $sanitizedFileName = str_replace(['"', "\r", "\n"], '_', $fileName);
        $disposition = ($request->getQueryParam('disposition') === 'attachment' || $request->getQueryParam('download') === '1')
            ? 'attachment'
            : 'inline';

        if (ob_get_level()) {
            ob_end_clean();
        }

        \App\Core\Response::$lastStatusCode = 200;
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . (string) $fileSize);
            header('Content-Disposition: ' . $disposition . '; filename="' . $sanitizedFileName . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        readfile($fullPath);
        if (php_sapi_name() !== 'cli') {
            exit();
        }
    }

    /**
     * DELETE /api/groups/{token}/expenses/{id}/receipts/{receiptId}
     * Delete an existing receipt attachment.
     */
    public function delete(Request $request): void
    {
        $token = (string) $request->getParam('token');
        $expenseId = (int) $request->getParam('id');
        $receiptId = (int) $request->getParam('receiptId');

        $group = $this->groupRepo->findByInviteToken($token);
        if (!$group) {
            $this->error("Group not found.", 'NOT_FOUND', null, 404);
            return;
        }

        $actorMemberId = $request->getQueryParam('actor_member_id') !== null
            ? (int) $request->getQueryParam('actor_member_id')
            : null;

        $this->receiptService->deleteReceipt((int) $group['id'], $expenseId, $receiptId, $actorMemberId);
        $this->json(['success' => true, 'deleted_id' => $receiptId]);
    }
}
