<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Core\Env;
use InvalidArgumentException;

/**
 * Factory for creating receipt storage provider instances.
 */
class ReceiptStorageFactory
{
    private static ?ReceiptStorageInterface $instance = null;
    /** @var array<string, callable> */
    private static array $customDrivers = [];

    /**
     * Create a new receipt storage instance based on configuration or explicit driver name.
     *
     * @param string|null $driver
     * @param array<string, mixed>|null $config
     * @return ReceiptStorageInterface
     */
    public static function create(?string $driver = null, ?array $config = null): ReceiptStorageInterface
    {
        $driver = strtolower((string) ($driver ?? Env::get('RECEIPT_STORAGE', 'local')));

        if (isset(self::$customDrivers[$driver])) {
            return (self::$customDrivers[$driver])($config ?? []);
        }

        return match ($driver) {
            'local', 'filesystem' => new LocalReceiptStorage($config['base_directory'] ?? null),
            default => throw new InvalidArgumentException("Unsupported receipt storage driver '{$driver}'. Supported drivers: 'local'.", 500),
        };
    }

    /**
     * Register a custom storage driver (e.g. S3, MinIO, GCS, Azure, R2, or Mock).
     *
     * @param string $driver
     * @param callable $resolver Function(array $config): ReceiptStorageInterface
     */
    public static function registerDriver(string $driver, callable $resolver): void
    {
        self::$customDrivers[strtolower($driver)] = $resolver;
    }

    /**
     * Get or initialize default singleton instance.
     */
    public static function getInstance(): ReceiptStorageInterface
    {
        if (self::$instance === null) {
            self::$instance = self::create();
        }
        return self::$instance;
    }

    /**
     * Set a custom instance (useful for testing or mocking).
     */
    public static function setInstance(?ReceiptStorageInterface $storage): void
    {
        self::$instance = $storage;
    }

    /**
     * Reset singleton instance and custom drivers to clean state.
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$customDrivers = [];
    }
}
