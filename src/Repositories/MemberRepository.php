<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Data Access Repository for Group Members.
 */
class MemberRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Add a new member to a group with a unique member token and optional linked user account.
     *
     * @param int $groupId
     * @param string $name
     * @param int|null $userId
     * @return array<string, mixed>
     */
    public function create(int $groupId, string $name, ?int $userId = null): array
    {
        $memberToken = bin2hex(random_bytes(32));

        $stmt = $this->pdo->prepare("
            INSERT INTO `members` (`group_id`, `user_id`, `name`, `member_token`, `is_active`)
            VALUES (:group_id, :user_id, :name, :member_token, 1)
        ");

        $stmt->execute([
            ':group_id' => $groupId,
            ':user_id' => $userId,
            ':name' => $name,
            ':member_token' => $memberToken,
        ]);

        $memberId = (int) $this->pdo->lastInsertId();

        return [
            'id' => $memberId,
            'group_id' => $groupId,
            'user_id' => $userId,
            'name' => $name,
            'member_token' => $memberToken,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Retrieve all active members belonging to a group.
     *
     * @param int $groupId
     * @param bool $onlyActive
     * @return array<array<string, mixed>>
     */
    public function findByGroupId(int $groupId, bool $onlyActive = true): array
    {
        $sql = "
            SELECT m.`id`, m.`group_id`, m.`user_id`, m.`name`, m.`color_hex`, m.`email`, m.`member_token`, m.`is_active`, m.`created_at`,
                   m.`upi_id` AS `member_upi_id`,
                   u.`upi_id` AS `user_upi_id`,
                   COALESCE(u.`upi_id`, m.`upi_id`) AS `upi_id`
            FROM `members` m
            LEFT JOIN `users` u ON m.`user_id` = u.`id`
            WHERE m.`group_id` = :group_id
        ";

        if ($onlyActive) {
            $sql .= " AND m.`is_active` = 1";
        }

        $sql .= " ORDER BY m.`id` ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':group_id' => $groupId]);

        return $stmt->fetchAll();
    }

    /**
     * Retrieve the original creator member for a group (the first registered member).
     *
     * @param int $groupId
     * @return array<string, mixed>|null
     */
    public function getCreatorMember(int $groupId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT m.`id`, m.`group_id`, m.`user_id`, m.`name`, m.`color_hex`, m.`email`, m.`member_token`, m.`is_active`, m.`created_at`,
                   m.`upi_id` AS `member_upi_id`,
                   u.`upi_id` AS `user_upi_id`,
                   COALESCE(u.`upi_id`, m.`upi_id`) AS `upi_id`
            FROM `members` m
            LEFT JOIN `users` u ON m.`user_id` = u.`id`
            WHERE m.`group_id` = :group_id
            ORDER BY m.`id` ASC
            LIMIT 1
        ");
        $stmt->execute([':group_id' => $groupId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Find a member by ID.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT m.`id`, m.`group_id`, m.`user_id`, m.`name`, m.`color_hex`, m.`email`, m.`member_token`, m.`is_active`, m.`created_at`,
                   m.`upi_id` AS `member_upi_id`,
                   u.`upi_id` AS `user_upi_id`,
                   COALESCE(u.`upi_id`, m.`upi_id`) AS `upi_id`
            FROM `members` m
            LEFT JOIN `users` u ON m.`user_id` = u.`id`
            WHERE m.`id` = :id
            LIMIT 1
        ");

        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Check if a member with the same name already exists in the group.
     *
     * @param int $groupId
     * @param string $name
     * @return array<string, mixed>|null
     */
    public function findByName(int $groupId, string $name): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT `id`, `group_id`, `user_id`, `name`, `member_token`, `is_active`, `created_at`
            FROM `members`
            WHERE `group_id` = :group_id AND LOWER(TRIM(`name`)) = LOWER(TRIM(:name))
            LIMIT 1
        ");

        $stmt->execute([
            ':group_id' => $groupId,
            ':name' => $name,
        ]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Check if a member is referenced in any expenses, payers, splits, or settlements.
     *
     * @param int $memberId
     * @return bool
     */
    public function hasTransactions(int $memberId): bool
    {
        // 1. Check created expenses
        $stmtExp = $this->pdo->prepare("SELECT COUNT(*) FROM `expenses` WHERE `created_by_member_id` = :member_id");
        $stmtExp->execute([':member_id' => $memberId]);
        if ((int) $stmtExp->fetchColumn() > 0) return true;

        // 2. Check expense payers
        $stmtPay = $this->pdo->prepare("SELECT COUNT(*) FROM `expense_payers` WHERE `member_id` = :member_id");
        $stmtPay->execute([':member_id' => $memberId]);
        if ((int) $stmtPay->fetchColumn() > 0) return true;

        // 3. Check expense splits
        $stmtSpl = $this->pdo->prepare("SELECT COUNT(*) FROM `expense_splits` WHERE `member_id` = :member_id");
        $stmtSpl->execute([':member_id' => $memberId]);
        if ((int) $stmtSpl->fetchColumn() > 0) return true;

        // 4. Check settlements
        $stmtSet = $this->pdo->prepare("SELECT COUNT(*) FROM `settlements` WHERE `payer_member_id` = :payer_id OR `payee_member_id` = :payee_id");
        $stmtSet->execute([
            ':payer_id' => $memberId,
            ':payee_id' => $memberId,
        ]);
        if ((int) $stmtSet->fetchColumn() > 0) return true;

        return false;
    }

    /**
     * Update a member's name.
     *
     * @param int $memberId
     * @param int $groupId
     * @param string $name
     * @return bool
     */
    public function updateName(int $memberId, int $groupId, string $name): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE `members`
            SET `name` = :name
            WHERE `id` = :id AND `group_id` = :group_id
        ");
        return $stmt->execute([
            ':name' => $name,
            ':id' => $memberId,
            ':group_id' => $groupId,
        ]);
    }

    /**
     * Update a member's name and/or UPI ID.
     *
     * @param int $memberId
     * @param int $groupId
     * @param string $name
     * @param string|null $upiId
     * @return bool
     */
    public function updateMemberDetails(int $memberId, int $groupId, string $name, ?string $upiId = null): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE `members`
            SET `name` = :name, `upi_id` = :upi_id
            WHERE `id` = :id AND `group_id` = :group_id
        ");
        return $stmt->execute([
            ':name' => $name,
            ':upi_id' => $upiId,
            ':id' => $memberId,
            ':group_id' => $groupId,
        ]);
    }

    /**
     * Update a member's UPI ID specifically.
     *
     * @param int $memberId
     * @param int $groupId
     * @param string|null $upiId
     * @return bool
     */
    public function updateUpiId(int $memberId, int $groupId, ?string $upiId = null): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE `members`
            SET `upi_id` = :upi_id
            WHERE `id` = :id AND `group_id` = :group_id
        ");
        return $stmt->execute([
            ':upi_id' => $upiId,
            ':id' => $memberId,
            ':group_id' => $groupId,
        ]);
    }

    /**
     * Permanently delete a member record from the database.
     *
     * @param int $memberId
     * @param int $groupId
     * @return bool
     */
    public function delete(int $memberId, int $groupId): bool
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM `members`
            WHERE `id` = :id AND `group_id` = :group_id
        ");
        return $stmt->execute([
            ':id' => $memberId,
            ':group_id' => $groupId,
        ]);
    }

    /**
     * Soft deactivate a member when historical transaction records exist.
     *
     * @param int $memberId
     * @param int $groupId
     * @return bool
     */
    public function deactivate(int $memberId, int $groupId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE `members`
            SET `is_active` = 0, `user_id` = NULL
            WHERE `id` = :id AND `group_id` = :group_id
        ");
        return $stmt->execute([
            ':id' => $memberId,
            ':group_id' => $groupId,
        ]);
    }
}
