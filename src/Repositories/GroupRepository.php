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
