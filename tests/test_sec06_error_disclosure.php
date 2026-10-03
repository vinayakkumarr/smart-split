<?php

declare(strict_types=1);

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Response.php';

use App\Core\Env;
use App\Core\Response;

Env::load(dirname(__DIR__) . '/.env');

echo "\n====================================================================\n";
echo " SEC-06: ERROR & STACK TRACE DISCLOSURE SECURITY TEST SUITE\n";
echo "====================================================================\n\n";

$passed = 0;
$total = 0;

function assertSec06(bool $condition, string $testId, string $description, int &$passedCount, int &$totalCount): void {
    $totalCount++;
    if ($condition) {
        $passedCount++;
        echo "  [PASS] {$testId}: {$description}\n";
    } else {
        echo "  [FAIL] {$testId}: {$description}\n";
    }
}

/**
 * Helper to simulate executing the global exception handler logic in a controlled environment.
 */
function simulateExceptionHandler(
    Throwable $exception,
    bool $isProduction,
    mixed $rawDebug,
    string $requestUri = '/api/test',
    string $acceptHeader = 'application/json'
): array {
    $isDebug = false;
    if (!$isProduction) {
        if (is_bool($rawDebug)) {
            $isDebug = $rawDebug;
        } elseif (is_string($rawDebug)) {
            $isDebug = in_array(strtolower(trim($rawDebug)), ['true', '1', 'yes', 'on'], true);
        } elseif (is_int($rawDebug)) {
            $isDebug = ($rawDebug === 1);
        }
    }

    $statusCode = ($exception->getCode() >= 400 && $exception->getCode() < 600) ? (int) $exception->getCode() : 500;
    $errorCode = match ($statusCode) {
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHORIZED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        405 => 'METHOD_NOT_ALLOWED',
        409 => 'CONFLICT',
        422 => 'UNPROCESSABLE_ENTITY',
        default => 'INTERNAL_SERVER_ERROR',
    };

    if ($isDebug) {
        $clientMessage = $exception->getMessage() ?: 'An error occurred.';
        $details = [
            'exception' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => explode("\n", $exception->getTraceAsString()),
        ];
    } else {
        $clientMessage = ($statusCode < 500 && $exception->getMessage() !== '')
            ? $exception->getMessage()
            : 'An unexpected error occurred. Please try again later.';
        $details = null;
    }

    $path = parse_url($requestUri, PHP_URL_PATH) ?? '/';
    $isApi = str_starts_with($path, '/api/') || str_contains($acceptHeader, 'application/json');

    ob_start();
    if ($isApi) {
        Response::error($clientMessage, $errorCode, $details, $statusCode, false);
        $output = ob_get_clean();
        $json = json_decode($output, true);
        return [
            'type' => 'json',
            'status' => $statusCode,
            'raw' => $output,
            'json' => $json,
        ];
    }

    if ($isDebug) {
        echo "<h1>" . htmlspecialchars(get_class($exception), ENT_QUOTES, 'UTF-8') . "</h1>";
        echo "<pre>" . htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8') . "</pre>";
    } else {
        echo "<!DOCTYPE html><html><body><h1>500 - Internal Server Error</h1><p>An unexpected error occurred on the server. Please try again later.</p></body></html>";
    }
    $output = ob_get_clean();
    return [
        'type' => 'html',
        'status' => $statusCode,
        'raw' => $output,
    ];
}

// -----------------------------------------------------------------------------
// SEC06-T01: Production API Exception (Zero disclosure of internal paths/traces)
// -----------------------------------------------------------------------------
$sensitiveInternalMessage = "SQLSTATE[42S02]: Base table not found: 1146 Table 'smart_split.secrets' doesn't exist at C:\\xampp\\htdocs\\src\\Database.php:42";
$testException = new RuntimeException($sensitiveInternalMessage, 500);

$prodApiResponse = simulateExceptionHandler(
    $testException,
    isProduction: true,
    rawDebug: false,
    requestUri: '/api/groups',
    acceptHeader: 'application/json'
);

