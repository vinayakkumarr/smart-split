<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * MySQL-backed sliding window rate limiter.
 *
 * Implements a persistent fixed-window counter with atomic upsert semantics
 * to prevent race conditions and lost updates under concurrent distributed requests.
 */
class RateLimiterService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        // Schema is maintained by migration 007_add_rate_limiting.sql
    }

    /**
     * Check and record a rate-limited action attempt.
     *
     * @param string $action Logical action name (e.g., 'auth_register', 'group_create')
     * @param string $identifier Unique client identifier (e.g., Client IP, User ID)
     * @param int $maxAttempts Maximum allowed attempts in the window
     * @param int $windowSeconds Window duration in seconds
     * @return array{allowed: bool, attempts: int, remaining: int, retry_after: int, reset_at: int}
     */
    public function check(
        string $action,
        string $identifier,
        int $maxAttempts = 10,
        int $windowSeconds = 60
    ): array {
        $keyHash = hash('sha256', $action . ':' . $identifier);
        $now = time();
        $windowExpiresAt = $now + $windowSeconds;

        // Atomic Upsert: insert new counter or increment existing active window counter.
        // If the window has expired, reset counter to 1 and advance window.
        $upsertSql = "
            INSERT INTO `rate_limits` (`key_hash`, `attempts`, `window_expires_at`)
            VALUES (:key_hash, 1, :window_expires_at)
            ON DUPLICATE KEY UPDATE
                attempts = IF(`window_expires_at` < :now1, 1, `attempts` + 1),
                window_expires_at = IF(`window_expires_at` < :now2, :window_expires_at_upd, `window_expires_at`)
        ";

        $stmt = $this->pdo->prepare($upsertSql);
        $stmt->execute([
            ':key_hash' => $keyHash,
            ':window_expires_at' => $windowExpiresAt,
            ':now1' => $now,
            ':now2' => $now,
            ':window_expires_at_upd' => $windowExpiresAt,
        ]);

        // Retrieve current state
        $fetchStmt = $this->pdo->prepare("SELECT `attempts`, `window_expires_at` FROM `rate_limits` WHERE `key_hash` = :key_hash LIMIT 1");
        $fetchStmt->execute([':key_hash' => $keyHash]);
        $row = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        $currentAttempts = $row ? (int) $row['attempts'] : 1;
        $expiresAt = $row ? (int) $row['window_expires_at'] : $windowExpiresAt;
        $retryAfter = max(1, $expiresAt - $now);

        $allowed = ($currentAttempts <= $maxAttempts);
        $remaining = max(0, $maxAttempts - $currentAttempts);

        // Probabilistic opportunistic garbage collection (1 in 100 requests)
        if (random_int(1, 100) === 1) {
            $this->cleanupExpired();
        }

        return [
            'allowed' => $allowed,
            'attempts' => $currentAttempts,
            'remaining' => $remaining,
            'retry_after' => $retryAfter,
            'reset_at' => $expiresAt,
        ];
    }

    /**
     * Clear / reset the rate limit for a specific action and identifier.
     *
     * @param string $action
     * @param string $identifier
     * @return bool
     */
    public function clear(string $action, string $identifier): bool
    {
        $keyHash = hash('sha256', $action . ':' . $identifier);
        $stmt = $this->pdo->prepare("DELETE FROM `rate_limits` WHERE `key_hash` = :key_hash");
        return $stmt->execute([':key_hash' => $keyHash]);
    }

    /**
     * Purge all rate limit records (primarily for test teardown).
     */
    public function resetAll(): void
    {
        $this->pdo->exec("DELETE FROM `rate_limits`");
    }

    /**
     * Prune expired rate limit records older than 24 hours.
     */
    public function cleanupExpired(): void
    {
        try {
            $cutoff = time() - 86400;
            $stmt = $this->pdo->prepare("DELETE FROM `rate_limits` WHERE `window_expires_at` < :cutoff LIMIT 500");
            $stmt->execute([':cutoff' => $cutoff]);
        } catch (\Throwable $e) {
            // Non-blocking catch
        }
    }

    /**
     * Ensure rate_limits schema exists on initial invocation.
     */
    private function ensureTableExists(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS `rate_limits` (
                `key_hash` VARCHAR(64) NOT NULL PRIMARY KEY,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
                `window_expires_at` INT UNSIGNED NOT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_window_expires` (`window_expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $this->pdo->exec($sql);
    }
}
