<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Storage interface for receipt attachment files and object stores.
 */
interface ReceiptStorageInterface
{
    /**
     * Store raw file contents under a given key.
     *
     * @param string $key Unique storage key / relative file path.
     * @param string $contents Raw binary content.
     * @param string $mimeType MIME content type.
     * @return bool True on success, false on failure.
     */
    public function store(string $key, string $contents, string $mimeType): bool;

    /**
     * Read file contents as string.
     *
     * @param string $key
     * @return string|null Raw content or null if not found.
     */
    public function read(string $key): ?string;

    /**
     * Check if a file exists.
     *
     * @param string $key
     * @return bool
     */
    public function exists(string $key): bool;

    /**
     * Delete a stored file.
     *
     * @param string $key
     * @return bool True if deleted or did not exist.
     */
    public function delete(string $key): bool;

    /**
     * Get the MIME type of a stored file.
     *
     * @param string $key
     * @return string|null
     */
    public function getMimeType(string $key): ?string;

    /**
     * Get the size of a stored file in bytes.
     *
     * @param string $key
     * @return int Size in bytes, 0 if not found.
     */
    public function getSizeBytes(string $key): int;

    /**
     * Resolve the absolute local file path if stored locally, or null if remote.
     *
     * @param string $key
     * @return string|null Absolute filesystem path if locally resolvable.
     */
    public function getLocalPath(string $key): ?string;
}
