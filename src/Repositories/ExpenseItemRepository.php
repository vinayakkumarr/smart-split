<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Repository for managing itemized expense line items and member assignments.
 */
class ExpenseItemRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Batch insert line items and member assignments for an expense.
     *
     * @param int $expenseId
     * @param array<array{name: string, amount_cents: int, sort_order?: int, member_ids: array<int>}> $items
     * @return void
     * @throws Throwable
     */
    public function saveItems(int $expenseId, array $items): void
    {
        Database::transaction(function (PDO $pdo) use ($expenseId, $items): void {
            // Delete existing line items for clean replacement
            $delStmt = $pdo->prepare("DELETE FROM `expense_items` WHERE `expense_id` = :expense_id");
            $delStmt->execute([':expense_id' => $expenseId]);

            $itemStmt = $pdo->prepare("
                INSERT INTO `expense_items` (`expense_id`, `name`, `amount_cents`, `sort_order`)
                VALUES (:expense_id, :name, :amount_cents, :sort_order)
            ");

            $assignStmt = $pdo->prepare("
                INSERT INTO `expense_item_assignments` (`item_id`, `member_id`, `amount_owed_cents`)
                VALUES (:item_id, :member_id, :amount_owed_cents)
            ");

            foreach ($items as $index => $item) {
                $name = trim((string) $item['name']);
                $amountCents = (int) $item['amount_cents'];
                $sortOrder = (int) ($item['sort_order'] ?? $index);

                $itemStmt->execute([
                    ':expense_id' => $expenseId,
                    ':name' => $name,
                    ':amount_cents' => $amountCents,
                    ':sort_order' => $sortOrder,
                ]);

                $itemId = (int) $pdo->lastInsertId();
                $memberIds = array_values(array_unique(array_map('intval', $item['member_ids'] ?? [])));

                if (!empty($memberIds)) {
                    $count = count($memberIds);
                    $basePerMember = (int) floor($amountCents / $count);
                    $rem = $amountCents % $count;

                    foreach ($memberIds as $mIdx => $mId) {
                        $extra = ($mIdx < $rem) ? 1 : 0;
                        $assignStmt->execute([
                            ':item_id' => $itemId,
                            ':member_id' => $mId,
                            ':amount_owed_cents' => $basePerMember + $extra,
                        ]);
                    }
                }
            }
        });
    }

    /**
     * Fetch all line items with assigned members for a given expense.
     *
     * @param int $expenseId
     * @return array<array<string, mixed>>
     */
    public function findByExpenseId(int $expenseId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT i.`id`, i.`name`, i.`amount_cents`, i.`sort_order`,
                   a.`member_id`, m.`name` AS `member_name`, a.`amount_owed_cents`
            FROM `expense_items` i
            LEFT JOIN `expense_item_assignments` a ON i.`id` = a.`item_id`
            LEFT JOIN `members` m ON a.`member_id` = m.`id`
            WHERE i.`expense_id` = :expense_id
            ORDER BY i.`sort_order` ASC, i.`id` ASC
        ");
        $stmt->execute([':expense_id' => $expenseId]);
        $rows = $stmt->fetchAll();

        $items = [];
        foreach ($rows as $r) {
            $itemId = (int) $r['id'];
            if (!isset($items[$itemId])) {
                $items[$itemId] = [
                    'id' => $itemId,
                    'name' => (string) $r['name'],
                    'amount_cents' => (int) $r['amount_cents'],
                    'sort_order' => (int) $r['sort_order'],
                    'assigned_members' => [],
                ];
            }

            if ($r['member_id'] !== null) {
                $items[$itemId]['assigned_members'][] = [
                    'member_id' => (int) $r['member_id'],
                    'member_name' => (string) $r['member_name'],
                    'amount_owed_cents' => (int) $r['amount_owed_cents'],
                ];
            }
        }

        return array_values($items);
    }
}
