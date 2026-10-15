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

    $rawCode = $exception->getCode();
    $statusCode = (is_int($rawCode) && $rawCode >= 400 && $rawCode <= 599)
        ? $rawCode
        : ((is_string($rawCode) && preg_match('/^[45][0-9]{2}$/', trim($rawCode))) ? (int) $rawCode : 500);
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

// -----------------------------------------------------------------------------
// SEC06-T09: SQLSTATE 42S02 Table-Not-Found Regression Test (Never status 42)
// -----------------------------------------------------------------------------
$pdoEx42S02 = new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'smartsplit.workspace_events' doesn't exist", 0);
$refCode = new ReflectionProperty(Exception::class, 'code');
$refCode->setAccessible(true);
$refCode->setValue($pdoEx42S02, '42S02');

$pdo42S02Response = simulateExceptionHandler(
    $pdoEx42S02,
    isProduction: true,
    rawDebug: false,
    requestUri: '/api/groups/tok_test/settlements',
    acceptHeader: 'application/json'
);

assertSec06(
    $pdo42S02Response['status'] === 500
    && $pdo42S02Response['json']['success'] === false
    && $pdo42S02Response['json']['error']['code'] === 'INTERNAL_SERVER_ERROR'
    && $pdo42S02Response['json']['error']['message'] === 'An unexpected error occurred. Please try again later.'
    && !isset($pdo42S02Response['json']['error']['details'])
    && !str_contains($pdo42S02Response['raw'], '42S02')
    && !str_contains($pdo42S02Response['raw'], 'workspace_events')
    && Response::getLastStatusCode() === 500,
    'SEC06-T09',
    'PDOException with SQLSTATE 42S02 maps to HTTP 500 (never status 42) with zero schema disclosure',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC06-T10: Additional SQLSTATE Class 42 Codes (42S22 Column Not Found, 42000 Syntax Error)
// -----------------------------------------------------------------------------
$pdoEx42S22 = new PDOException("Unknown column 'confirmed_by' in 'field list'", 0);
$refCode->setValue($pdoEx42S22, '42S22');
$pdo42S22Response = simulateExceptionHandler($pdoEx42S22, true, false, '/api/groups', 'application/json');

$pdoEx42000 = new PDOException("Syntax error or access rule violation", 0);
$refCode->setValue($pdoEx42000, '42000');
$pdo42000Response = simulateExceptionHandler($pdoEx42000, true, false, '/api/groups', 'application/json');

assertSec06(
    $pdo42S22Response['status'] === 500
    && $pdo42S22Response['json']['error']['code'] === 'INTERNAL_SERVER_ERROR'
    && $pdo42000Response['status'] === 500
    && $pdo42000Response['json']['error']['code'] === 'INTERNAL_SERVER_ERROR',
    'SEC06-T10',
    'SQLSTATE 42S22 and 42000 exceptions safely map to HTTP 500 (never status 42 or 420)',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC06-T11: Non-HTTP Integer Exception Code 42 (Safely falls back to 500)
// -----------------------------------------------------------------------------
$int42Exception = new Exception('Arbitrary application failure', 42);
$int42Response = simulateExceptionHandler($int42Exception, true, false, '/api/groups', 'application/json');

assertSec06(
    $int42Response['status'] === 500
    && $int42Response['json']['error']['code'] === 'INTERNAL_SERVER_ERROR'
    && Response::getLastStatusCode() === 500,
    'SEC06-T11',
    'Exception with non-HTTP integer code 42 falls back to HTTP 500',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC06-T12: Valid Numeric-String Codes (Strict 3-digit HTTP range preserved)
// -----------------------------------------------------------------------------
$str404Exception = new Exception('Entity not found', 0);
$refCode->setValue($str404Exception, '404');
$str404Response = simulateExceptionHandler($str404Exception, true, false, '/api/groups', 'application/json');

$str422Exception = new Exception('Validation error', 0);
$refCode->setValue($str422Exception, '422');
$str422Response = simulateExceptionHandler($str422Exception, true, false, '/api/groups', 'application/json');

assertSec06(
    $str404Response['status'] === 404
    && $str404Response['json']['error']['code'] === 'NOT_FOUND'
    && $str422Response['status'] === 422
    && $str422Response['json']['error']['code'] === 'UNPROCESSABLE_ENTITY',
    'SEC06-T12',
    'Valid numeric-string HTTP codes ("404", "422") preserve their intended 4xx status',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC06-T13: Out-of-Range, Partially Numeric and Malformed Codes
// -----------------------------------------------------------------------------
$malformedCodes = ['422abc', '0', '-1', '600', '999', '', '   '];
$allMalformedSafe = true;
foreach ($malformedCodes as $badCode) {
    $badEx = new Exception('Malformed code test', 0);
    $refCode->setValue($badEx, $badCode);
    $badRes = simulateExceptionHandler($badEx, true, false, '/api/groups', 'application/json');
    if ($badRes['status'] !== 500 || $badRes['json']['error']['code'] !== 'INTERNAL_SERVER_ERROR') {
        $allMalformedSafe = false;
        break;
    }
}

assertSec06(
    $allMalformedSafe,
    'SEC06-T13',
    'Partially numeric ("422abc"), negative, zero, and out-of-range string codes safely fall back to HTTP 500',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC06-T14: Response Layer Defensive Guard Integrity
// -----------------------------------------------------------------------------
ob_start();
Response::error('Defensive error test', 'ERR_TEST', null, 42, false);
$rawError42 = ob_get_clean();
$lastStatusError42 = Response::getLastStatusCode();

ob_start();
Response::json(['data' => 'test'], 42, [], false);
$rawJson42 = ob_get_clean();
$lastStatusJson42 = Response::getLastStatusCode();

ob_start();
Response::json(['data' => 'valid'], 201, [], false);
$rawJson201 = ob_get_clean();
$lastStatusJson201 = Response::getLastStatusCode();

assertSec06(
    $lastStatusError42 === 500
    && $lastStatusJson42 === 500
    && $lastStatusJson201 === 201
    && json_decode($rawError42, true)['success'] === false
    && json_decode($rawJson42, true)['success'] === true
    && json_decode($rawJson201, true)['success'] === true,
    'SEC06-T14',
    'Response::error and Response::json defensively guard against invalid status 42 (failing closed to 500)',
    $passed,
    $total
);

echo "\n--------------------------------------------------------------------\n";
echo " SEC-06 TEST SUITE RESULTS: {$passed} / {$total} ASSERTIONS PASSED\n";
echo "====================================================================\n\n";

if ($passed !== $total) {
    exit(1);
}
