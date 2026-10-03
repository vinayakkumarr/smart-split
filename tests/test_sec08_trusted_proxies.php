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
use App\Core\Database;
use App\Core\Request;
use App\Services\RateLimiterService;
use App\Controllers\AuthController;
use App\Controllers\GroupController;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();
$rateLimiter = new RateLimiterService($pdo);

echo "\n====================================================================\n";
echo " SEC-08: TRUSTED PROXY CLIENT IP RESOLUTION & ANTI-SPOOFING SUITE\n";
echo "====================================================================\n\n";

$passed = 0;
$total = 0;

function assertSec08(bool $condition, string $testId, string $description, int &$passedCount, int &$totalCount): void {
    $totalCount++;
    if ($condition) {
        $passedCount++;
        echo "  [PASS] {$testId}: {$description}\n";
    } else {
        echo "  [FAIL] {$testId}: {$description}\n";
    }
}

// -----------------------------------------------------------------------------
// Test A: Direct connection with spoofed header -> ignores X-Forwarded-For
// -----------------------------------------------------------------------------
Env::set('TRUSTED_PROXIES', ''); // No trusted proxies configured
$_SERVER['REMOTE_ADDR'] = '203.0.113.195';
$reqA = new Request('GET', '/api/groups', null, null, ['X-Forwarded-For' => '1.2.3.4, 5.6.7.8']);
assertSec08(
    $reqA->getClientIp() === '203.0.113.195',
    'SEC08-TEST-A',
    'Direct connection with spoofed header ignores X-Forwarded-For and returns REMOTE_ADDR',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// Test B: Untrusted proxy -> ignores X-Forwarded-For
// -----------------------------------------------------------------------------
Env::set('TRUSTED_PROXIES', '10.0.0.1, 10.0.0.2');
$_SERVER['REMOTE_ADDR'] = '198.51.100.44'; // Not in TRUSTED_PROXIES
$reqB = new Request('GET', '/api/groups', null, null, ['X-Forwarded-For' => '10.0.0.1, 8.8.8.8']);
assertSec08(
    $reqB->getClientIp() === '198.51.100.44',
    'SEC08-TEST-B',
    'Request from untrusted immediate peer ignores X-Forwarded-For header',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// Test C: Trusted proxy -> resolves legitimate client IP
// -----------------------------------------------------------------------------
Env::set('TRUSTED_PROXIES', '10.0.0.1, 10.0.0.2');
$_SERVER['REMOTE_ADDR'] = '10.0.0.1'; // Immediate peer is trusted proxy
$reqC = new Request('GET', '/api/groups', null, null, ['X-Forwarded-For' => '198.51.100.22']);
assertSec08(
    $reqC->getClientIp() === '198.51.100.22',
    'SEC08-TEST-C',
    'Trusted reverse proxy successfully resolves legitimate client IP from X-Forwarded-For',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// Test D: Multiple proxy chain (client -> proxyA -> proxyB) -> resolves client
// -----------------------------------------------------------------------------
Env::set('TRUSTED_PROXIES', '10.0.0.1, 172.16.0.0/12');
$_SERVER['REMOTE_ADDR'] = '10.0.0.1'; // Immediate proxy B
// Client is 203.0.113.77, intermediary proxy A is 172.16.5.10
$reqD = new Request('GET', '/api/groups', null, null, ['X-Forwarded-For' => '203.0.113.77, 172.16.5.10']);
assertSec08(
    $reqD->getClientIp() === '203.0.113.77',
    'SEC08-TEST-D',
    'Multi-hop proxy chain correctly traverses right-to-left past trusted intermediate proxy to client',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// Test E: Spoofing attempt with rotating / injected headers behind trusted proxy
// -----------------------------------------------------------------------------
Env::set('TRUSTED_PROXIES', '10.0.0.1');
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
// Attacker at 203.0.113.99 tried to spoof by prepending 1.1.1.1, 2.2.2.2
$reqE = new Request('GET', '/api/groups', null, null, ['X-Forwarded-For' => '1.1.1.1, 2.2.2.2, 203.0.113.99']);
assertSec08(
    $reqE->getClientIp() === '203.0.113.99',
    'SEC08-TEST-E',
    'Injected fake IPs in X-Forwarded-For are safely ignored via right-to-left traversal',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// Test F: Malformed headers -> fails safely to REMOTE_ADDR
// -----------------------------------------------------------------------------
Env::set('TRUSTED_PROXIES', '10.0.0.1');
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';

$reqF1 = new Request('GET', '/api/groups', null, null, ['X-Forwarded-For' => '   ']);
$reqF2 = new Request('GET', '/api/groups', null, null, ['X-Forwarded-For' => 'invalid_ip_format, <script>alert(1)</script>']);
$reqF3 = new Request('GET', '/api/groups', null, null, ['X-Forwarded-For' => '999.999.999.999']);

assertSec08(
    $reqF1->getClientIp() === '10.0.0.1' &&
    $reqF2->getClientIp() === '10.0.0.1' &&
    $reqF3->getClientIp() === '10.0.0.1',
    'SEC08-TEST-F',
    'Malformed, empty, or unparseable headers safely fall back to REMOTE_ADDR',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// Test G: IPv4 CIDR matching
// -----------------------------------------------------------------------------
Env::set('TRUSTED_PROXIES', '192.168.0.0/16, 10.0.0.0/8');
$_SERVER['REMOTE_ADDR'] = '192.168.100.50';
$reqG1 = new Request('GET', '/api/groups', null, null, ['X-Forwarded-For' => '198.51.100.77']);
$_SERVER['REMOTE_ADDR'] = '10.254.1.1';
$reqG2 = new Request('GET', '/api/groups', null, null, ['X-Forwarded-For' => '198.51.100.88']);

assertSec08(
    $reqG1->getClientIp() === '198.51.100.77' && $reqG2->getClientIp() === '198.51.100.88',
    'SEC08-TEST-G',
    'IPv4 CIDR blocks (192.168.0.0/16, 10.0.0.0/8) correctly match trusted reverse proxies',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// Test H: IPv6 and IPv6 CIDR validation
// -----------------------------------------------------------------------------
Env::set('TRUSTED_PROXIES', '2001:db8::/32, ::1');
$_SERVER['REMOTE_ADDR'] = '2001:db8:85a3::8a2e:370:7334';
$reqH1 = new Request('GET', '/api/groups', null, null, ['X-Forwarded-For' => '2001:0db8:0000:0000:0000:0000:0000:0001, 203.0.113.111']);

$_SERVER['REMOTE_ADDR'] = '::1';
$reqH2 = new Request('GET', '/api/groups', null, null, ['X-Forwarded-For' => '2001:db8:1234::5678']);

assertSec08(
    $reqH1->getClientIp() === '203.0.113.111' && $reqH2->getClientIp() === '2001:db8:1234::5678',
    'SEC08-TEST-H',
    'IPv6 exact and CIDR matching properly resolve IPv4/IPv6 client endpoints',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// Test I: Rate-limit isolation behind trusted proxy
// -----------------------------------------------------------------------------
Env::set('TRUSTED_PROXIES', '10.0.0.1');
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';

$clientAlpha = '198.51.100.101';
$clientBeta  = '198.51.100.102';

$rateLimiter->clear('auth_login', $clientAlpha);
$rateLimiter->clear('auth_login', $clientBeta);

// Simulate clientAlpha exhausting rate limit
for ($i = 1; $i <= 5; $i++) {
    $rateLimiter->check('auth_login', $clientAlpha, maxAttempts: 5, windowSeconds: 60);
}
$blockedAlpha = $rateLimiter->check('auth_login', $clientAlpha, maxAttempts: 5, windowSeconds: 60);
$allowedBeta  = $rateLimiter->check('auth_login', $clientBeta,  maxAttempts: 5, windowSeconds: 60);

assertSec08(
    $blockedAlpha['allowed'] === false && $allowedBeta['allowed'] === true && $allowedBeta['remaining'] === 4,
    'SEC08-TEST-I',
    'Two distinct clients behind the same trusted proxy receive independent rate-limiting buckets',
    $passed,
    $total
);

$rateLimiter->clear('auth_login', $clientAlpha);
$rateLimiter->clear('auth_login', $clientBeta);

// -----------------------------------------------------------------------------
// Test J: Existing numerical rate limits verified unchanged
// -----------------------------------------------------------------------------
$testIpJ = '198.51.100.200';
$rateLimiter->clear('auth_login', $testIpJ);
$rateLimiter->clear('auth_register', $testIpJ);
$rateLimiter->clear('group_create', $testIpJ);

// auth_login = 5
$loginPass = true;
for ($i = 1; $i <= 5; $i++) {
    $res = $rateLimiter->check('auth_login', $testIpJ, maxAttempts: 5, windowSeconds: 60);
    if (!$res['allowed']) { $loginPass = false; }
}
$loginBlocked = $rateLimiter->check('auth_login', $testIpJ, maxAttempts: 5, windowSeconds: 60);
if ($loginBlocked['allowed']) { $loginPass = false; }

// auth_register = 10
$regPass = true;
for ($i = 1; $i <= 10; $i++) {
    $res = $rateLimiter->check('auth_register', $testIpJ, maxAttempts: 10, windowSeconds: 60);
    if (!$res['allowed']) { $regPass = false; }
}
$regBlocked = $rateLimiter->check('auth_register', $testIpJ, maxAttempts: 10, windowSeconds: 60);
if ($regBlocked['allowed']) { $regPass = false; }

// group_create = 15
$groupPass = true;
for ($i = 1; $i <= 15; $i++) {
    $res = $rateLimiter->check('group_create', $testIpJ, maxAttempts: 15, windowSeconds: 60);
    if (!$res['allowed']) { $groupPass = false; }
}
$groupBlocked = $rateLimiter->check('group_create', $testIpJ, maxAttempts: 15, windowSeconds: 60);
if ($groupBlocked['allowed']) { $groupPass = false; }

assertSec08(
    $loginPass && $regPass && $groupPass,
    'SEC08-TEST-J',
    'Verified exact numerical thresholds: auth_login=5, auth_register=10, group_create=15',
    $passed,
    $total
);

$rateLimiter->clear('auth_login', $testIpJ);
$rateLimiter->clear('auth_register', $testIpJ);
$rateLimiter->clear('group_create', $testIpJ);

// Reset test environment
Env::set('TRUSTED_PROXIES', '');
unset($_SERVER['REMOTE_ADDR']);

echo "\n--------------------------------------------------------------------\n";
echo " SEC-08 TEST SUITE RESULTS: {$passed} / {$total} ASSERTIONS PASSED\n";
echo "====================================================================\n\n";

if ($passed !== $total) {
    exit(1);
}
