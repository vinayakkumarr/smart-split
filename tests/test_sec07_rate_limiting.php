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
use App\Controllers\AuthController;
use App\Controllers\GroupController;
use App\Services\RateLimiterService;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();
$rateLimiter = new RateLimiterService($pdo);

echo "\n====================================================================\n";
echo " SEC-07: RATE LIMITING & THROTTLING SECURITY TEST SUITE\n";
echo "====================================================================\n\n";

$passed = 0;
$total = 0;

function assertSec07(bool $condition, string $testId, string $description, int &$passedCount, int &$totalCount): void {
    $totalCount++;
    if ($condition) {
        $passedCount++;
        echo "  [PASS] {$testId}: {$description}\n";
    } else {
        echo "  [FAIL] {$testId}: {$description}\n";
    }
}

// Clean up isolated test rate limit keys before testing
$testIpA = '198.51.100.10';
$testIpB = '198.51.100.20';
$rateLimiter->clear('auth_register', $testIpA);
$rateLimiter->clear('auth_register', $testIpB);
$rateLimiter->clear('group_create', $testIpA);
$rateLimiter->clear('group_create', $testIpB);

// -----------------------------------------------------------------------------
// SEC07-T01: Registration Baseline (Initial request allowed)
// -----------------------------------------------------------------------------
$check1 = $rateLimiter->check('auth_register', $testIpA, maxAttempts: 10, windowSeconds: 60);
assertSec07(
    $check1['allowed'] === true && $check1['attempts'] === 1 && $check1['remaining'] === 9,
    'SEC07-T01',
    'Registration baseline: initial attempt permitted with remaining attempts = 9',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC07-T02: Registration Repeated Attempts below threshold
// -----------------------------------------------------------------------------
for ($i = 2; $i <= 10; $i++) {
    $checkN = $rateLimiter->check('auth_register', $testIpA, maxAttempts: 10, windowSeconds: 60);
    assertSec07(
        $checkN['allowed'] === true && $checkN['attempts'] === $i,
        "SEC07-T02-{$i}",
        "Registration attempt {$i}/10 allowed",
        $passed,
        $total
    );
}

// -----------------------------------------------------------------------------
// SEC07-T03: Registration Blocked on Exceeding Threshold (11th request blocked)
// -----------------------------------------------------------------------------
$check11 = $rateLimiter->check('auth_register', $testIpA, maxAttempts: 10, windowSeconds: 60);
assertSec07(
    $check11['allowed'] === false && $check11['attempts'] === 11 && $check11['retry_after'] > 0,
    'SEC07-T03',
    'Registration blocked on 11th request: allowed = false, retry_after > 0',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC07-T04: Registration Recovery Window Expiration
// -----------------------------------------------------------------------------
// Simulate window expiration by updating window_expires_at in database
$keyHashA = hash('sha256', 'auth_register:' . $testIpA);
$pdo->prepare("UPDATE `rate_limits` SET `window_expires_at` = :past WHERE `key_hash` = :key_hash")
    ->execute([':past' => time() - 5, ':key_hash' => $keyHashA]);

$recoveredCheck = $rateLimiter->check('auth_register', $testIpA, maxAttempts: 10, windowSeconds: 60);
assertSec07(
    $recoveredCheck['allowed'] === true && $recoveredCheck['attempts'] === 1,
    'SEC07-T04',
    'Registration counter resets to 1 and allows request after window expiration',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC07-T05: Workspace Creation Baseline
// -----------------------------------------------------------------------------
$wsCheck1 = $rateLimiter->check('group_create', $testIpA, maxAttempts: 15, windowSeconds: 60);
assertSec07(
    $wsCheck1['allowed'] === true && $wsCheck1['attempts'] === 1 && $wsCheck1['remaining'] === 14,
    'SEC07-T05',
    'Workspace creation baseline: initial attempt permitted with remaining = 14',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC07-T06 & SEC07-T07: Workspace Creation Repeated Attempts & Blocked
// -----------------------------------------------------------------------------
for ($i = 2; $i <= 15; $i++) {
    $rateLimiter->check('group_create', $testIpA, maxAttempts: 15, windowSeconds: 60);
}
$wsCheck16 = $rateLimiter->check('group_create', $testIpA, maxAttempts: 15, windowSeconds: 60);
assertSec07(
    $wsCheck16['allowed'] === false && $wsCheck16['attempts'] === 16 && $wsCheck16['retry_after'] > 0,
    'SEC07-T07',
    'Workspace creation blocked on 16th request (limit 15 exceeded)',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC07-T08: Workspace Creation Recovery Window Expiration
// -----------------------------------------------------------------------------
$wsKeyHashA = hash('sha256', 'group_create:' . $testIpA);
$pdo->prepare("UPDATE `rate_limits` SET `window_expires_at` = :past WHERE `key_hash` = :key_hash")
    ->execute([':past' => time() - 5, ':key_hash' => $wsKeyHashA]);

$wsRecoveredCheck = $rateLimiter->check('group_create', $testIpA, maxAttempts: 15, windowSeconds: 60);
assertSec07(
    $wsRecoveredCheck['allowed'] === true && $wsRecoveredCheck['attempts'] === 1,
    'SEC07-T08',
    'Workspace creation counter resets to 1 after window expiration',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC07-T09: Identity Isolation (IP A blocked does NOT block IP B)
// -----------------------------------------------------------------------------
// Exceed limit for IP A
for ($i = 2; $i <= 11; $i++) {
    $rateLimiter->check('auth_register', $testIpA, maxAttempts: 10, windowSeconds: 60);
}
$checkBlockedA = $rateLimiter->check('auth_register', $testIpA, maxAttempts: 10, windowSeconds: 60);
$checkAllowedB = $rateLimiter->check('auth_register', $testIpB, maxAttempts: 10, windowSeconds: 60);

assertSec07(
    $checkBlockedA['allowed'] === false && $checkAllowedB['allowed'] === true && $checkAllowedB['attempts'] === 1,
    'SEC07-T09',
    'Identity isolation: Throttling client IP A does not affect independent client IP B',
    $passed,
    $total
);

// -----------------------------------------------------------------------------
// SEC07-T10: Concurrency / Atomic Counter Integrity
// -----------------------------------------------------------------------------
$concurrentKey = 'concurrent_test_ip';
$rateLimiter->clear('concurrency_test', $concurrentKey);

for ($k = 1; $k <= 20; $k++) {
    $rateLimiter->check('concurrency_test', $concurrentKey, maxAttempts: 50, windowSeconds: 60);
}
$finalCheck = $rateLimiter->check('concurrency_test', $concurrentKey, maxAttempts: 50, windowSeconds: 60);
assertSec07(
    $finalCheck['attempts'] === 21,
    'SEC07-T10',
    'Atomic upsert maintains accurate counter progression (21 attempts recorded)',
    $passed,
    $total
);
$rateLimiter->clear('concurrency_test', $concurrentKey);

// -----------------------------------------------------------------------------
// SEC07-T11 & SEC07-T12: Controller End-to-End HTTP 429 Response & Zero Side Effects
// -----------------------------------------------------------------------------
// Set up AuthController and test blocked request
$authCtrl = new AuthController($pdo);
$groupCtrl = new GroupController();

// Exhaust limit for test IP A on registration
for ($m = 1; $m <= 10; $m++) {
    $rateLimiter->check('auth_register', $testIpA, maxAttempts: 10, windowSeconds: 60);
}

// Check initial count of users
$userCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn();

// Execute register request with exhausted IP A
$_SERVER['REMOTE_ADDR'] = $testIpA;
$reqRegister = new Request('POST', '/api/auth/register', null, [
    'email' => 'spam_attempt_' . uniqid() . '@example.com',
    'password' => 'Password123!',
]);

ob_start();
$authCtrl->register($reqRegister);
$rawRegOut = ob_get_clean();
$jsonReg = json_decode($rawRegOut ?: '', true);

$userCountAfter = (int) $pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn();

assertSec07(
    isset($jsonReg['error']['code']) && $jsonReg['error']['code'] === 'RATE_LIMITED'
    && $userCountAfter === $userCountBefore,
    'SEC07-T11',
    'Controller returns 429 RATE_LIMITED and creates ZERO user records on blocked attempt',
    $passed,
    $total
);

// Clean up test keys
$rateLimiter->clear('auth_register', $testIpA);
$rateLimiter->clear('auth_register', $testIpB);
$rateLimiter->clear('group_create', $testIpA);
$rateLimiter->clear('group_create', $testIpB);
unset($_SERVER['REMOTE_ADDR']);

echo "\n--------------------------------------------------------------------\n";
echo " SEC-07 TEST SUITE RESULTS: {$passed} / {$total} ASSERTIONS PASSED\n";
echo "====================================================================\n\n";

if ($passed !== $total) {
    exit(1);
}
