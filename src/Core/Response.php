<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Standardized JSON API Response Dispatcher.
 */
class Response
{
    public static int $lastStatusCode = 200;

    public static function getLastStatusCode(): int
    {
        return self::$lastStatusCode;
    }

    /**
     * Emit a successful JSON response and terminate script execution.
     *
     * @param mixed $data
     * @param int $statusCode
     * @param array<string, mixed> $meta
     * @param bool $exit
     * @return void
     */
    public static function json(mixed $data, int $statusCode = 200, array $meta = [], bool $exit = true): void
    {
        self::$lastStatusCode = $statusCode;
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($exit && php_sapi_name() !== 'cli') {
            exit;
        }
    }

    /**
     * Emit an error JSON response and terminate script execution.
     *
     * @param string $message
     * @param string $code
     * @param mixed $details
     * @param int $statusCode
     * @param bool $exit
     * @return void
     */
    public static function error(
        string $message,
        string $code = 'BAD_REQUEST',
        mixed $details = null,
        int $statusCode = 400,
        bool $exit = true
    ): void {
        self::$lastStatusCode = $statusCode;
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        $errorPayload = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $errorPayload['details'] = $details;
        }

        $payload = [
            'success' => false,
            'error' => $errorPayload,
        ];

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($exit && php_sapi_name() !== 'cli') {
            exit;
        }
    }
}
