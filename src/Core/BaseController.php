<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Base Controller providing HTTP response helpers and request validation routines.
 */
abstract class BaseController
{
    /**
     * Dispatch a successful JSON response.
     *
     * @param mixed $data
     * @param int $statusCode
     * @param array<string, mixed> $meta
     * @return void
     */
    protected function json(mixed $data, int $statusCode = 200, array $meta = []): void
    {
        Response::json($data, $statusCode, $meta);
    }

    /**
     * Dispatch an error JSON response.
     *
     * @param string $message
     * @param string $code
     * @param mixed $details
     * @param int $statusCode
     * @return void
     */
    protected function error(
        string $message,
        string $code = 'BAD_REQUEST',
        mixed $details = null,
        int $statusCode = 400
    ): void {
        Response::error($message, $code, $details, $statusCode);
    }

    /**
     * Validate that an array contains all required non-empty keys.
     *
     * @param array<string, mixed> $data
     * @param array<string> $requiredFields
     * @return void
     * @throws InvalidArgumentException
     */
    protected function validateRequired(array $data, array $requiredFields): void
    {
        $missing = [];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            $fieldsList = implode(', ', $missing);
            throw new InvalidArgumentException("Missing or empty required fields: {$fieldsList}", 422);
        }
    }

    /**
     * Sanitize a plain text string against control characters and trim excess whitespace.
     *
     * @param mixed $value
     * @param int $maxLength
     * @return string
     */
    protected function sanitizeString(mixed $value, int $maxLength = 255): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $cleaned = trim(strip_tags((string) $value));
        return mb_substr($cleaned, 0, $maxLength, 'UTF-8');
    }
}
