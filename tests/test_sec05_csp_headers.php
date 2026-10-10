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
require_once __DIR__ . '/../src/Core/Database.php';

use App\Core\Env;
use App\Core\Request;
use App\Core\Middleware\SecurityHeadersMiddleware;

Env::load(dirname(__DIR__) . '/.env');

$baseUrl = 'http://127.0.0.1:8000';

function getHttpHeaders(string $url): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerStr = substr((string) $res, 0, $headerSize);
    $body = substr((string) $res, $headerSize);
    curl_close($ch);
    
    // Parse headers into associative array
    $headers = [];
    foreach (explode("\r\n", $headerStr) as $line) {
        if (str_contains($line, ':')) {
            [$key, $val] = explode(':', $line, 2);
            $headers[trim(strtolower($key))] = trim($val);
        }
    }
    
    return [
        'status' => $code,
        'headers' => $headers,
        'raw_headers' => $headerStr,
        'body' => $body,
    ];
}

echo "\n====================================================================\n";
echo " SEC-05: CONTENT SECURITY POLICY HARDENING SECURITY TEST SUITE\n";
echo "====================================================================\n\n";

$passed = 0;
$total = 0;

function assertSec05(bool $condition, string $testId, string $description, int &$passedCount, int &$totalCount): void {
    $totalCount++;
    if ($condition) {
        $passedCount++;
        echo "  [PASS] {$testId}: {$description}\n";
    } else {
        echo "  [FAIL] {$testId}: {$description}\n";
    }
}

// -----------------------------------------------------------------------------
// SEC05-T01: Legitimate Operation – Health & Root endpoints return 200 with headers
// -----------------------------------------------------------------------------
$resHealth = getHttpHeaders($baseUrl . '/api/health');
assertSec05(
    $resHealth['status'] === 200 && isset($resHealth['headers']['content-security-policy']),
    'SEC05-T01',
    'Legitimate request to /api/health returns HTTP 200 and includes Content-Security-Policy header',
    $passed,
    $total
);

$resRoot = getHttpHeaders($baseUrl . '/');
assertSec05(
    $resRoot['status'] === 200 && isset($resRoot['headers']['content-security-policy']),
    'SEC05-T01b',
    'Legitimate request to SPA root / returns HTTP 200 and includes Content-Security-Policy header',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC05-T02: CSP Header Presence, Class Constant & Valid Directives
// -----------------------------------------------------------------------------
$cspHeader = $resHealth['headers']['content-security-policy'] ?? '';
assertSec05(
    !empty($cspHeader) && str_starts_with($cspHeader, "default-src 'self'"),
    'SEC05-T02',
    'Content-Security-Policy begins with default-src \'self\'',
    $passed,
    $total
);

assertSec05(
    defined(SecurityHeadersMiddleware::class . '::CSP_POLICY') && !empty(SecurityHeadersMiddleware::CSP_POLICY),
    'SEC05-T02b',
    'SecurityHeadersMiddleware defines authoritative CSP_POLICY constant',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC05-T03: 'unsafe-inline' strictly absent from script-src
// -----------------------------------------------------------------------------
// Extract script-src directive
preg_match('/script-src\s+([^;]+)/', $cspHeader, $scriptSrcMatches);
$scriptSrc = $scriptSrcMatches[1] ?? '';

assertSec05(
    str_contains($scriptSrc, "'self'") && !str_contains($scriptSrc, "'unsafe-inline'"),
    'SEC05-T03',
    'script-src allows \'self\' and strictly prohibits \'unsafe-inline\'',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC05-T04: Framing & Clickjacking Protection Directives
// -----------------------------------------------------------------------------
assertSec05(
    str_contains($cspHeader, "frame-ancestors 'none'") && ($resHealth['headers']['x-frame-options'] ?? '') === 'DENY',
    'SEC05-T04',
    'Enforces both frame-ancestors \'none\' (CSP) and X-Frame-Options: DENY',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC05-T05: Injection Mitigation Directives (object-src, base-uri, form-action)
// -----------------------------------------------------------------------------
assertSec05(
    str_contains($cspHeader, "object-src 'none'") 
    && str_contains($cspHeader, "base-uri 'self'") 
    && str_contains($cspHeader, "form-action 'self'"),
    'SEC05-T05',
    'Enforces object-src \'none\', base-uri \'self\', and form-action \'self\'',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC05-T06: Original Vulnerability Condition Verification
// -----------------------------------------------------------------------------
$vulnerablePattern = "script-src 'self' 'unsafe-inline'";
assertSec05(
    !str_contains($cspHeader, $vulnerablePattern) && !str_contains(SecurityHeadersMiddleware::CSP_POLICY, $vulnerablePattern),
    'SEC05-T06',
    'Original vulnerable policy (script-src \'self\' \'unsafe-inline\') is completely eliminated',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC05-T07: SPA HTML Shell Hygiene (Zero inline <script> tags)
// -----------------------------------------------------------------------------
$indexHtml = file_get_contents(dirname(__DIR__) . '/public/index.php');
// Find any <script> tags without src attribute
preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*>(.*?)<\/script>/is', $indexHtml, $inlineScriptMatches);
$inlineScripts = array_filter($inlineScriptMatches[1], fn($c) => trim($c) !== '');

assertSec05(
    count($inlineScripts) === 0,
    'SEC05-T07',
    'public/index.php contains 0 un-externalized inline <script> blocks',
    $passed,
    $total
);

assertSec05(
    str_contains($indexHtml, 'src="/assets/js/theme-init.js"') && str_contains($indexHtml, 'src="/assets/js/pwa-init.js'),
    'SEC05-T07b',
    'public/index.php references externalized theme-init.js and pwa-init.js',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC05-T08: Neighboring Functionality & External Static Assets Accessibility
// -----------------------------------------------------------------------------
$themeInitRes = getHttpHeaders($baseUrl . '/assets/js/theme-init.js');
$pwaInitRes = getHttpHeaders($baseUrl . '/assets/js/pwa-init.js');
$appJsRes = getHttpHeaders($baseUrl . '/assets/js/app.js');

assertSec05(
    $themeInitRes['status'] === 200 && str_contains($themeInitRes['body'], 'smartsplit_theme'),
    'SEC05-T08a',
    '/assets/js/theme-init.js is accessible via HTTP 200 and contains theme logic',
    $passed,
    $total
);

assertSec05(
    $pwaInitRes['status'] === 200 && str_contains($pwaInitRes['body'], 'serviceWorker'),
    'SEC05-T08b',
    '/assets/js/pwa-init.js is accessible via HTTP 200 and contains PWA registration',
    $passed,
    $total
);

assertSec05(
    $appJsRes['status'] === 200 && str_contains($appJsRes['body'], 'SmartSplit'),
    'SEC05-T08c',
    '/assets/js/app.js is accessible via HTTP 200 and boots SPA cleanly',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// Direct Middleware Execution Unit Test
// -----------------------------------------------------------------------------
$middleware = new SecurityHeadersMiddleware();
$req = new Request('GET', '/api/test');
$middleware($req);
assertSec05(
    true,
    'SEC05-UNIT',
    'SecurityHeadersMiddleware executes unit invocation without runtime errors',
    $passed,
    $total
);

echo "\n--------------------------------------------------------------------\n";
echo " SEC-05 TEST SUITE RESULTS: {$passed} / {$total} ASSERTIONS PASSED\n";
echo "====================================================================\n\n";

if ($passed !== $total) {
    exit(1);
}
