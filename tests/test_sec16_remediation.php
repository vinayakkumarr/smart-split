<?php

declare(strict_types=1);

/**
 * Smart Split V2 — SEC-16 Post-Remediation Verification Suite
 *
 * Validates:
 * 1. SEC16-01: Removal of wildcard CORS header from SSE stream endpoint.
 * 2. SEC16-01: Verification of valid SSE streaming, correct headers, and 404 on invalid tokens.
 * 3. SEC16-02: Verification of .gitignore rule for /storage/receipts/* and preservation of .gitkeep.
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Middleware\SecurityHeadersMiddleware;
use App\Core\Middleware\AuthSessionMiddleware;
use App\Controllers\EventController;
use App\Controllers\GroupController;
use App\Repositories\GroupRepository;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

echo "\n================================================================================\n";
echo " SEC-16: POST-REMEDIATION VERIFICATION SUITE\n";
echo "================================================================================\n\n";

$passed = 0;
$total = 0;
$failures = [];

function assertSec16(bool $condition, string $testId, string $description, ?string $details = null): void {
    global $passed, $total, $failures;
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$testId}: {$description}\n";
    } else {
        echo "  [FAIL] {$testId}: {$description}\n";
        if ($details) {
            echo "         > Details: {$details}\n";
        }
        $failures[] = [
            'testId' => $testId,
            'description' => $description,
            'details' => $details,
        ];
    }
}

// Router Setup
$router = new Router();
$router->use(new SecurityHeadersMiddleware());
$router->use(new AuthSessionMiddleware($pdo));
$router->post('/api/groups', [\App\Controllers\GroupController::class, 'create']);
$router->get('/api/groups/{token}/events', [\App\Controllers\EventController::class, 'stream']);

// Setup Test Group Fixture using GroupRepository
$groupRepo = new GroupRepository($pdo);
$group = $groupRepo->create('SEC16 Verification Group', 'INR');
$groupToken = (string) $group['invite_token'];
$groupId = (int) $group['id'];

// -----------------------------------------------------------------------------
// DOMAIN 1: SEC16-01 — SSE Stream Endpoint & CORS Verification
// -----------------------------------------------------------------------------
echo "--- Domain 1: SEC16-01 SSE Endpoint CORS & Stream Verification ---\n";

// 1. Inspect EventController.php directly to guarantee wildcard CORS is removed
$eventCtrlCode = file_get_contents(dirname(__DIR__) . '/src/Controllers/EventController.php');
assertSec16(
    !str_contains($eventCtrlCode, 'Access-Control-Allow-Origin'),
    'SEC16.REM.1',
    'EventController::stream() does NOT contain wildcard Access-Control-Allow-Origin header'
);

// 2. Test valid SSE stream execution (once mode)
$sseReq = new Request('GET', "/api/groups/{$groupToken}/events", ['once' => '1', 'last_event_id' => '0']);
ob_start();
$router->dispatch($sseReq);
$sseOutput = ob_get_clean();

assertSec16(
    str_contains($sseOutput, ': connected'),
    'SEC16.REM.2',
    'SSE stream connects successfully and emits initial : connected ACK comment'
);
assertSec16(
    str_contains($sseOutput, 'retry: 2000'),
    'SEC16.REM.3',
    'SSE stream emits client reconnect retry directive (retry: 2000)'
);

// 3. Test Poll mode fallback (returns JSON event feed)
$pollReq = new Request('GET', "/api/groups/{$groupToken}/events", ['poll' => '1', 'last_event_id' => '0']);
ob_start();
$router->dispatch($pollReq);
$pollOutput = ob_get_clean();
$pollJson = json_decode($pollOutput, true);

assertSec16(
    isset($pollJson['success']) && $pollJson['success'] === true && isset($pollJson['data']['events']),
    'SEC16.REM.4',
    'SSE poll mode fallback returns standard success JSON envelope with events array'
);

// 4. Test Invalid Workspace Token (returns 404 NOT_FOUND)
$badTokenReq = new Request('GET', '/api/groups/invalid_tok_99999/events', ['poll' => '1']);
ob_start();
$router->dispatch($badTokenReq);
$badOutput = ob_get_clean();
$badJson = json_decode($badOutput, true);

assertSec16(
    isset($badJson['success']) && $badJson['success'] === false && $badJson['error']['code'] === 'NOT_FOUND',
    'SEC16.REM.5',
    'SSE stream for invalid workspace token rejects request cleanly with HTTP 404 NOT_FOUND'
);

// -----------------------------------------------------------------------------
// DOMAIN 2: SEC16-02 — .gitignore Storage Receipts Rule Verification
// -----------------------------------------------------------------------------
echo "\n--- Domain 2: SEC16-02 .gitignore Receipt Storage Exclusion ---\n";

$gitignoreContent = file_get_contents(dirname(__DIR__) . '/.gitignore');

assertSec16(
    str_contains($gitignoreContent, '/storage/receipts/*'),
    'SEC16.REM.6',
    '.gitignore contains explicit rule: /storage/receipts/*'
);
assertSec16(
    str_contains($gitignoreContent, '!/storage/receipts/.gitkeep'),
    'SEC16.REM.7',
    '.gitignore contains exclusion for directory anchor: !/storage/receipts/.gitkeep'
);

// Test temporary receipt file simulation
$tempReceiptPath = dirname(__DIR__) . '/storage/receipts/temp_sec16_test_receipt.png';
file_put_contents($tempReceiptPath, 'fake_png_data');

assertSec16(
    file_exists($tempReceiptPath),
    'SEC16.REM.8',
    'Temporary receipt file created successfully under storage/receipts/'
);

// Cleanup temporary receipt
if (file_exists($tempReceiptPath)) {
    unlink($tempReceiptPath);
}

assertSec16(
    !file_exists($tempReceiptPath),
    'SEC16.REM.9',
    'Temporary receipt file safely cleaned up with zero test artifacts left in storage'
);

// -----------------------------------------------------------------------------
// CLEANUP TEST FIXTURES
// -----------------------------------------------------------------------------
try {
    $pdo->exec("DELETE FROM `groups` WHERE `id` = {$groupId}");
} catch (\Throwable $e) {}

// -----------------------------------------------------------------------------
// SUMMARY & VERDICT
// -----------------------------------------------------------------------------
echo "\n================================================================================\n";
echo " SEC-16 POST-REMEDIATION VERIFICATION SUMMARY\n";
echo "================================================================================\n";
echo " Total Assertions  : {$total}\n";
echo " Passed Assertions : {$passed}\n";
echo " Failed Assertions : " . count($failures) . "\n";
echo " Pass Rate         : " . sprintf('%.2f%%', ($passed / max(1, $total)) * 100) . "\n";
echo "================================================================================\n\n";

if (!empty($failures)) {
    echo "FAILED ASSERTIONS:\n";
    foreach ($failures as $f) {
        echo " - [{$f['testId']}] {$f['description']}\n";
    }
    exit(1);
}

echo ">>> VERDICT: SEC-16 SURGICAL REMEDIATION VERIFIED: 100% PASS <<<\n";
exit(0);
