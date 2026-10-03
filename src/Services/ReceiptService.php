<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ActivityLogRepository;
use App\Repositories\ExpenseRepository;
use App\Repositories\GroupRepository;
use App\Repositories\ReceiptRepository;
use App\Services\Storage\ReceiptStorageFactory;
use App\Services\Storage\ReceiptStorageInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Domain Service for Handling Receipt Attachment Uploads, Storage, and Lifecycle.
 */
class ReceiptService
{
    public const MAX_FILE_SIZE_BYTES = 5242880; // 5 MB

    public const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    private ReceiptRepository $receiptRepo;
    private ExpenseRepository $expenseRepo;
    private GroupRepository $groupRepo;
    private ActivityLogRepository $activityLogRepo;
    private ReceiptStorageInterface $storage;
    private string $uploadDir;

    public function __construct(
        ?ReceiptRepository $receiptRepo = null,
        ?ExpenseRepository $expenseRepo = null,
        ?GroupRepository $groupRepo = null,
        ?ActivityLogRepository $activityLogRepo = null,
        ?string $uploadDir = null,
        ?ReceiptStorageInterface $storage = null
    ) {
        $this->receiptRepo = $receiptRepo ?? new ReceiptRepository();
        $this->expenseRepo = $expenseRepo ?? new ExpenseRepository();
        $this->groupRepo = $groupRepo ?? new GroupRepository();
        $this->activityLogRepo = $activityLogRepo ?? new ActivityLogRepository();
        $this->uploadDir = $uploadDir ?? (dirname(__DIR__, 2) . '/storage/receipts');
        $this->storage = $storage ?? ($uploadDir !== null ? new \App\Services\Storage\LocalReceiptStorage($uploadDir) : ReceiptStorageFactory::getInstance());

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * Upload and attach a standard multipart receipt file to an expense.
     *
     * @param int $groupId
     * @param int $expenseId
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @param int|null $actorMemberId
     * @param string|null $inviteToken
     * @return array<string, mixed>
     */
    public function uploadReceipt(
        int $groupId,
        int $expenseId,
        array $file,
        ?int $actorMemberId = null,
        ?string $inviteToken = null
    ): array {
        $this->validateExpenseOwnership($groupId, $expenseId);

        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            throw new InvalidArgumentException("File upload failed with error code {$code}.", 422);
        }

        if (!isset($file['size']) || $file['size'] <= 0 || $file['size'] > self::MAX_FILE_SIZE_BYTES) {
            throw new InvalidArgumentException("File size exceeds maximum allowed limit of 5 MB.", 422);
        }

        $tmpPath = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmpPath) && !file_exists($tmpPath)) {
            throw new InvalidArgumentException("Invalid uploaded file stream.", 422);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpPath) ?: 'application/octet-stream';
        finfo_close($finfo);

        if (!isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
            throw new InvalidArgumentException("Unsupported file type '{$mimeType}'. Only JPEG, PNG, WebP, and PDF documents are supported.", 422);
        }

        $ext = self::ALLOWED_MIME_TYPES[$mimeType];
        $originalName = pathinfo((string) ($file['name'] ?? 'receipt'), PATHINFO_FILENAME);
        $sanitizedOriginalName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $originalName) . '.' . $ext;

        $storedFileName = sprintf(
            'receipt_%d_%d_%s.%s',
            $expenseId,
            time(),
            bin2hex(random_bytes(6)),
            $ext
        );

        $contents = file_get_contents($tmpPath);
        if ($contents === false) {
            throw new RuntimeException("Failed to read uploaded receipt file.", 500);
        }
        $this->storage->store($storedFileName, $contents, $mimeType);

        $relativeDbPath = 'storage/receipts/' . $storedFileName;
        $receiptId = $this->receiptRepo->create(
            $expenseId,
            $sanitizedOriginalName,
            $relativeDbPath,
            (int) $file['size'],
            $mimeType
        );

        // Record Activity Audit
        $this->activityLogRepo->record(
            $groupId,
            $actorMemberId,
            'RECEIPT_ATTACHED',
            'expenses',
            $expenseId,
            [
                'receipt_id' => $receiptId,
                'file_name' => $sanitizedOriginalName,
                'file_size_bytes' => (int) $file['size'],
                'mime_type' => $mimeType,
            ]
        );

        // Increment Group Version for OCC
        $this->incrementGroupVersion($groupId);
        \App\Services\EventService::broadcast($groupId, 'receipt.created', $receiptId);

        $token = $inviteToken;
        if ($token === null) {
            $group = $this->groupRepo->findById($groupId);
            $token = $group ? (string) $group['invite_token'] : null;
        }

        $receipt = $this->receiptRepo->findById($receiptId);
        return $this->formatReceipt($receipt ?? [], $token);
    }

    /**
     * Upload and attach a Base64-encoded receipt file (supports data URI schemes or raw Base64).
     *
     * @param int $groupId
     * @param int $expenseId
     * @param string $fileName
     * @param string $base64Payload
     * @param int|null $actorMemberId
     * @param string|null $inviteToken
     * @return array<string, mixed>
     */
    public function uploadReceiptBase64(
        int $groupId,
        int $expenseId,
        string $fileName,
        string $base64Payload,
        ?int $actorMemberId = null,
        ?string $inviteToken = null
    ): array {
        $this->validateExpenseOwnership($groupId, $expenseId);

        if (empty($base64Payload)) {
            throw new InvalidArgumentException("Receipt payload cannot be empty.", 422);
        }

        // Strip data:image/...;base64, header if present
        $cleanBase64 = $base64Payload;
        if (preg_match('/^data:([^;]+);base64,(.+)$/s', $base64Payload, $matches)) {
            $cleanBase64 = $matches[2];
        }

        $binaryData = base64_decode($cleanBase64, true);
        if ($binaryData === false) {
            throw new InvalidArgumentException("Invalid Base64 encoded file data.", 422);
        }

        $fileSizeBytes = strlen($binaryData);
        if ($fileSizeBytes <= 0 || $fileSizeBytes > self::MAX_FILE_SIZE_BYTES) {
            throw new InvalidArgumentException("File size exceeds maximum allowed limit of 5 MB.", 422);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_buffer($finfo, $binaryData) ?: 'application/octet-stream';
        finfo_close($finfo);

        if (!isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
            throw new InvalidArgumentException("Unsupported file type '{$mimeType}'. Only JPEG, PNG, WebP, and PDF documents are supported.", 422);
        }

        $ext = self::ALLOWED_MIME_TYPES[$mimeType];
        $originalName = pathinfo($fileName, PATHINFO_FILENAME);
        $sanitizedOriginalName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $originalName) . '.' . $ext;

        $storedFileName = sprintf(
            'receipt_%d_%d_%s.%s',
            $expenseId,
            time(),
            bin2hex(random_bytes(6)),
            $ext
        );

        $this->storage->store($storedFileName, $binaryData, $mimeType);

        $relativeDbPath = 'storage/receipts/' . $storedFileName;
        $receiptId = $this->receiptRepo->create(
            $expenseId,
            $sanitizedOriginalName,
            $relativeDbPath,
            $fileSizeBytes,
            $mimeType
        );

        // Record Activity Audit
        $this->activityLogRepo->record(
            $groupId,
            $actorMemberId,
            'RECEIPT_ATTACHED',
            'expenses',
            $expenseId,
            [
                'receipt_id' => $receiptId,
                'file_name' => $sanitizedOriginalName,
                'file_size_bytes' => $fileSizeBytes,
                'mime_type' => $mimeType,
            ]
        );

        // Increment Group Version for OCC
        $this->incrementGroupVersion($groupId);
        \App\Services\EventService::broadcast($groupId, 'receipt.created', $receiptId);

        $token = $inviteToken;
        if ($token === null) {
            $group = $this->groupRepo->findById($groupId);
            $token = $group ? (string) $group['invite_token'] : null;
        }

        $receipt = $this->receiptRepo->findById($receiptId);
        return $this->formatReceipt($receipt ?? [], $token);
    }

    /**
     * Retrieve all receipts attached to an expense.
     *
     * @param int $groupId
     * @param int $expenseId
     * @param string|null $inviteToken
     * @return array<array<string, mixed>>
     */
    public function getReceipts(int $groupId, int $expenseId, ?string $inviteToken = null): array
    {
        $this->validateExpenseOwnership($groupId, $expenseId);
        $token = $inviteToken;
        if ($token === null) {
            $group = $this->groupRepo->findById($groupId);
            $token = $group ? (string) $group['invite_token'] : null;
        }
        $list = $this->receiptRepo->findByExpenseId($expenseId);
        return array_map(fn($item) => $this->formatReceipt($item, $token), $list);
    }

    /**
     * Resolve and validate receipt file path for authorized download/streaming.
     *
     * @param int $groupId
     * @param int $expenseId
     * @param int $receiptId
     * @return array{full_path: string, file_name: string, mime_type: string, file_size_bytes: int}
     */
    public function getReceiptFile(int $groupId, int $expenseId, int $receiptId): array
    {
        $this->validateExpenseOwnership($groupId, $expenseId);

        $receipt = $this->receiptRepo->findById($receiptId);
        if (!$receipt || (int) $receipt['expense_id'] !== $expenseId || (int) $receipt['group_id'] !== $groupId) {
            throw new InvalidArgumentException("Receipt attachment not found.", 404);
        }

        $rawPath = (string) $receipt['file_path'];
        $baseName = basename($rawPath);
        $targetPath = $this->storage->getLocalPath($baseName);

        if ($targetPath === null || !file_exists($targetPath)) {
            $altPath = dirname(__DIR__, 2) . '/' . ltrim($rawPath, '/');
            if (file_exists($altPath)) {
                $targetPath = $altPath;
            }
        }

        $realStorage = realpath($this->uploadDir);
        $realPath = $targetPath !== null ? realpath($targetPath) : false;

        if ($realPath === false || !file_exists($realPath) || !is_file($realPath)) {
            throw new InvalidArgumentException("Receipt file not found on disk.", 404);
        }

        if ($realStorage !== false && !str_starts_with($realPath, $realStorage)) {
            $projectRoot = realpath(dirname(__DIR__, 2));
            if ($projectRoot === false || !str_starts_with($realPath, $projectRoot)) {
                throw new InvalidArgumentException("Unauthorized receipt file path.", 403);
            }
        }

        return [
            'full_path' => $realPath,
            'file_name' => (string) $receipt['file_name'],
            'mime_type' => (string) $receipt['mime_type'],
            'file_size_bytes' => (int) $receipt['file_size_bytes'],
        ];
    }

    /**
     * Delete a receipt attachment.
     *
     * @param int $groupId
     * @param int $expenseId
     * @param int $receiptId
     * @param int|null $actorMemberId
     * @return bool
     */
    public function deleteReceipt(int $groupId, int $expenseId, int $receiptId, ?int $actorMemberId = null): bool
    {
        $this->validateExpenseOwnership($groupId, $expenseId);

        $receipt = $this->receiptRepo->findById($receiptId);
        if (!$receipt || (int) $receipt['expense_id'] !== $expenseId || (int) $receipt['group_id'] !== $groupId) {
            throw new InvalidArgumentException("Receipt attachment not found.", 404);
        }

        $rawPath = (string) $receipt['file_path'];
        $baseName = basename($rawPath);
        $this->storage->delete($baseName);

        $altPath = dirname(__DIR__, 2) . '/' . ltrim($rawPath, '/');
        if (file_exists($altPath) && is_file($altPath)) {
            @unlink($altPath);
        }

        $this->receiptRepo->delete($receiptId);

        // Record Activity Audit
        $this->activityLogRepo->record(
            $groupId,
            $actorMemberId,
            'RECEIPT_DELETED',
            'expenses',
            $expenseId,
            [
                'receipt_id' => $receiptId,
                'file_name' => $receipt['file_name'],
            ]
        );

        // Increment Group Version
        $this->incrementGroupVersion($groupId);
        \App\Services\EventService::broadcast($groupId, 'receipt.deleted', $receiptId);

        return true;
    }

    /**
     * Format a receipt record with authorized endpoint URL and metadata.
     *
     * @param array<string, mixed> $r
     * @param string|null $inviteToken
     * @return array<string, mixed>
     */
    public function formatReceipt(array $r, ?string $inviteToken = null): array
    {
        $token = $inviteToken ?? ($r['invite_token'] ?? null);
        $expenseId = (int) ($r['expense_id'] ?? 0);
        $receiptId = (int) ($r['id'] ?? 0);

        if ($token === null && $expenseId > 0) {
            $exp = $this->expenseRepo->findDetailsById($expenseId);
            if ($exp && !empty($exp['group_id'])) {
                $grp = $this->groupRepo->findById((int) $exp['group_id']);
                if ($grp) {
                    $token = (string) $grp['invite_token'];
                }
            }
        }

        $url = ($token && $expenseId && $receiptId)
            ? "/api/groups/{$token}/expenses/{$expenseId}/receipts/{$receiptId}/download"
            : ('/' . ltrim((string) ($r['file_path'] ?? ''), '/'));

        return [
            'id' => $receiptId,
            'expense_id' => $expenseId,
            'file_name' => (string) ($r['file_name'] ?? 'receipt'),
            'file_path' => (string) ($r['file_path'] ?? ''),
            'url' => $url,
            'file_size_bytes' => (int) ($r['file_size_bytes'] ?? 0),
            'mime_type' => (string) ($r['mime_type'] ?? 'image/jpeg'),
            'is_image' => str_starts_with((string) ($r['mime_type'] ?? ''), 'image/'),
            'created_at' => (string) ($r['created_at'] ?? date('Y-m-d H:i:s')),
        ];
    }

    /**
     * Validate that the expense belongs to the group and is not deleted.
     */
    private function validateExpenseOwnership(int $groupId, int $expenseId): void
    {
        $expense = $this->expenseRepo->findDetailsById($expenseId);
        if (!$expense || (int) $expense['group_id'] !== $groupId || !empty($expense['is_deleted'])) {
            throw new InvalidArgumentException("Expense not found or does not belong to group.", 404);
        }
    }

    /**
     * Increment group version for OCC.
     */
    private function incrementGroupVersion(int $groupId): void
    {
        $pdo = \App\Core\Database::getConnection();
        $stmt = $pdo->prepare("UPDATE `groups` SET `version` = `version` + 1 WHERE `id` = :group_id");
        $stmt->execute([':group_id' => $groupId]);
    }

    /**
     * Get underlying receipt storage instance.
     */
    public function getStorage(): ReceiptStorageInterface
    {
        return $this->storage;
    }
}


