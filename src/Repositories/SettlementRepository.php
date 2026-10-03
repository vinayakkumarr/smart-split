<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Data Access Repository for Debt Settlements with Verification Lifecycle & Attribution.
 */
class SettlementRepository
{
    private PDO $pdo;
    private ActivityLogRepository $logRepo;

    public function __construct(?PDO $pdo = null, ?ActivityLogRepository $logRepo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->logRepo = $logRepo ?? new ActivityLogRepository($this->pdo);
    }

    /**
     * Record a direct debt payment settlement between two group members.
     *
     * @param int $groupId
     * @param int $payerId
     * @param int $payeeId
     * @param int $amountCents
     * @param string|null $notes
     * @param string|null $settledDate
     * @param int|null $recordedByMemberId
     * @param string $paymentMethod
     * @param string|null $referenceId
     * @param string $status
     * @param int|null $confirmedByMemberId
     * @param string|null $confirmedAt
     * @return int Inserted settlement ID.
     * @throws Throwable
     */
    public function create(
        int $groupId,
        int $payerId,
        int $payeeId,
        int $amountCents,
        ?string $notes = null,
        ?string $settledDate = null,
        ?int $recordedByMemberId = null,
        string $paymentMethod = 'OTHER',
        ?string $referenceId = null,
        string $status = 'PENDING',
        ?int $confirmedByMemberId = null,
        ?string $confirmedAt = null
    ): int {
        return Database::transaction(function (PDO $pdo) use (
            $groupId, $payerId, $payeeId, $amountCents, $notes, $settledDate,
            $recordedByMemberId, $paymentMethod, $referenceId, $status,
            $confirmedByMemberId, $confirmedAt
        ): int {
            // 1. Increment Group Version / Acquire Exclusive Row Lock
            $versionStmt = $pdo->prepare("
                UPDATE `groups` SET `version` = `version` + 1 WHERE `id` = :group_id
            ");
            $versionStmt->execute([':group_id' => $groupId]);

            $date = $settledDate ?? date('Y-m-d H:i:s');
            $actorId = $recordedByMemberId ?? $payerId;

            $stmt = $pdo->prepare("
                INSERT INTO `settlements` (
                    `group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`,
                    `status`, `recorded_by_member_id`, `payment_method`, `reference_id`,
                    `settled_date`, `notes`, `confirmed_by_member_id`, `confirmed_at`, `is_deleted`
                ) VALUES (
                    :group_id, :payer_id, :payee_id, :amount_cents,
                    :status, :recorded_by_member_id, :payment_method, :reference_id,
                    :settled_date, :notes, :confirmed_by_member_id, :confirmed_at, 0
                )
            ");

            $stmt->execute([
                ':group_id' => $groupId,
                ':payer_id' => $payerId,
                ':payee_id' => $payeeId,
                ':amount_cents' => $amountCents,
                ':status' => $status,
                ':recorded_by_member_id' => $recordedByMemberId,
                ':payment_method' => $paymentMethod,
                ':reference_id' => $referenceId,
                ':settled_date' => $date,
                ':notes' => $notes,
                ':confirmed_by_member_id' => $confirmedByMemberId,
                ':confirmed_at' => $confirmedAt,
            ]);

            $settlementId = (int) $pdo->lastInsertId();

            // Record audit log
            $this->logRepo->record(
                $groupId,
                $actorId,
                'SETTLEMENT_RECORDED',
                'settlements',
                $settlementId,
                [
                    'payer_id' => $payerId,
                    'payee_id' => $payeeId,
                    'recorded_by_id' => $recordedByMemberId,
                    'amount_cents' => $amountCents,
                    'status' => $status,
                    'payment_method' => $paymentMethod,
                    'reference_id' => $referenceId,
                    'notes' => $notes,
                    'settled_date' => $date,
                ]
            );

            return $settlementId;
        });
    }

    /**
     * Explicitly confirm receipt of a pending settlement.
     *
     * @param int $settlementId
     * @param int $confirmedByMemberId
     * @return bool
     * @throws Throwable
     */
    public function confirm(int $settlementId, int $confirmedByMemberId): bool
    {
        return Database::transaction(function (PDO $pdo) use ($settlementId, $confirmedByMemberId): bool {
            $stmt = $pdo->prepare("
                SELECT `id`, `group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `status`, `is_deleted`
                FROM `settlements`
                WHERE `id` = :id
                FOR UPDATE
            ");
            $stmt->execute([':id' => $settlementId]);
            $settlement = $stmt->fetch();

            if (!$settlement || (int) $settlement['is_deleted'] === 1) {
                throw new InvalidArgumentException("Settlement not found or deleted.", 404);
            }

            if ($settlement['status'] !== 'PENDING') {
                throw new InvalidArgumentException("Only PENDING settlements can be confirmed.", 422);
            }

            $now = date('Y-m-d H:i:s');
            $updateStmt = $pdo->prepare("
                UPDATE `settlements`
                SET `status` = 'CONFIRMED',
                    `confirmed_by_member_id` = :confirmed_by,
                    `confirmed_at` = :confirmed_at
                WHERE `id` = :id
            ");
            $updateStmt->execute([
                ':confirmed_by' => $confirmedByMemberId,
                ':confirmed_at' => $now,
                ':id' => $settlementId,
            ]);

            // Increment group version
            $versionStmt = $pdo->prepare("
                UPDATE `groups` SET `version` = `version` + 1 WHERE `id` = :group_id
            ");
            $versionStmt->execute([':group_id' => (int) $settlement['group_id']]);

            // Record audit log
            $this->logRepo->record(
                (int) $settlement['group_id'],
                $confirmedByMemberId,
                'SETTLEMENT_CONFIRMED',
                'settlements',
                $settlementId,
                [
                    'payer_id' => (int) $settlement['payer_member_id'],
                    'payee_id' => (int) $settlement['payee_member_id'],
                    'confirmed_by_id' => $confirmedByMemberId,
                    'amount_cents' => (int) $settlement['amount_cents'],
                    'confirmed_at' => $now,
                ]
            );

            return true;
        });
    }

    /**
     * Dispute a pending settlement.
     *
     * @param int $settlementId
     * @param int $disputedByMemberId
     * @param string|null $reason
     * @return bool
     * @throws Throwable
     */
    public function dispute(int $settlementId, int $disputedByMemberId, ?string $reason = null): bool
    {
        return Database::transaction(function (PDO $pdo) use ($settlementId, $disputedByMemberId, $reason): bool {
            $stmt = $pdo->prepare("
                SELECT `id`, `group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `status`, `is_deleted`
                FROM `settlements`
                WHERE `id` = :id
                FOR UPDATE
            ");
            $stmt->execute([':id' => $settlementId]);
            $settlement = $stmt->fetch();

            if (!$settlement || (int) $settlement['is_deleted'] === 1) {
                throw new InvalidArgumentException("Settlement not found or deleted.", 404);
            }

            if ($settlement['status'] !== 'PENDING') {
                throw new InvalidArgumentException("Only PENDING settlements can be disputed.", 422);
            }

            $now = date('Y-m-d H:i:s');
            $updateStmt = $pdo->prepare("
                UPDATE `settlements`
                SET `status` = 'DISPUTED',
                    `disputed_by_member_id` = :disputed_by,
                    `disputed_at` = :disputed_at,
                    `dispute_reason` = :reason
                WHERE `id` = :id
            ");
            $updateStmt->execute([
                ':disputed_by' => $disputedByMemberId,
                ':disputed_at' => $now,
                ':reason' => $reason,
                ':id' => $settlementId,
            ]);

            // Increment group version
            $versionStmt = $pdo->prepare("
                UPDATE `groups` SET `version` = `version` + 1 WHERE `id` = :group_id
            ");
            $versionStmt->execute([':group_id' => (int) $settlement['group_id']]);

            // Record audit log
            $this->logRepo->record(
                (int) $settlement['group_id'],
                $disputedByMemberId,
                'SETTLEMENT_DISPUTED',
                'settlements',
                $settlementId,
                [
                    'payer_id' => (int) $settlement['payer_member_id'],
                    'payee_id' => (int) $settlement['payee_member_id'],
                    'disputed_by_id' => $disputedByMemberId,
                    'amount_cents' => (int) $settlement['amount_cents'],
                    'dispute_reason' => $reason,
                    'disputed_at' => $now,
                ]
            );

            return true;
        });
    }

    /**
     * Reverse a previously confirmed settlement.
     *
     * @param int $settlementId
     * @param int $reversedByMemberId
     * @param string|null $reason
     * @return bool
     * @throws Throwable
     */
    public function reverse(int $settlementId, int $reversedByMemberId, ?string $reason = null): bool
    {
        return Database::transaction(function (PDO $pdo) use ($settlementId, $reversedByMemberId, $reason): bool {
            $stmt = $pdo->prepare("
                SELECT `id`, `group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `status`, `is_deleted`
                FROM `settlements`
                WHERE `id` = :id
                FOR UPDATE
            ");
            $stmt->execute([':id' => $settlementId]);
            $settlement = $stmt->fetch();

            if (!$settlement || (int) $settlement['is_deleted'] === 1) {
                throw new InvalidArgumentException("Settlement not found or deleted.", 404);
            }

            if ($settlement['status'] === 'REVERSED') {
                throw new InvalidArgumentException("Settlement is already reversed.", 422);
            }

            $now = date('Y-m-d H:i:s');
            $updateStmt = $pdo->prepare("
                UPDATE `settlements`
                SET `status` = 'REVERSED',
                    `reversed_by_member_id` = :reversed_by,
                    `reversed_at` = :reversed_at,
                    `reversal_reason` = :reason
                WHERE `id` = :id
            ");
            $updateStmt->execute([
                ':reversed_by' => $reversedByMemberId,
                ':reversed_at' => $now,
                ':reason' => $reason,
                ':id' => $settlementId,
            ]);

            // Increment group version
            $versionStmt = $pdo->prepare("
                UPDATE `groups` SET `version` = `version` + 1 WHERE `id` = :group_id
            ");
            $versionStmt->execute([':group_id' => (int) $settlement['group_id']]);

            // Record audit log
            $this->logRepo->record(
                (int) $settlement['group_id'],
                $reversedByMemberId,
                'SETTLEMENT_REVERSED',
                'settlements',
                $settlementId,
                [
                    'payer_id' => (int) $settlement['payer_member_id'],
                    'payee_id' => (int) $settlement['payee_member_id'],
                    'reversed_by_id' => $reversedByMemberId,
                    'amount_cents' => (int) $settlement['amount_cents'],
                    'reversal_reason' => $reason,
                    'reversed_at' => $now,
                ]
            );

            return true;
        });
    }

    /**
     * Retrieve all non-deleted settlements for a group.
     *
     * @param int $groupId
     * @param bool $includeDeleted
     * @return array<array<string, mixed>>
     */
    public function findByGroupId(int $groupId, bool $includeDeleted = false): array
    {
        $sql = "
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
            WHERE s.`group_id` = :group_id
        ";

        if (!$includeDeleted) {
            $sql .= " AND s.`is_deleted` = 0";
        }

        $sql .= " ORDER BY s.`settled_date` DESC, s.`id` DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':group_id' => $groupId]);
        $rows = $stmt->fetchAll();

        return array_map(function (array $row) {
            return $this->formatSettlementRow($row);
        }, $rows);
    }

    /**
     * Find settlement by ID.
     *
     * @param int $settlementId
     * @return array<string, mixed>|null
     */
    public function findById(int $settlementId): ?array
    {
        $stmt = $this->pdo->prepare("
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
            WHERE s.`id` = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $settlementId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->formatSettlementRow($row);
    }

    /**
     * Soft delete a settlement and record audit log.
     *
     * @param int $settlementId
     * @param int|null $actorMemberId
     * @return bool
     */
    public function softDelete(int $settlementId, ?int $actorMemberId = null): bool
    {
        $settlement = $this->findById($settlementId);
        if (!$settlement || $settlement['is_deleted']) {
            return false;
        }

        return Database::transaction(function (PDO $pdo) use ($settlementId, $settlement, $actorMemberId): bool {
            $stmt = $pdo->prepare("
                UPDATE `settlements`
                SET `is_deleted` = 1
                WHERE `id` = :id
            ");
            $stmt->execute([':id' => $settlementId]);

            $this->logRepo->record(
                (int) $settlement['group_id'],
                $actorMemberId ?? (int) $settlement['payer']['id'],
                'SETTLEMENT_DELETED',
                'settlements',
                $settlementId,
                [
                    'amount_cents' => $settlement['amount_cents'],
                    'payer_id' => $settlement['payer']['id'],
                    'payee_id' => $settlement['payee']['id'],
                ]
            );

            // Increment group version
            $versionStmt = $pdo->prepare("
                UPDATE `groups` SET `version` = `version` + 1 WHERE `id` = :group_id
            ");
            $versionStmt->execute([':group_id' => (int) $settlement['group_id']]);

            return true;
        });
    }

    /**
     * Format database row into standardized structure.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatSettlementRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'group_id' => (int) $row['group_id'],
            'payer' => [
                'id' => (int) $row['payer_member_id'],
                'name' => $row['payer_name'],
            ],
            'payee' => [
                'id' => (int) $row['payee_member_id'],
                'name' => $row['payee_name'],
            ],
            'amount_cents' => (int) $row['amount_cents'],
            'status' => (string) ($row['status'] ?? 'CONFIRMED'),
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
}
