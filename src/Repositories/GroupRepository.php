<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Data Access Repository for Groups.
 */
class GroupRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Create a new Group record with unique UUID, 64-char invite token, and optional owner.
     *
     * @param string $name
     * @param string $currencyCode
     * @param int|null $ownerUserId
     * @return array<string, mixed>
     */
    public function create(string $name, string $currencyCode = 'INR', ?int $ownerUserId = null): array
    {
        $uuid = $this->generateUuidV4();
        $inviteToken = bin2hex(random_bytes(32));

        $stmt = $this->pdo->prepare("
            INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`, `owner_user_id`, `version`)
            VALUES (:uuid, :name, :currency_code, :invite_token, :owner_user_id, 1)
        ");

        $stmt->execute([
            ':uuid' => $uuid,
            ':name' => $name,
            ':currency_code' => strtoupper($currencyCode),
            ':invite_token' => $inviteToken,
            ':owner_user_id' => $ownerUserId,
        ]);

        $groupId = (int) $this->pdo->lastInsertId();

        return [
            'id' => $groupId,
            'uuid' => $uuid,
            'name' => $name,
            'currency_code' => strtoupper($currencyCode),
            'invite_token' => $inviteToken,
            'owner_user_id' => $ownerUserId,
            'version' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Find a group by its 64-character invite token.
     *
     * @param string $inviteToken
     * @return array<string, mixed>|null
     */
    public function findByInviteToken(string $inviteToken): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT `id`, `uuid`, `name`, `currency_code`, `invite_token`, `owner_user_id`, `version`, `created_at`, `updated_at`
            FROM `groups`
            WHERE `invite_token` = :invite_token
            LIMIT 1
        ");

        $stmt->execute([':invite_token' => $inviteToken]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Find a group by its primary ID.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT `id`, `uuid`, `name`, `currency_code`, `invite_token`, `owner_user_id`, `version`, `created_at`, `updated_at`
            FROM `groups`
            WHERE `id` = :id
            LIMIT 1
        ");

        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Increment group version for Optimistic Concurrency Control (OCC).
     *
     * @param int $groupId
     * @return void
     */
    public function incrementVersion(int $groupId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE `groups`
            SET `version` = `version` + 1
            WHERE `id` = :id
        ");
        $stmt->execute([':id' => $groupId]);
    }

    /**
     * Permanently delete a workspace and all associated records with clean file and foreign key cascade.
     *
     * @param int $groupId
     * @return bool
     */
    public function delete(int $groupId): bool
    {
        // 1. Fetch physical receipt files to unlink from disk
        try {
            $stmtReceipts = $this->pdo->prepare("
                SELECT r.`file_path`
                FROM `receipt_attachments` r
                JOIN `expenses` e ON r.`expense_id` = e.`id`
                WHERE e.`group_id` = :group_id
            ");
            $stmtReceipts->execute([':group_id' => $groupId]);
            $receipts = $stmtReceipts->fetchAll(PDO::FETCH_ASSOC);

            $storageDir = dirname(__DIR__, 2) . '/storage/receipts';
            foreach ($receipts as $r) {
                if (!empty($r['file_path'])) {
                    $baseFileName = basename((string) $r['file_path']);
                    $fullPath = $storageDir . '/' . $baseFileName;
                    if (file_exists($fullPath) && is_file($fullPath)) {
                        @unlink($fullPath);
                    } else {
                        $altPath = dirname(__DIR__, 2) . '/' . ltrim((string) $r['file_path'], '/');
                        if (file_exists($altPath) && is_file($altPath)) {
                            @unlink($altPath);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently continue if receipt lookup fails
        }

        // 2. Cascade delete in reverse dependency order
        $this->pdo->beginTransaction();
        try {
            // Delete expense items assignments & items
            $this->pdo->prepare("
                DELETE eia FROM `expense_item_assignments` eia
                JOIN `expense_items` ei ON eia.`item_id` = ei.`id`
                JOIN `expenses` e ON ei.`expense_id` = e.`id`
                WHERE e.`group_id` = :group_id
            ")->execute([':group_id' => $groupId]);

            $this->pdo->prepare("
                DELETE ei FROM `expense_items` ei
                JOIN `expenses` e ON ei.`expense_id` = e.`id`
                WHERE e.`group_id` = :group_id
            ")->execute([':group_id' => $groupId]);

            // Delete receipts
            $this->pdo->prepare("
                DELETE r FROM `receipt_attachments` r
                JOIN `expenses` e ON r.`expense_id` = e.`id`
                WHERE e.`group_id` = :group_id
            ")->execute([':group_id' => $groupId]);

            // Delete expense payers & splits
            $this->pdo->prepare("
                DELETE p FROM `expense_payers` p
                JOIN `expenses` e ON p.`expense_id` = e.`id`
                WHERE e.`group_id` = :group_id
            ")->execute([':group_id' => $groupId]);

            $this->pdo->prepare("
                DELETE s FROM `expense_splits` s
                JOIN `expenses` e ON s.`expense_id` = e.`id`
                WHERE e.`group_id` = :group_id
            ")->execute([':group_id' => $groupId]);

            // Delete expenses
            $this->pdo->prepare("DELETE FROM `expenses` WHERE `group_id` = :group_id")->execute([':group_id' => $groupId]);

            // Delete settlements
            $this->pdo->prepare("DELETE FROM `settlements` WHERE `group_id` = :group_id")->execute([':group_id' => $groupId]);

            // Delete recurring rules and templates
            $this->pdo->prepare("DELETE FROM `recurring_rules` WHERE `group_id` = :group_id")->execute([':group_id' => $groupId]);
            $this->pdo->prepare("DELETE FROM `expense_templates` WHERE `group_id` = :group_id")->execute([':group_id' => $groupId]);

            // Delete activity logs
            $this->pdo->prepare("DELETE FROM `activity_logs` WHERE `group_id` = :group_id")->execute([':group_id' => $groupId]);

            // Delete custom categories
            $this->pdo->prepare("DELETE FROM `categories` WHERE `group_id` = :group_id")->execute([':group_id' => $groupId]);

            // Delete members
            $this->pdo->prepare("DELETE FROM `members` WHERE `group_id` = :group_id")->execute([':group_id' => $groupId]);

            // Delete group
            $stmt = $this->pdo->prepare("DELETE FROM `groups` WHERE `id` = :id");
            $stmt->execute([':id' => $groupId]);

            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Retrieve consolidated workspace dataset (group, members, balances, settlement plan, expenses, settlements)
     * in minimal indexed queries with in-memory zero-sum balance and settlement graph calculation.
     *
     * @param string $inviteToken
     * @return array<string, mixed>|null
     */
    public function getWorkspaceData(string $inviteToken): ?array
    {
        // 1. Group Record
        $group = $this->findByInviteToken($inviteToken);
        if (!$group) {
            return null;
        }

        $groupId = (int) $group['id'];
        $currencyCode = (string) ($group['currency_code'] ?? 'INR');

        // 2. Members Record
        $memberStmt = $this->pdo->prepare("
            SELECT m.`id`, m.`group_id`, m.`user_id`, m.`name`, m.`color_hex`, m.`email`, m.`member_token`, m.`is_active`, m.`created_at`,
                   m.`upi_id` AS `member_upi_id`,
                   u.`upi_id` AS `user_upi_id`,
                   COALESCE(u.`upi_id`, m.`upi_id`) AS `upi_id`
            FROM `members` m
            LEFT JOIN `users` u ON m.`user_id` = u.`id`
            WHERE m.`group_id` = :group_id
            ORDER BY m.`id` ASC
        ");
        $memberStmt->execute([':group_id' => $groupId]);
        $allMembers = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

        $members = array_values(array_filter($allMembers, fn($m) => (int) $m['is_active'] === 1));
        $creatorMember = $allMembers[0] ?? null;
        $creatorMemberId = $creatorMember ? (int) $creatorMember['id'] : (!empty($members) ? (int) $members[0]['id'] : null);

        // Prepare member lookup map for balance accumulation
        $memberMap = [];
        foreach ($members as $m) {
            $mId = (int) $m['id'];
            $memberMap[$mId] = [
                'member_id' => $mId,
                'name' => $m['name'],
                'upi_id' => $m['upi_id'] ?? null,
                'total_paid_cents' => 0,
                'total_owed_cents' => 0,
                'settlements_sent_cents' => 0,
                'settlements_received_cents' => 0,
                'net_balance_cents' => 0,
                'status' => 'SETTLED',
            ];
        }

        // 3. Active Expenses
        $expStmt = $this->pdo->prepare("
            SELECT e.`id`, e.`group_id`, e.`title`, e.`total_amount_cents`, e.`tax_cents`, e.`tip_cents`, e.`discount_cents`,
                   e.`original_currency_code`, e.`original_amount_cents`, e.`exchange_rate`, e.`split_type`,
                   e.`category_id`, c.`slug` AS `category_slug`, c.`name` AS `category_name`, c.`icon` AS `category_icon`, c.`color_hex` AS `category_color`,
                   e.`expense_date`, e.`created_by_member_id`, m.`name` AS `creator_name`,
                   e.`version`, e.`is_deleted`, e.`notes`, e.`created_at`, e.`updated_at`
            FROM `expenses` e
            JOIN `members` m ON e.`created_by_member_id` = m.`id`
            LEFT JOIN `categories` c ON e.`category_id` = c.`id`
            WHERE e.`group_id` = :group_id AND e.`is_deleted` = 0
            ORDER BY e.`expense_date` DESC, e.`id` DESC
        ");
        $expStmt->execute([':group_id' => $groupId]);
        $rawExpenses = $expStmt->fetchAll(PDO::FETCH_ASSOC);

        $payersByExpense = [];
        $splitsByExpense = [];
        $itemsByExpense = [];
        $receiptsByExpense = [];

        if (!empty($rawExpenses)) {
            // 3a. Payers
            $payerStmt = $this->pdo->prepare("
                SELECT p.`expense_id`, p.`member_id`, m.`name` AS `member_name`, p.`amount_paid_cents`
                FROM `expense_payers` p
                JOIN `expenses` e ON p.`expense_id` = e.`id`
                JOIN `members` m ON p.`member_id` = m.`id`
                WHERE e.`group_id` = :group_id AND e.`is_deleted` = 0
            ");
            $payerStmt->execute([':group_id' => $groupId]);
            $allPayers = $payerStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($allPayers as $payer) {
                $expId = (int) $payer['expense_id'];
                $mId = (int) $payer['member_id'];
                $paidCents = (int) $payer['amount_paid_cents'];

                $payersByExpense[$expId][] = [
                    'member_id' => $mId,
                    'member_name' => $payer['member_name'],
                    'amount_paid_cents' => $paidCents,
                ];

                if (isset($memberMap[$mId])) {
                    $memberMap[$mId]['total_paid_cents'] += $paidCents;
                }
            }

            // 3b. Splits
            $splitStmt = $this->pdo->prepare("
                SELECT s.`expense_id`, s.`member_id`, m.`name` AS `member_name`, s.`amount_owed_cents`, s.`split_value`
                FROM `expense_splits` s
                JOIN `expenses` e ON s.`expense_id` = e.`id`
                JOIN `members` m ON s.`member_id` = m.`id`
                WHERE e.`group_id` = :group_id AND e.`is_deleted` = 0
            ");
            $splitStmt->execute([':group_id' => $groupId]);
            $allSplits = $splitStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($allSplits as $split) {
                $expId = (int) $split['expense_id'];
                $mId = (int) $split['member_id'];
                $owedCents = (int) $split['amount_owed_cents'];

                $splitsByExpense[$expId][] = [
                    'member_id' => $mId,
                    'member_name' => $split['member_name'],
                    'amount_owed_cents' => $owedCents,
                    'split_value' => $split['split_value'] !== null ? (float) $split['split_value'] : null,
                ];

                if (isset($memberMap[$mId])) {
                    $memberMap[$mId]['total_owed_cents'] += $owedCents;
                }
            }

            // 3c. Itemized Line Items (if any)
            $hasItemized = false;
            foreach ($rawExpenses as $exp) {
                if ($exp['split_type'] === 'ITEMIZED') {
                    $hasItemized = true;
                    break;
                }
            }

            if ($hasItemized) {
                $itemStmt = $this->pdo->prepare("
                    SELECT ei.`id`, ei.`expense_id`, ei.`name`, ei.`amount_cents`, ei.`created_at`
                    FROM `expense_items` ei
                    JOIN `expenses` e ON ei.`expense_id` = e.`id`
                    WHERE e.`group_id` = :group_id AND e.`is_deleted` = 0 AND e.`split_type` = 'ITEMIZED'
                    ORDER BY ei.`id` ASC
                ");
                $itemStmt->execute([':group_id' => $groupId]);
                $allItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

                $assignStmt = $this->pdo->prepare("
                    SELECT eia.`item_id`, eia.`member_id`, m.`name` AS `member_name`
                    FROM `expense_item_assignments` eia
                    JOIN `expense_items` ei ON eia.`item_id` = ei.`id`
                    JOIN `expenses` e ON ei.`expense_id` = e.`id`
                    JOIN `members` m ON eia.`member_id` = m.`id`
                    WHERE e.`group_id` = :group_id AND e.`is_deleted` = 0
                ");
                $assignStmt->execute([':group_id' => $groupId]);
                $allAssignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

                $assignmentsByItem = [];
                foreach ($allAssignments as $assign) {
                    $itemId = (int) $assign['item_id'];
                    $assignmentsByItem[$itemId][] = [
                        'member_id' => (int) $assign['member_id'],
                        'member_name' => $assign['member_name'],
                    ];
                }

                foreach ($allItems as $item) {
                    $expId = (int) $item['expense_id'];
                    $itemId = (int) $item['id'];
                    $itemsByExpense[$expId][] = [
                        'id' => $itemId,
                        'expense_id' => $expId,
                        'name' => (string) $item['name'],
                        'amount_cents' => (int) $item['amount_cents'],
                        'assigned_members' => $assignmentsByItem[$itemId] ?? [],
                        'created_at' => $item['created_at'],
                    ];
                }
            }

            // 3d. Receipts
            $receiptsStmt = $this->pdo->prepare("
                SELECT r.`id`, r.`expense_id`, r.`file_name`, r.`file_path`, r.`file_size_bytes`, r.`mime_type`, r.`created_at`
                FROM `receipt_attachments` r
                JOIN `expenses` e ON r.`expense_id` = e.`id`
                WHERE e.`group_id` = :group_id AND e.`is_deleted` = 0
                ORDER BY r.`id` ASC
            ");
            $receiptsStmt->execute([':group_id' => $groupId]);
            $allReceipts = $receiptsStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($allReceipts as $rec) {
                $expId = (int) $rec['expense_id'];
                $receiptsByExpense[$expId][] = [
                    'id' => (int) $rec['id'],
                    'expense_id' => $expId,
                    'file_name' => (string) $rec['file_name'],
                    'file_path' => (string) $rec['file_path'],
                    'url' => "/api/groups/{$group['invite_token']}/expenses/{$expId}/receipts/{$rec['id']}/download",
                    'file_size_bytes' => (int) $rec['file_size_bytes'],
                    'mime_type' => (string) $rec['mime_type'],
                    'is_image' => str_starts_with((string) $rec['mime_type'], 'image/'),
                    'created_at' => (string) $rec['created_at'],
                ];
            }
        }

        // 4. Settlements
        $settleStmt = $this->pdo->prepare("
            SELECT s.`id`, s.`group_id`, s.`payer_member_id`, p.`name` AS `payer_name`,
                   s.`payee_member_id`, r.`name` AS `payee_name`, s.`amount_cents`,
                   s.`status`, s.`recorded_by_member_id`, rec.`name` AS `recorded_by_name`,
                   s.`payment_method`, s.`reference_id`,
                   s.`settled_date`, s.`notes`, s.`confirmed_by_member_id`, conf.`name` AS `confirmed_by_name`,
                   s.`confirmed_at`, s.`disputed_by_member_id`, disp.`name` AS `disputed_by_name`,
                   s.`disputed_at`, s.`dispute_reason`, s.`reversed_by_member_id`, rev.`name` AS `reversed_by_name`,
                   s.`reversed_at`, s.`reversal_reason`, s.`reversal_of_id`,
                   s.`is_deleted`, s.`created_at`
            FROM `settlements` s
            JOIN `members` p ON s.`payer_member_id` = p.`id`
            JOIN `members` r ON s.`payee_member_id` = r.`id`
            LEFT JOIN `members` rec ON s.`recorded_by_member_id` = rec.`id`
            LEFT JOIN `members` conf ON s.`confirmed_by_member_id` = conf.`id`
            LEFT JOIN `members` disp ON s.`disputed_by_member_id` = disp.`id`
            LEFT JOIN `members` rev ON s.`reversed_by_member_id` = rev.`id`
            WHERE s.`group_id` = :group_id AND s.`is_deleted` = 0
            ORDER BY s.`settled_date` DESC, s.`id` DESC
        ");
        $settleStmt->execute([':group_id' => $groupId]);
        $rawSettlements = $settleStmt->fetchAll(PDO::FETCH_ASSOC);

        $settlements = [];
        foreach ($rawSettlements as $row) {
            $amountCents = (int) $row['amount_cents'];
            $payerId = (int) $row['payer_member_id'];
            $payeeId = (int) $row['payee_member_id'];
            $status = (string) ($row['status'] ?? 'CONFIRMED');

            if ($status === 'CONFIRMED') {
                if (isset($memberMap[$payerId])) {
                    $memberMap[$payerId]['settlements_sent_cents'] += $amountCents;
                }
                if (isset($memberMap[$payeeId])) {
                    $memberMap[$payeeId]['settlements_received_cents'] += $amountCents;
                }
            }

            $settlements[] = [
                'id' => (int) $row['id'],
                'group_id' => (int) $row['group_id'],
                'payer' => [
                    'id' => $payerId,
                    'name' => $row['payer_name'],
                ],
                'payee' => [
                    'id' => $payeeId,
                    'name' => $row['payee_name'],
                ],
                'amount_cents' => $amountCents,
                'status' => $status,
                'payment_method' => (string) ($row['payment_method'] ?? 'OTHER'),
                'reference_id' => $row['reference_id'] ?? null,
                'recorded_by' => $row['recorded_by_member_id'] ? [
                    'id' => (int) $row['recorded_by_member_id'],
                    'name' => $row['recorded_by_name'] ?? 'Member #' . $row['recorded_by_member_id'],
                ] : null,
                'confirmed_by' => $row['confirmed_by_member_id'] ? [
                    'id' => (int) $row['confirmed_by_member_id'],
                    'name' => $row['confirmed_by_name'] ?? 'Member #' . $row['confirmed_by_member_id'],
                ] : null,
                'confirmed_at' => $row['confirmed_at'] ?? null,
                'disputed_by' => $row['disputed_by_member_id'] ? [
                    'id' => (int) $row['disputed_by_member_id'],
                    'name' => $row['disputed_by_name'] ?? 'Member #' . $row['disputed_by_member_id'],
                ] : null,
                'disputed_at' => $row['disputed_at'] ?? null,
                'dispute_reason' => $row['dispute_reason'] ?? null,
                'reversed_by' => $row['reversed_by_member_id'] ? [
                    'id' => (int) $row['reversed_by_member_id'],
                    'name' => $row['reversed_by_name'] ?? 'Member #' . $row['reversed_by_member_id'],
                ] : null,
                'reversed_at' => $row['reversed_at'] ?? null,
                'reversal_reason' => $row['reversal_reason'] ?? null,
                'reversal_of_id' => $row['reversal_of_id'] ? (int) $row['reversal_of_id'] : null,
                'settled_date' => $row['settled_date'],
                'notes' => $row['notes'],
                'is_deleted' => (bool) $row['is_deleted'],
                'created_at' => $row['created_at'],
            ];
        }

        // 5. In-Memory Net Balances Calculation & Zero-Sum Assertion
        $netSum = 0;
        $totalSpending = 0;
        $totalSettled = 0;

        foreach ($memberMap as $mId => &$data) {
            $net = $data['total_paid_cents'] - $data['total_owed_cents']
                + $data['settlements_sent_cents'] - $data['settlements_received_cents'];

            $data['net_balance_cents'] = $net;
            $netSum += $net;
            $totalSpending += $data['total_paid_cents'];
            $totalSettled += $data['settlements_sent_cents'];

            if ($net > 0) {
                $data['status'] = 'CREDITOR';
            } elseif ($net < 0) {
                $data['status'] = 'DEBTOR';
            } else {
                $data['status'] = 'SETTLED';
            }
        }
        unset($data);

        if ($netSum !== 0) {
            throw new \RuntimeException(
                "Critical mathematical integrity violation: Group balances do not sum to zero (net discrepancy: {$netSum} paise)."
            );
        }

        $balanceReport = [
            'group_id' => $groupId,
            'total_spending_cents' => $totalSpending,
            'total_settled_cents' => $totalSettled,
            'zero_sum_verified' => true,
            'members' => array_values($memberMap),
        ];

        // 6. Greedy Debt Simplification Plan
        $settlementPlan = \App\Services\SettlementEngine::simplifyDebts(
            $balanceReport['members'],
            $currencyCode
        );

        // 7. Format Expenses
        $expenses = array_map(function (array $exp) use ($payersByExpense, $splitsByExpense, $itemsByExpense, $receiptsByExpense) {
            $id = (int) $exp['id'];
            $receiptsList = $receiptsByExpense[$id] ?? [];
            return [
                'id' => $id,
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
        }, $rawExpenses);

        return [
            'group' => [
                'id' => $groupId,
                'uuid' => $group['uuid'],
                'name' => $group['name'],
                'currency_code' => $currencyCode,
                'invite_token' => $group['invite_token'],
                'owner_user_id' => $group['owner_user_id'] ? (int) $group['owner_user_id'] : null,
                'creator_member_id' => $creatorMemberId,
                'version' => (int) $group['version'],
                'created_at' => $group['created_at'],
            ],
            'members' => array_map(function (array $m) {
                return [
                    'id' => (int) $m['id'],
                    'name' => $m['name'],
                    'upi_id' => $m['upi_id'] ?? null,
                    'color_hex' => $m['color_hex'] ?? null,
                    'user_id' => $m['user_id'] ? (int) $m['user_id'] : null,
                    'is_active' => (bool) $m['is_active'],
                    'created_at' => $m['created_at'],
                ];
            }, $members),
            'balances' => [
                'total_spending_cents' => $balanceReport['total_spending_cents'],
                'total_settled_cents' => $balanceReport['total_settled_cents'],
                'zero_sum_verified' => $balanceReport['zero_sum_verified'],
                'members' => $balanceReport['members'],
            ],
            'settlement_plan' => [
                'total_spending_cents' => $balanceReport['total_spending_cents'],
                'total_transactions' => $settlementPlan['total_transactions'],
                'total_settlement_volume_cents' => $settlementPlan['total_settlement_volume_cents'],
                'transactions' => $settlementPlan['transactions'],
            ],
            'expenses' => $expenses,
            'settlements' => $settlements,
        ];
    }

    /**
     * Generate a cryptographically secure UUID v4.
     *
     * @return string
     */
    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
