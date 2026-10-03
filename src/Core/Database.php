<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Thread-safe singleton PDO Database Connection Manager.
 */
class Database
{
    private static ?PDO $instance = null;

    /**
     * Prevent direct instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Prevent cloning.
     */
    private function __clone()
    {
    }

    /**
     * Retrieve or initialize the active PDO connection.
     *
     * @return PDO
     * @throws RuntimeException If database connection fails.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $configPath = dirname(__DIR__, 2) . '/config/database.php';
            if (!file_exists($configPath)) {
                throw new RuntimeException("Database configuration file not found at: {$configPath}");
            }

            /** @var array<string, mixed> $config */
            $config = require $configPath;

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );

            try {
                self::$instance = new PDO(
                    $dsn,
                    $config['username'],
                    $config['password'],
                    $config['options'] ?? []
                );
            } catch (PDOException $e) {
                throw new RuntimeException(
                    "Database connection failed: " . $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }
        }

        return self::$instance;
    }

    /**
     * Start a new PDO database transaction.
     *
     * @return bool
     */
    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    /**
     * Commit the current PDO database transaction.
     *
     * @return bool
     */
    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    /**
     * Roll back the current PDO database transaction.
     *
     * @return bool
     */
    public static function rollBack(): bool
    {
        if (self::getConnection()->inTransaction()) {
            return self::getConnection()->rollBack();
        }
        return false;
    }

    /**
     * Check if currently inside a database transaction.
     *
     * @return bool
     */
    public static function inTransaction(): bool
    {
        return self::getConnection()->inTransaction();
    }

    /**
     * Determine if a Throwable is a transient MySQL deadlock or serialization failure.
     *
     * @param Throwable $e
     * @return bool
     */
    public static function isDeadlock(Throwable $e): bool
    {
        if ($e instanceof PDOException) {
            if ($e->getCode() === '40001' || ($e->errorInfo[0] ?? null) === '40001') {
                return true;
            }
            $errno = (int) ($e->errorInfo[1] ?? 0);
            if ($errno === 1213 || $errno === 1205) {
                return true;
            }
        }
        $msg = strtolower($e->getMessage());
        return str_contains($msg, '1213 deadlock')
            || str_contains($msg, 'serialization failure')
            || str_contains($msg, 'lock wait timeout');
    }

    /**
     * Execute a callback inside an ACID database transaction with automatic bounded retry for transient deadlocks.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @param int $maxRetries Maximum retry attempts (default 10).
     * @param int $initialBackoffMs Initial backoff delay in milliseconds (default 25ms).
     * @return T
     * @throws Throwable
     */
    public static function transaction(callable $callback, int $maxRetries = 10, int $initialBackoffMs = 25): mixed
    {
        $pdo = self::getConnection();

        // If an outer transaction is already active, execute callback directly without nested management
        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $pdo->beginTransaction();

            try {
                $result = $callback($pdo);
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    try {
                        $pdo->rollBack();
                    } catch (Throwable $rollbackEx) {
                        // Rollback failed (e.g. lost connection)
                    }
                }

                // Retry only transient deadlocks/serialization failures within bounds
                if (self::isDeadlock($e) && $attempt < $maxRetries) {
                    // Exponential backoff with random jitter to disperse concurrent thread collisions
                    $backoff = (int) min(500, ($initialBackoffMs * (1 << min($attempt - 1, 5))));
                    $jitter = mt_rand(10, 50);
                    $delayMs = $backoff + $jitter;
                    usleep($delayMs * 1000);
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Reset the PDO instance (useful for unit testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
