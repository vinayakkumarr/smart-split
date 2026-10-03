<?php

declare(strict_types=1);

namespace App\Services\Storage;

use InvalidArgumentException;
use RuntimeException;

/**
 * Local filesystem implementation of ReceiptStorageInterface.
 */
class LocalReceiptStorage implements ReceiptStorageInterface
{
    private string $baseDirectory;

    public function __construct(?string $baseDirectory = null)
    {
        $dir = $baseDirectory ?? (dirname(__DIR__, 3) . '/storage/receipts');
        $this->baseDirectory = rtrim(str_replace('\\', '/', $dir), '/');

        if (!is_dir($this->baseDirectory)) {
            mkdir($this->baseDirectory, 0755, true);
        }
    }

    /**
     * Sanitize key and resolve full filesystem path safely.
     *
     * @param string $key
     * @return string
     * @throws InvalidArgumentException
     */
    private function resolvePath(string $key): string
    {
        if (str_contains($key, '..') || str_contains($key, '/') || str_contains($key, '\\')) {
            throw new InvalidArgumentException("Invalid storage key '{$key}': path traversal is not permitted.", 422);
        }

        $baseName = basename($key);
        if ($baseName === '' || $baseName === '.' || $baseName === '..') {
            throw new InvalidArgumentException("Invalid storage key '{$key}'.", 422);
        }

        return $this->baseDirectory . '/' . $baseName;
    }

    /**
     * Store raw file contents.
     */
    public function store(string $key, string $contents, string $mimeType): bool
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        if (!in_array(strtolower($mimeType), $allowedMimes, true)) {
            throw new InvalidArgumentException("Unsupported MIME type '{$mimeType}'.", 422);
        }

        $path = $this->resolvePath($key);
        $result = file_put_contents($path, $contents);
        if ($result === false) {
            throw new RuntimeException("Failed to persist file to local storage at '{$path}'.", 500);
        }
        return true;
    }

    /**
     * Read file contents.
     */
    public function read(string $key): ?string
    {
        $path = $this->resolvePath($key);
        if (!file_exists($path) || !is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        return $contents === false ? null : $contents;
    }

    /**
     * Check if a file exists.
     */
    public function exists(string $key): bool
    {
        try {
            $path = $this->resolvePath($key);
            return file_exists($path) && is_file($path);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Delete a stored file.
     */
    public function delete(string $key): bool
    {
        try {
            $path = $this->resolvePath($key);
            if (file_exists($path) && is_file($path)) {
                return @unlink($path);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the MIME type of a stored file.
     */
    public function getMimeType(string $key): ?string
    {
        $path = $this->resolvePath($key);
        if (!file_exists($path) || !is_file($path)) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $path) ?: null;
        finfo_close($finfo);

        return $mime;
    }

    /**
     * Get the size of a stored file in bytes.
     */
    public function getSizeBytes(string $key): int
    {
        $path = $this->resolvePath($key);
        if (!file_exists($path) || !is_file($path)) {
            return 0;
        }
        $size = filesize($path);
        return $size === false ? 0 : (int) $size;
    }

    /**
     * Resolve the absolute local file path.
     */
    public function getLocalPath(string $key): ?string
    {
        $path = $this->resolvePath($key);
        if (file_exists($path) && is_file($path)) {
            return realpath($path) ?: $path;
        }
        return null;
    }

    /**
     * Get base directory.
     */
    public function getBaseDirectory(): string
    {
        return $this->baseDirectory;
    }
}
