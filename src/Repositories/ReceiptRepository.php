<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Data Access Repository for Expense Receipt Attachments.
 */
class ReceiptRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Store a new receipt attachment record.
     *
     * @param int $expenseId
     * @param string $fileName
     * @param string $filePath
     * @param int $fileSizeBytes
     * @param string $mimeType
     * @return int Inserted receipt ID
     */
    public function create(
        int $expenseId,
        string $fileName,
        string $filePath,
        int $fileSizeBytes,
        string $mimeType
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO `receipt_attachments` (`expense_id`, `file_name`, `file_path`, `file_size_bytes`, `mime_type`, `created_at`)
            VALUES (:expense_id, :file_name, :file_path, :file_size_bytes, :mime_type, NOW())
        ");

        $stmt->execute([
            ':expense_id' => $expenseId,
            ':file_name' => $fileName,
            ':file_path' => $filePath,
            ':file_size_bytes' => $fileSizeBytes,
            ':mime_type' => $mimeType,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Retrieve all receipts for a specific expense.
     *
     * @param int $expenseId
     * @return array<array<string, mixed>>
     */
    public function findByExpenseId(int $expenseId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT `id`, `expense_id`, `file_name`, `file_path`, `file_size_bytes`, `mime_type`, `created_at`
            FROM `receipt_attachments`
            WHERE `expense_id` = :expense_id
            ORDER BY `id` ASC
        ");
        $stmt->execute([':expense_id' => $expenseId]);
        return $stmt->fetchAll();
    }

    /**
     * Find a specific receipt by ID.
     *
     * @param int $receiptId
     * @return array<string, mixed>|null
     */
    public function findById(int $receiptId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT r.`id`, r.`expense_id`, r.`file_name`, r.`file_path`, r.`file_size_bytes`, r.`mime_type`, r.`created_at`,
                   e.`group_id`
            FROM `receipt_attachments` r
            JOIN `expenses` e ON r.`expense_id` = e.`id`
            WHERE r.`id` = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $receiptId]);
        $res = $stmt->fetch();
        return $res ?: null;
    }

    /**
     * Delete a specific receipt record.
     *
     * @param int $receiptId
     * @return bool
     */
    public function delete(int $receiptId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `receipt_attachments` WHERE `id` = :id");
        $stmt->execute([':id' => $receiptId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete all receipts for an expense.
     *
     * @param int $expenseId
     * @return bool
     */
    public function deleteByExpenseId(int $expenseId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `receipt_attachments` WHERE `expense_id` = :expense_id");
        $stmt->execute([':expense_id' => $expenseId]);
        return true;
    }
}
