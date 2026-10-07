<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lightweight, zero-dependency .env environment variable loader and registry.
 */
class Env
{
    /**
     * @var array<string, mixed> Cached environment key-value pairs.
     */
    private static array $variables = [];

    /**
     * Load environment variables from an absolute file path.
     *
     * @param string $filePath
     * @return void
     */
    public static function load(string $filePath): void
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip comments and empty lines
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // Split by first equals sign
            $parts = explode('=', $trimmed, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            // Strip surrounding single or double quotes
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            // Type cast common string literals
            $parsedValue = match (strtolower($value)) {
                'true', '(true)' => true,
                'false', '(false)' => false,
                'empty', '(empty)', 'null', '(null)' => null,
                default => $value,
            };

            self::$variables[$key] = $parsedValue;
            $_ENV[$key] = $parsedValue;
            $_SERVER[$key] = $parsedValue;
        }
    }

    /**
     * Get an environment variable value with optional default fallback.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $val = self::$variables[$key] ?? $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if ($val !== null) {
            return $val;
        }
        $envVal = getenv($key);
        return ($envVal !== false) ? $envVal : $default;
    }

    /**
     * Set or override an environment variable at runtime.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        self::$variables[$key] = $value;
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /**
     * Check if an environment variable exists.
     *
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$variables) || array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER);
    }
}
