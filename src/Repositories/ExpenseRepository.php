<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Data Access Repository for Expenses, Payers, and Splits with ACID Transaction Boundaries.
 */
class ExpenseRepository
{
    private PDO $pdo;
    private ActivityLogRepository $logRepo;

    public function __construct(?PDO $pdo = null, ?ActivityLogRepository $logRepo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->logRepo = $logRepo ?? new ActivityLogRepository($this->pdo);
    }

    /**
     * Atomically create an expense and its associated payers and splits inside an ACID transaction.
     *
     * @param int $groupId
     * @param string $title
     * @param int $totalAmountCents
     * @param string $splitType E.g. EQUAL, EXACT, PERCENTAGE, SHARES
     * @param string $expenseDate YYYY-MM-DD
     * @param int $createdByMemberId
     * @param array<array{member_id: int, amount_paid_cents: int}> $payers
     * @param array<array{member_id: int, amount_owed_cents: int, split_value: float|int|null}> $splits
     * @param int|null $categoryId
     * @return int The created expense ID.
     * @throws Throwable
     */
    public function createExpense(
        int $groupId,
        string $title,
        int $totalAmountCents,
        string $splitType,
        string $expenseDate,
        int $createdByMemberId,
        array $payers,
        array $splits,
        ?int $categoryId = null,
        int $taxCents = 0,
        int $tipCents = 0,
        int $discountCents = 0,
        ?string $notes = null,
        ?string $originalCurrencyCode = null,
        ?int $originalAmountCents = null,
        ?float $exchangeRate = null,
        ?string $idempotencyKey = null,
        ?bool &$isDuplicate = null
    ): int {
        return Database::transaction(function (PDO $pdo) use (
            $groupId, $title, $totalAmountCents, $splitType, $expenseDate,
            $createdByMemberId, $payers, $splits, $categoryId,
            $taxCents, $tipCents, $discountCents, $notes,
            $originalCurrencyCode, $originalAmountCents, $exchangeRate,
            $idempotencyKey, &$isDuplicate
        ): int {
            // 1. Acquire Exclusive Row Lock on group to serialize group writes without premature version bump
            $lockStmt = $pdo->prepare("SELECT `id`, `version` FROM `groups` WHERE `id` = :group_id FOR UPDATE");
            $lockStmt->execute([':group_id' => $groupId]);

            // 2. Check Server-Side Idempotency Record (if key provided)
            $trimmedKey = $idempotencyKey !== null ? trim($idempotencyKey) : '';
            $requestHash = '';
            if ($trimmedKey !== '') {
                $requestHash = hash('sha256', (string) json_encode([
                    'title' => $title,
                    'total_amount_cents' => $totalAmountCents,
                    'split_type' => strtoupper($splitType),
                    'expense_date' => $expenseDate,
                    'payers' => $payers,
                    'splits' => $splits,
                ]));

                $idempCheckStmt = $pdo->prepare("
                    SELECT `expense_id`, `request_hash` FROM `idempotency_keys`
                    WHERE `group_id` = :group_id AND `idempotency_key` = :idempotency_key
                    FOR UPDATE
                ");
                $idempCheckStmt->execute([
                    ':group_id' => $groupId,
                    ':idempotency_key' => $trimmedKey,
                ]);
                $existingIdemp = $idempCheckStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingIdemp) {
                    if ($existingIdemp['request_hash'] !== $requestHash) {
                        throw new \InvalidArgumentException("Idempotency key reused with mismatched payload data.", 409);
                    }
                    $isDuplicate = true;
                    return (int) $existingIdemp['expense_id'];
                }
            }

            // 3. New non-duplicate mutation: Increment Group Version NOW
            $versionStmt = $pdo->prepare("
                UPDATE `groups` SET `version` = `version` + 1 WHERE `id` = :group_id
            ");
            $versionStmt->execute([':group_id' => $groupId]);

            // 4. Insert Master Expense Record
            $expenseStmt = $pdo->prepare("
                INSERT INTO `expenses` (
                    `group_id`, `title`, `total_amount_cents`, `tax_cents`, `tip_cents`, `discount_cents`,
                    `original_currency_code`, `original_amount_cents`, `exchange_rate`,
                    `split_type`, `category_id`, `expense_date`, `created_by_member_id`, `version`, `is_deleted`, `notes`
                ) VALUES (
                    :group_id, :title, :total_amount_cents, :tax_cents, :tip_cents, :discount_cents,
                    :original_currency_code, :original_amount_cents, :exchange_rate,
                    :split_type, :category_id, :expense_date, :created_by_member_id, 1, 0, :notes
                )
            ");

            $expenseStmt->execute([
                ':group_id' => $groupId,
                ':title' => $title,
                ':total_amount_cents' => $totalAmountCents,
                ':tax_cents' => $taxCents,
                ':tip_cents' => $tipCents,
                ':discount_cents' => $discountCents,
                ':original_currency_code' => $originalCurrencyCode ? strtoupper($originalCurrencyCode) : null,
                ':original_amount_cents' => $originalAmountCents,
                ':exchange_rate' => $exchangeRate,
                ':split_type' => strtoupper($splitType),
                ':category_id' => $categoryId,
                ':expense_date' => $expenseDate,
                ':created_by_member_id' => $createdByMemberId,
                ':notes' => $notes,
            ]);

            $expenseId = (int) $pdo->lastInsertId();

            // 3. Batch Insert Payers
            $payerStmt = $pdo->prepare("
                INSERT INTO `expense_payers` (`expense_id`, `member_id`, `amount_paid_cents`)
                VALUES (:expense_id, :member_id, :amount_paid_cents)
            ");

            foreach ($payers as $payer) {
                $payerStmt->execute([
                    ':expense_id' => $expenseId,
                    ':member_id' => (int) $payer['member_id'],
                    ':amount_paid_cents' => (int) $payer['amount_paid_cents'],
                ]);
            }

            // 4. Batch Insert Splits
            $splitStmt = $pdo->prepare("
                INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`, `split_value`)
                VALUES (:expense_id, :member_id, :amount_owed_cents, :split_value)
            ");

            foreach ($splits as $split) {
                $splitStmt->execute([
                    ':expense_id' => $expenseId,
                    ':member_id' => (int) $split['member_id'],
                    ':amount_owed_cents' => (int) $split['amount_owed_cents'],
                    ':split_value' => $split['split_value'] ?? null,
                ]);
            }

            // 5. Record Activity Log
            $this->logRepo->record(
                $groupId,
                $createdByMemberId,
                'EXPENSE_ADDED',
                'expenses',
                $expenseId,
                [
                    'title' => $title,
                    'amount_cents' => $totalAmountCents,
                    'split_type' => $splitType,
                    'category_id' => $categoryId,
                    'original_currency_code' => $originalCurrencyCode,
                    'original_amount_cents' => $originalAmountCents,
                    'exchange_rate' => $exchangeRate,
                    'payers' => $payers,
                    'splits' => $splits,
                ]
            );

            // 6. Record Idempotency Key (if provided)
            if ($trimmedKey !== '') {
                $idempInsertStmt = $pdo->prepare("
                    INSERT INTO `idempotency_keys` (`group_id`, `idempotency_key`, `expense_id`, `request_hash`)
                    VALUES (:group_id, :idempotency_key, :expense_id, :request_hash)
                ");
                $idempInsertStmt->execute([
                    ':group_id' => $groupId,
                    ':idempotency_key' => $trimmedKey,
                    ':expense_id' => $expenseId,
                    ':request_hash' => $requestHash,
                ]);
            }

            return $expenseId;
        });
    }

    /**
     * Atomically update an existing expense and replace its payers and splits inside an ACID transaction.
     *
     * @param int $expenseId
     * @param int $groupId
     * @param string $title
     * @param int $totalAmountCents
     * @param string $splitType
     * @param string $expenseDate
     * @param int|null $categoryId
     * @param array<array{member_id: int, amount_paid_cents: int}> $payers
     * @param array<array{member_id: int, amount_owed_cents: int, split_value: float|int|null}> $splits
     * @return bool
     * @throws Throwable
     */
    public function updateExpense(
        int $expenseId,
        int $groupId,
        string $title,
        int $totalAmountCents,
        string $splitType,
        string $expenseDate,
        ?int $categoryId,
        array $payers,
        array $splits,
        int $taxCents = 0,
        int $tipCents = 0,
        int $discountCents = 0,
        ?string $notes = null,
        ?string $originalCurrencyCode = null,
        ?int $originalAmountCents = null,
        ?float $exchangeRate = null
    ): bool {
        return Database::transaction(function (PDO $pdo) use (
            $expenseId, $groupId, $title, $totalAmountCents, $splitType, $expenseDate,
            $categoryId, $payers, $splits, $taxCents, $tipCents, $discountCents,
            $notes, $originalCurrencyCode, $originalAmountCents, $exchangeRate
        ): bool {
            // 1. Increment Group Version / Acquire Exclusive Row Lock
            $versionStmt = $pdo->prepare("
                UPDATE `groups` SET `version` = `version` + 1 WHERE `id` = :group_id
            ");
            $versionStmt->execute([':group_id' => $groupId]);

            // 2. Update Master Expense Record
            $expenseStmt = $pdo->prepare("
                UPDATE `expenses` SET
                    `title` = :title,
                    `total_amount_cents` = :total_amount_cents,
                    `tax_cents` = :tax_cents,
                    `tip_cents` = :tip_cents,
                    `discount_cents` = :discount_cents,
                    `original_currency_code` = :original_currency_code,
                    `original_amount_cents` = :original_amount_cents,
                    `exchange_rate` = :exchange_rate,
                    `split_type` = :split_type,
                    `category_id` = :category_id,
                    `expense_date` = :expense_date,
                    `notes` = :notes,
                    `version` = `version` + 1
                WHERE `id` = :id AND `group_id` = :group_id AND `is_deleted` = 0
            ");

            $expenseStmt->execute([
                ':id' => $expenseId,
                ':group_id' => $groupId,
                ':title' => $title,
                ':total_amount_cents' => $totalAmountCents,
                ':tax_cents' => $taxCents,
                ':tip_cents' => $tipCents,
                ':discount_cents' => $discountCents,
                ':original_currency_code' => $originalCurrencyCode ? strtoupper($originalCurrencyCode) : null,
                ':original_amount_cents' => $originalAmountCents,
                ':exchange_rate' => $exchangeRate,
                ':split_type' => strtoupper($splitType),
                ':category_id' => $categoryId,
                ':expense_date' => $expenseDate,
                ':notes' => $notes,
            ]);

            // 3. Delete and replace payers
            $delPayers = $pdo->prepare("DELETE FROM `expense_payers` WHERE `expense_id` = :expense_id");
            $delPayers->execute([':expense_id' => $expenseId]);

            $payerStmt = $pdo->prepare("
                INSERT INTO `expense_payers` (`expense_id`, `member_id`, `amount_paid_cents`)
                VALUES (:expense_id, :member_id, :amount_paid_cents)
            ");
            foreach ($payers as $payer) {
                $payerStmt->execute([
                    ':expense_id' => $expenseId,
                    ':member_id' => (int) $payer['member_id'],
                    ':amount_paid_cents' => (int) $payer['amount_paid_cents'],
                ]);
            }

            // 4. Delete and replace splits
            $delSplits = $pdo->prepare("DELETE FROM `expense_splits` WHERE `expense_id` = :expense_id");
            $delSplits->execute([':expense_id' => $expenseId]);

            $splitStmt = $pdo->prepare("
                INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`, `split_value`)
                VALUES (:expense_id, :member_id, :amount_owed_cents, :split_value)
            ");
            foreach ($splits as $split) {
                $splitStmt->execute([
                    ':expense_id' => $expenseId,
                    ':member_id' => (int) $split['member_id'],
                    ':amount_owed_cents' => (int) $split['amount_owed_cents'],
                    ':split_value' => $split['split_value'] ?? null,
                ]);
            }

            // 4. Record Activity Log
            $this->logRepo->record(
                $groupId,
                null,
                'EXPENSE_UPDATED',
                'expenses',
                $expenseId,
                [
                    'title' => $title,
                    'amount_cents' => $totalAmountCents,
                    'split_type' => $splitType,
                    'category_id' => $categoryId,
                    'original_currency_code' => $originalCurrencyCode,
                    'original_amount_cents' => $originalAmountCents,
                ]
            );

            return true;
        });
    }

    /**
     * Fetch all active expenses for a group including payer, debtor, category, and items metadata with optional multi-dimensional filtering.
     *
     * @param int $groupId
     * @param bool $includeDeleted
     * @param array<string, mixed> $filters
     * @return array<array<string, mixed>>
     */
    public function findByGroupId(int $groupId, bool $includeDeleted = false, array $filters = []): array
    {
        $params = [':group_id' => $groupId];
        $whereClauses = ["e.`group_id` = :group_id"];

        if (!$includeDeleted) {
            $whereClauses[] = "e.`is_deleted` = 0";
        }

        if (!empty($filters['search'])) {
            $whereClauses[] = "(e.`title` LIKE :search_title OR e.`notes` LIKE :search_notes)";
            $searchTerm = '%' . trim((string) $filters['search']) . '%';
            $params[':search_title'] = $searchTerm;
            $params[':search_notes'] = $searchTerm;
        }

        if (!empty($filters['category_id'])) {
            $whereClauses[] = "e.`category_id` = :category_id";
            $params[':category_id'] = (int) $filters['category_id'];
        }

        if (!empty($filters['split_type'])) {
            $whereClauses[] = "e.`split_type` = :split_type";
            $params[':split_type'] = strtoupper(trim((string) $filters['split_type']));
        }

        if (!empty($filters['from_date'])) {
            $whereClauses[] = "e.`expense_date` >= :from_date";
            $params[':from_date'] = trim((string) $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $whereClauses[] = "e.`expense_date` <= :to_date";
            $params[':to_date'] = trim((string) $filters['to_date']);
        }

        if (isset($filters['min_amount_cents']) && $filters['min_amount_cents'] !== '') {
            $whereClauses[] = "e.`total_amount_cents` >= :min_amount_cents";
            $params[':min_amount_cents'] = (int) $filters['min_amount_cents'];
        }

        if (isset($filters['max_amount_cents']) && $filters['max_amount_cents'] !== '') {
            $whereClauses[] = "e.`total_amount_cents` <= :max_amount_cents";
            $params[':max_amount_cents'] = (int) $filters['max_amount_cents'];
        }

        if (!empty($filters['payer_id'])) {
            $whereClauses[] = "e.`id` IN (SELECT `expense_id` FROM `expense_payers` WHERE `member_id` = :payer_id)";
            $params[':payer_id'] = (int) $filters['payer_id'];
        }

        if (!empty($filters['debtor_id'])) {
            $whereClauses[] = "e.`id` IN (SELECT `expense_id` FROM `expense_splits` WHERE `member_id` = :debtor_id)";
            $params[':debtor_id'] = (int) $filters['debtor_id'];
        }

        if (!empty($filters['member_id'])) {
            $whereClauses[] = "(e.`id` IN (SELECT `expense_id` FROM `expense_payers` WHERE `member_id` = :member_id_p) OR e.`id` IN (SELECT `expense_id` FROM `expense_splits` WHERE `member_id` = :member_id_d))";
            $params[':member_id_p'] = (int) $filters['member_id'];
            $params[':member_id_d'] = (int) $filters['member_id'];
        }

        $whereSql = implode(' AND ', $whereClauses);

        $sql = "
            SELECT e.`id`, e.`group_id`, e.`title`, e.`total_amount_cents`, e.`tax_cents`, e.`tip_cents`, e.`discount_cents`,
                   e.`original_currency_code`, e.`original_amount_cents`, e.`exchange_rate`, e.`split_type`,
                   e.`category_id`, c.`slug` AS `category_slug`, c.`name` AS `category_name`, c.`icon` AS `category_icon`, c.`color_hex` AS `category_color`,
                   e.`expense_date`, e.`created_by_member_id`, m.`name` AS `creator_name`,
                   e.`version`, e.`is_deleted`, e.`notes`, e.`created_at`, e.`updated_at`
            FROM `expenses` e
            JOIN `members` m ON e.`created_by_member_id` = m.`id`
            LEFT JOIN `categories` c ON e.`category_id` = c.`id`
            WHERE {$whereSql}
            ORDER BY e.`expense_date` DESC, e.`id` DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $expenses = $stmt->fetchAll();

        if (empty($expenses)) {
            return [];
        }

        $expenseIds = array_column($expenses, 'id');
        $inClause = implode(',', array_fill(0, count($expenseIds), '?'));

        // Fetch Payers
        $payersStmt = $this->pdo->prepare("
            SELECT p.`expense_id`, p.`member_id`, m.`name` AS `member_name`, p.`amount_paid_cents`
            FROM `expense_payers` p
            JOIN `members` m ON p.`member_id` = m.`id`
            WHERE p.`expense_id` IN ({$inClause})
        ");
        $payersStmt->execute($expenseIds);
        $allPayers = $payersStmt->fetchAll();

        $payersByExpense = [];
        foreach ($allPayers as $payer) {
            $payersByExpense[$payer['expense_id']][] = [
                'member_id' => (int) $payer['member_id'],
                'member_name' => $payer['member_name'],
                'amount_paid_cents' => (int) $payer['amount_paid_cents'],
            ];
        }

        // Fetch Splits
        $splitsStmt = $this->pdo->prepare("
            SELECT s.`expense_id`, s.`member_id`, m.`name` AS `member_name`, s.`amount_owed_cents`, s.`split_value`
            FROM `expense_splits` s
            JOIN `members` m ON s.`member_id` = m.`id`
            WHERE s.`expense_id` IN ({$inClause})
        ");
        $splitsStmt->execute($expenseIds);
        $allSplits = $splitsStmt->fetchAll();

        $splitsByExpense = [];
        foreach ($allSplits as $split) {
            $splitsByExpense[$split['expense_id']][] = [
                'member_id' => (int) $split['member_id'],
                'member_name' => $split['member_name'],
                'amount_owed_cents' => (int) $split['amount_owed_cents'],
                'split_value' => $split['split_value'] !== null ? (float) $split['split_value'] : null,
            ];
        }

        // Fetch line items for itemized expenses
        $itemRepo = new ExpenseItemRepository($this->pdo);
        $itemsByExpense = [];
        foreach ($expenses as $exp) {
            if ($exp['split_type'] === 'ITEMIZED') {
                $itemsByExpense[$exp['id']] = $itemRepo->findByExpenseId((int) $exp['id']);
            }
        }

        // Fetch Receipts
        $receiptsStmt = $this->pdo->prepare("
            SELECT r.`id`, r.`expense_id`, r.`file_name`, r.`file_path`, r.`file_size_bytes`, r.`mime_type`, r.`created_at`,
                   g.`invite_token`
            FROM `receipt_attachments` r
            JOIN `expenses` e ON r.`expense_id` = e.`id`
            JOIN `groups` g ON e.`group_id` = g.`id`
            WHERE r.`expense_id` IN ({$inClause})
            ORDER BY r.`id` ASC
        ");
        $receiptsStmt->execute($expenseIds);
        $allReceipts = $receiptsStmt->fetchAll();

        $receiptsByExpense = [];
        foreach ($allReceipts as $rec) {
            $token = (string) ($rec['invite_token'] ?? '');
            $receiptsByExpense[$rec['expense_id']][] = [
                'id' => (int) $rec['id'],
                'expense_id' => (int) $rec['expense_id'],
                'file_name' => (string) $rec['file_name'],
                'file_path' => (string) $rec['file_path'],
                'url' => "/api/groups/{$token}/expenses/{$rec['expense_id']}/receipts/{$rec['id']}/download",
                'file_size_bytes' => (int) $rec['file_size_bytes'],
                'mime_type' => (string) $rec['mime_type'],
                'is_image' => str_starts_with((string) $rec['mime_type'], 'image/'),
                'created_at' => (string) $rec['created_at'],
            ];
        }

        // Merge Payers, Splits, Items, and Receipts into Expense Objects
        return array_map(function (array $exp) use ($payersByExpense, $splitsByExpense, $itemsByExpense, $receiptsByExpense) {
            $id = $exp['id'];
            $receiptsList = $receiptsByExpense[$id] ?? [];
            return [
                'id' => (int) $exp['id'],
                'group_id' => (int) $exp['group_id'],
                'title' => $exp['title'],
                'total_amount_cents' => (int) $exp['total_amount_cents'],
                'amount_cents' => (int) $exp['total_amount_cents'],
                'tax_cents' => (int) ($exp['tax_cents'] ?? 0),
                'tip_cents' => (int) ($exp['tip_cents'] ?? 0),
                'discount_cents' => (int) ($exp['discount_cents'] ?? 0),
                'original_currency_code' => $exp['original_currency_code'] ?? null,
                'original_amount_cents' => $exp['original_amount_cents'] !== null ? (int) $exp['original_amount_cents'] : null,
                'exchange_rate' => $exp['exchange_rate'] !== null ? (float) $exp['exchange_rate'] : null,
                'split_type' => $exp['split_type'],
                'category' => $exp['category_id'] ? [
                    'id' => (int) $exp['category_id'],
                    'slug' => (string) ($exp['category_slug'] ?? ''),
                    'name' => (string) $exp['category_name'],
                    'icon' => (string) $exp['category_icon'],
                    'color' => (string) $exp['category_color'],
                ] : null,
                'expense_date' => $exp['expense_date'],
                'created_by' => [
                    'id' => (int) $exp['created_by_member_id'],
                    'name' => $exp['creator_name'],
                ],
                'payers' => $payersByExpense[$id] ?? [],
                'splits' => $splitsByExpense[$id] ?? [],
                'items' => $itemsByExpense[$id] ?? [],
                'receipts' => $receiptsList,
                'receipt_count' => count($receiptsList),
                'notes' => $exp['notes'] ?? null,
                'version' => (int) $exp['version'],
                'is_deleted' => (bool) $exp['is_deleted'],
                'created_at' => $exp['created_at'],
                'updated_at' => $exp['updated_at'],
            ];
        }, $expenses);
    }

    /**
     * Find detailed expense record by ID.
     *
     * @param int $expenseId
     * @return array<string, mixed>|null
     */
    public function findDetailsById(int $expenseId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT e.`id`, e.`group_id`, e.`title`, e.`total_amount_cents`, e.`tax_cents`, e.`tip_cents`, e.`discount_cents`,
                   e.`original_currency_code`, e.`original_amount_cents`, e.`exchange_rate`, e.`split_type`,
                   e.`category_id`, c.`slug` AS `category_slug`, c.`name` AS `category_name`, c.`icon` AS `category_icon`, c.`color_hex` AS `category_color`,
                   e.`expense_date`, e.`created_by_member_id`, m.`name` AS `creator_name`,
                   e.`version`, e.`is_deleted`, e.`notes`, e.`created_at`, e.`updated_at`
            FROM `expenses` e
            JOIN `members` m ON e.`created_by_member_id` = m.`id`
            LEFT JOIN `categories` c ON e.`category_id` = c.`id`
            WHERE e.`id` = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $expenseId]);
        $expense = $stmt->fetch();

        if (!$expense) {
            return null;
        }

        // Fetch Payers
        $payerStmt = $this->pdo->prepare("
            SELECT p.`member_id`, m.`name` AS `member_name`, p.`amount_paid_cents`
            FROM `expense_payers` p
            JOIN `members` m ON p.`member_id` = m.`id`
            WHERE p.`expense_id` = :id
        ");
        $payerStmt->execute([':id' => $expenseId]);
        $payers = $payerStmt->fetchAll();

        // Fetch Splits
        $splitStmt = $this->pdo->prepare("
            SELECT s.`member_id`, m.`name` AS `member_name`, s.`amount_owed_cents`, s.`split_value`
            FROM `expense_splits` s
            JOIN `members` m ON s.`member_id` = m.`id`
            WHERE s.`expense_id` = :id
        ");
        $splitStmt->execute([':id' => $expenseId]);
        $splits = $splitStmt->fetchAll();

        // Fetch Items if itemized
        $items = [];
        if ($expense['split_type'] === 'ITEMIZED') {
            $itemRepo = new ExpenseItemRepository($this->pdo);
            $items = $itemRepo->findByExpenseId($expenseId);
        }

        // Fetch Receipts
        $receiptStmt = $this->pdo->prepare("
            SELECT r.`id`, r.`expense_id`, r.`file_name`, r.`file_path`, r.`file_size_bytes`, r.`mime_type`, r.`created_at`,
                   g.`invite_token`
            FROM `receipt_attachments` r
            JOIN `expenses` e ON r.`expense_id` = e.`id`
            JOIN `groups` g ON e.`group_id` = g.`id`
            WHERE r.`expense_id` = :id
            ORDER BY r.`id` ASC
        ");
        $receiptStmt->execute([':id' => $expenseId]);
        $rawReceipts = $receiptStmt->fetchAll();
        $receipts = array_map(function (array $rec) {
            $token = (string) ($rec['invite_token'] ?? '');
            return [
                'id' => (int) $rec['id'],
                'expense_id' => (int) $rec['expense_id'],
                'file_name' => (string) $rec['file_name'],
                'file_path' => (string) $rec['file_path'],
                'url' => "/api/groups/{$token}/expenses/{$rec['expense_id']}/receipts/{$rec['id']}/download",
                'file_size_bytes' => (int) $rec['file_size_bytes'],
                'mime_type' => (string) $rec['mime_type'],
                'is_image' => str_starts_with((string) $rec['mime_type'], 'image/'),
                'created_at' => (string) $rec['created_at'],
            ];
        }, $rawReceipts);

        return [
            'id' => (int) $expense['id'],
            'group_id' => (int) $expense['group_id'],
            'title' => $expense['title'],
            'total_amount_cents' => (int) $expense['total_amount_cents'],
            'amount_cents' => (int) $expense['total_amount_cents'],
            'tax_cents' => (int) ($expense['tax_cents'] ?? 0),
            'tip_cents' => (int) ($expense['tip_cents'] ?? 0),
            'discount_cents' => (int) ($expense['discount_cents'] ?? 0),
            'original_currency_code' => $expense['original_currency_code'] ?? null,
            'original_amount_cents' => $expense['original_amount_cents'] !== null ? (int) $expense['original_amount_cents'] : null,
            'exchange_rate' => $expense['exchange_rate'] !== null ? (float) $expense['exchange_rate'] : null,
            'split_type' => $expense['split_type'],
            'category' => $expense['category_id'] ? [
                'id' => (int) $expense['category_id'],
                'slug' => (string) ($expense['category_slug'] ?? ''),
                'name' => (string) $expense['category_name'],
                'icon' => (string) $expense['category_icon'],
                'color' => (string) $expense['category_color'],
            ] : null,
            'expense_date' => $expense['expense_date'],
            'created_by' => [
                'id' => (int) $expense['created_by_member_id'],
                'name' => $expense['creator_name'],
            ],
            'payers' => array_map(function (array $p) {
                return [
                    'member_id' => (int) $p['member_id'],
                    'member_name' => $p['member_name'],
                    'amount_paid_cents' => (int) $p['amount_paid_cents'],
                ];
            }, $payers),
            'splits' => array_map(function (array $s) {
                return [
                    'member_id' => (int) $s['member_id'],
                    'member_name' => $s['member_name'],
                    'amount_owed_cents' => (int) $s['amount_owed_cents'],
                    'split_value' => $s['split_value'] !== null ? (float) $s['split_value'] : null,
                ];
            }, $splits),
            'items' => $items,
            'receipts' => $receipts,
            'receipt_count' => count($receipts),
            'notes' => $expense['notes'] ?? null,
            'version' => (int) $expense['version'],
            'is_deleted' => (bool) $expense['is_deleted'],
            'created_at' => $expense['created_at'],
            'updated_at' => $expense['updated_at'],
        ];
    }

    /**
     * Soft-delete an expense by setting is_deleted = 1 atomically.
     *
     * @param int $expenseId
     * @param int $groupId
     * @return bool
     */
    public function softDelete(int $expenseId, int $groupId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE `expenses`
            SET `is_deleted` = 1, `version` = `version` + 1
            WHERE `id` = :id AND `group_id` = :group_id AND `is_deleted` = 0
        ");

        $stmt->execute([
            ':id' => $expenseId,
            ':group_id' => $groupId,
        ]);

        $affected = $stmt->rowCount();

        if ($affected > 0) {
            $this->logRepo->record(
                $groupId,
                null,
                'EXPENSE_DELETED',
                'expenses',
                $expenseId,
                ['expense_id' => $expenseId]
            );

            // Increment Group Version for OCC
            $versionStmt = $this->pdo->prepare("
                UPDATE `groups` SET `version` = `version` + 1 WHERE `id` = :group_id
            ");
            $versionStmt->execute([':group_id' => $groupId]);

            return true;
        }

        return false;
    }

    /**
     * Fetch all soft-deleted expenses for a group (Trash Bin).
     *
     * @param int $groupId
     * @return array<array<string, mixed>>
     */
    public function getDeletedByGroupId(int $groupId): array
    {
        $sql = "
            SELECT e.`id`, e.`group_id`, e.`title`, e.`total_amount_cents`, e.`tax_cents`, e.`tip_cents`, e.`discount_cents`,
                   e.`original_currency_code`, e.`original_amount_cents`, e.`exchange_rate`, e.`split_type`,
                   e.`category_id`, c.`slug` AS `category_slug`, c.`name` AS `category_name`, c.`icon` AS `category_icon`, c.`color_hex` AS `category_color`,
                   e.`expense_date`, e.`created_by_member_id`, m.`name` AS `creator_name`,
                   e.`version`, e.`is_deleted`, e.`notes`, e.`created_at`, e.`updated_at`
            FROM `expenses` e
            JOIN `members` m ON e.`created_by_member_id` = m.`id`
            LEFT JOIN `categories` c ON e.`category_id` = c.`id`
            WHERE e.`group_id` = :group_id AND e.`is_deleted` = 1
            ORDER BY e.`updated_at` DESC, e.`id` DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':group_id' => $groupId]);
        $expenses = $stmt->fetchAll();

        if (empty($expenses)) {
            return [];
        }

        $expenseIds = array_column($expenses, 'id');
        $inClause = implode(',', array_fill(0, count($expenseIds), '?'));

        // Fetch Payers
        $payersStmt = $this->pdo->prepare("
            SELECT p.`expense_id`, p.`member_id`, m.`name` AS `member_name`, p.`amount_paid_cents`
            FROM `expense_payers` p
            JOIN `members` m ON p.`member_id` = m.`id`
            WHERE p.`expense_id` IN ({$inClause})
        ");
        $payersStmt->execute($expenseIds);
        $allPayers = $payersStmt->fetchAll();

        $payersByExpense = [];
        foreach ($allPayers as $payer) {
            $payersByExpense[$payer['expense_id']][] = [
                'member_id' => (int) $payer['member_id'],
                'member_name' => $payer['member_name'],
                'amount_paid_cents' => (int) $payer['amount_paid_cents'],
            ];
        }

        // Fetch Splits
        $splitsStmt = $this->pdo->prepare("
            SELECT s.`expense_id`, s.`member_id`, m.`name` AS `member_name`, s.`amount_owed_cents`, s.`split_value`
            FROM `expense_splits` s
            JOIN `members` m ON s.`member_id` = m.`id`
            WHERE s.`expense_id` IN ({$inClause})
        ");
        $splitsStmt->execute($expenseIds);
        $allSplits = $splitsStmt->fetchAll();

        $splitsByExpense = [];
        foreach ($allSplits as $split) {
            $splitsByExpense[$split['expense_id']][] = [
                'member_id' => (int) $split['member_id'],
                'member_name' => $split['member_name'],
                'amount_owed_cents' => (int) $split['amount_owed_cents'],
                'split_value' => $split['split_value'] !== null ? (float) $split['split_value'] : null,
            ];
        }

        // Fetch line items for itemized expenses
        $itemRepo = new ExpenseItemRepository($this->pdo);
        $itemsByExpense = [];
        foreach ($expenses as $exp) {
            if ($exp['split_type'] === 'ITEMIZED') {
                $itemsByExpense[$exp['id']] = $itemRepo->findByExpenseId((int) $exp['id']);
            }
        }

        // Fetch Receipts
        $receiptsStmt = $this->pdo->prepare("
            SELECT r.`id`, r.`expense_id`, r.`file_name`, r.`file_path`, r.`file_size_bytes`, r.`mime_type`, r.`created_at`,
                   g.`invite_token`
            FROM `receipt_attachments` r
            JOIN `expenses` e ON r.`expense_id` = e.`id`
            JOIN `groups` g ON e.`group_id` = g.`id`
            WHERE r.`expense_id` IN ({$inClause})
            ORDER BY r.`id` ASC
        ");
        $receiptsStmt->execute($expenseIds);
        $allReceipts = $receiptsStmt->fetchAll();

        $receiptsByExpense = [];
        foreach ($allReceipts as $rec) {
            $token = (string) ($rec['invite_token'] ?? '');
            $receiptsByExpense[$rec['expense_id']][] = [
                'id' => (int) $rec['id'],
                'expense_id' => (int) $rec['expense_id'],
                'file_name' => (string) $rec['file_name'],
                'file_path' => (string) $rec['file_path'],
                'url' => "/api/groups/{$token}/expenses/{$rec['expense_id']}/receipts/{$rec['id']}/download",
                'file_size_bytes' => (int) $rec['file_size_bytes'],
                'mime_type' => (string) $rec['mime_type'],
                'is_image' => str_starts_with((string) $rec['mime_type'], 'image/'),
                'created_at' => (string) $rec['created_at'],
            ];
        }

        return array_map(function (array $exp) use ($payersByExpense, $splitsByExpense, $itemsByExpense, $receiptsByExpense) {
            $id = $exp['id'];
            $receiptsList = $receiptsByExpense[$id] ?? [];
            return [
                'id' => (int) $exp['id'],
                'group_id' => (int) $exp['group_id'],
                'title' => $exp['title'],
                'total_amount_cents' => (int) $exp['total_amount_cents'],
                'amount_cents' => (int) $exp['total_amount_cents'],
                'tax_cents' => (int) ($exp['tax_cents'] ?? 0),
                'tip_cents' => (int) ($exp['tip_cents'] ?? 0),
                'discount_cents' => (int) ($exp['discount_cents'] ?? 0),
                'original_currency_code' => $exp['original_currency_code'] ?? null,
                'original_amount_cents' => $exp['original_amount_cents'] !== null ? (int) $exp['original_amount_cents'] : null,
                'exchange_rate' => $exp['exchange_rate'] !== null ? (float) $exp['exchange_rate'] : null,
                'split_type' => $exp['split_type'],
                'category' => $exp['category_id'] ? [
                    'id' => (int) $exp['category_id'],
                    'slug' => (string) ($exp['category_slug'] ?? ''),
                    'name' => (string) $exp['category_name'],
                    'icon' => (string) $exp['category_icon'],
                    'color' => (string) $exp['category_color'],
                ] : null,
                'expense_date' => $exp['expense_date'],
                'created_by' => [
                    'id' => (int) $exp['created_by_member_id'],
                    'name' => $exp['creator_name'],
                ],
                'payers' => $payersByExpense[$id] ?? [],
                'splits' => $splitsByExpense[$id] ?? [],
                'items' => $itemsByExpense[$id] ?? [],
                'receipts' => $receiptsList,
                'receipt_count' => count($receiptsList),
                'notes' => $exp['notes'] ?? null,
                'version' => (int) $exp['version'],
                'is_deleted' => true,
                'created_at' => $exp['created_at'],
                'updated_at' => $exp['updated_at'],
            ];
        }, $expenses);
    }

    /**
     * Restore a soft-deleted expense by setting is_deleted = 0 atomically.
     *
     * @param int $expenseId
     * @param int $groupId
     * @return bool
     */
    public function restoreExpense(int $expenseId, int $groupId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE `expenses`
            SET `is_deleted` = 0, `version` = `version` + 1
            WHERE `id` = :id AND `group_id` = :group_id AND `is_deleted` = 1
        ");

        $stmt->execute([
            ':id' => $expenseId,
            ':group_id' => $groupId,
        ]);

        $affected = $stmt->rowCount();

        if ($affected > 0) {
            $this->logRepo->record(
                $groupId,
                null,
                'EXPENSE_RESTORED',
                'expenses',
                $expenseId,
                ['expense_id' => $expenseId]
            );

            // Increment Group Version for OCC
            $versionStmt = $this->pdo->prepare("
                UPDATE `groups` SET `version` = `version` + 1 WHERE `id` = :group_id
            ");
            $versionStmt->execute([':group_id' => $groupId]);

            return true;
        }

        return false;
    }
}