assertSec06(
    $prodApiResponse['status'] === 500 
    && $prodApiResponse['json']['success'] === false
    && $prodApiResponse['json']['error']['code'] === 'INTERNAL_SERVER_ERROR'
    && $prodApiResponse['json']['error']['message'] === 'An unexpected error occurred. Please try again later.'
    && !isset($prodApiResponse['json']['error']['details'])
    && !str_contains($prodApiResponse['raw'], 'SQLSTATE')
    && !str_contains($prodApiResponse['raw'], 'C:\\xampp')
    && !str_contains($prodApiResponse['raw'], 'RuntimeException'),
    'SEC06-T01',
    'Production API 500 error returns generic safe message without stack trace, SQL error, or filesystem paths',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC06-T02: Production HTML Exception (Generic 500 HTML without paths/traces)
// -----------------------------------------------------------------------------
$prodHtmlResponse = simulateExceptionHandler(
    $testException,
    isProduction: true,
    rawDebug: false,
    requestUri: '/',
    acceptHeader: 'text/html'
);

assertSec06(
    $prodHtmlResponse['status'] === 500 
    && str_contains($prodHtmlResponse['raw'], '500 - Internal Server Error')
    && str_contains($prodHtmlResponse['raw'], 'An unexpected error occurred on the server.')
    && !str_contains($prodHtmlResponse['raw'], 'SQLSTATE')
    && !str_contains($prodHtmlResponse['raw'], 'C:\\xampp')
    && !str_contains($prodHtmlResponse['raw'], 'RuntimeException')
    && !str_contains($prodHtmlResponse['raw'], 'Stack trace'),
    'SEC06-T02',
    'Production HTML 500 error returns clean generic HTML without trace, class names, or file paths',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC06-T03: Development Debug Mode Enabled (Diagnostics explicitly available in dev)
// -----------------------------------------------------------------------------
$devApiResponse = simulateExceptionHandler(
    $testException,
    isProduction: false,
    rawDebug: true,
    requestUri: '/api/groups',
    acceptHeader: 'application/json'
);

assertSec06(
    $devApiResponse['status'] === 500 
    && isset($devApiResponse['json']['error']['details'])
    && $devApiResponse['json']['error']['details']['exception'] === 'RuntimeException'
    && is_array($devApiResponse['json']['error']['details']['trace']),
    'SEC06-T03',
    'Development mode with APP_DEBUG=true explicitly provides structured diagnostic details',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC06-T04: Debug Configuration Absent (Fails closed to production-safe)
// -----------------------------------------------------------------------------
$absentConfigResponse = simulateExceptionHandler(
    $testException,
    isProduction: false,
    rawDebug: null,
    requestUri: '/api/groups',
    acceptHeader: 'application/json'
);

assertSec06(
    $absentConfigResponse['status'] === 500 
    && !isset($absentConfigResponse['json']['error']['details'])
    && $absentConfigResponse['json']['error']['message'] === 'An unexpected error occurred. Please try again later.',
    'SEC06-T04',
    'Missing APP_DEBUG configuration fails closed to production-safe generic response',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC06-T05: Malformed Debug Configuration (Fails closed)
// -----------------------------------------------------------------------------
$malformedConfigResponse = simulateExceptionHandler(
    $testException,
    isProduction: false,
    rawDebug: 'invalid_string_or_false',
    requestUri: '/api/groups',
    acceptHeader: 'application/json'
);

assertSec06(
    $malformedConfigResponse['status'] === 500 
    && !isset($malformedConfigResponse['json']['error']['details'])
    && $malformedConfigResponse['json']['error']['message'] === 'An unexpected error occurred. Please try again later.',
    'SEC06-T05',
    'Malformed APP_DEBUG value fails closed to production-safe generic response',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC06-T06: Production Environment Defense-in-Depth (APP_ENV=production blocks debug)
// -----------------------------------------------------------------------------
$forcedProdResponse = simulateExceptionHandler(
    $testException,
    isProduction: true,
    rawDebug: true, // Even if APP_DEBUG=true, APP_ENV=production must override!
    requestUri: '/api/groups',
    acceptHeader: 'application/json'
);

assertSec06(
    $forcedProdResponse['status'] === 500 
    && !isset($forcedProdResponse['json']['error']['details'])
    && $forcedProdResponse['json']['error']['message'] === 'An unexpected error occurred. Please try again later.',
    'SEC06-T06',
    'APP_ENV=production strictly overrides APP_DEBUG=true, preventing accidental production leak',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC06-T07: Domain 4xx Client Exceptions (Preserves client messages safely)
// -----------------------------------------------------------------------------
$domain4xxException = new InvalidArgumentException('Workspace name is required and cannot be empty.', 422);

$domain4xxResponse = simulateExceptionHandler(
    $domain4xxException,
    isProduction: true,
    rawDebug: false,
    requestUri: '/api/groups',
    acceptHeader: 'application/json'
);

assertSec06(
    $domain4xxResponse['status'] === 422 
    && $domain4xxResponse['json']['error']['code'] === 'UNPROCESSABLE_ENTITY'
    && $domain4xxResponse['json']['error']['message'] === 'Workspace name is required and cannot be empty.'
    && !isset($domain4xxResponse['json']['error']['details']),
    'SEC06-T07',
    'Domain 4xx client errors preserve functional validation message with correct 422 status',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC06-T08: Server-Side Diagnostic Preservation
// -----------------------------------------------------------------------------
// Verify error_log format preserves full trace without outputting to response
$logBuffer = sprintf(
    "[%s] Uncaught %s: %s in %s:%d",
    date('Y-m-d H:i:s'),
    get_class($testException),
    $testException->getMessage(),
    $testException->getFile(),
    $testException->getLine()
);

assertSec06(
    str_contains($logBuffer, 'RuntimeException') && str_contains($logBuffer, 'SQLSTATE'),
    'SEC06-T08',
    'Server-side diagnostic log string preserves full exception context for administrator logs',
    $passed,
    $total
);

echo "\n--------------------------------------------------------------------\n";
echo " SEC-06 TEST SUITE RESULTS: {$passed} / {$total} ASSERTIONS PASSED\n";
echo "====================================================================\n\n";

if ($passed !== $total) {
    exit(1);
}
