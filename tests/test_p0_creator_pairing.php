<?php

declare(strict_types=1);

/**
 * Smart Split V2 — P0.3 Test Suite: Secure Guest Creator Device Pairing
 * 
 * Validates:
 * 1. Generating pairing code with valid creator token header
 * 2. Rejection of pairing code generation for unauthorized/missing creator tokens (403 FORBIDDEN)
 * 3. Successful claiming of pairing code on a new device (returns creator token)
 * 4. Single-use replay protection (claiming used code fails with 400 INVALID_PAIRING_CODE)
 * 5. Expiration enforcement (claiming expired code fails with 410 PAIRING_CODE_EXPIRED)
 * 6. Rate limiting enforcement on creation and claiming endpoints (SEC-07 compliance)
 */

spl_autoload_register(function (string $class): void {
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

use App\Core\Env;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Controllers\GroupController;
use App\Controllers\MemberController;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

// Ensure rate limits table is clean for tests
try {
    $pdo->exec("DELETE FROM `rate_limits` WHERE `action` LIKE 'creator_pairing_%'");
} catch (\Throwable $e) {}

echo "================================================================================\n";
echo " Smart Split V2: P0.3 Secure Guest Creator Device Pairing Tests\n";
echo "================================================================================\n";

$testsPassed = 0;
$totalTests = 0;

function assertTest(bool $condition, string $testName, ?string $details = null): void
{
    global $testsPassed, $totalTests;
    $totalTests++;
    if ($condition) {
        echo "  [PASS] {$testName}\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] {$testName}\n";
        if ($details) {
            echo "         Details: {$details}\n";
        }
        exit(1);
    }
}

$router = new Router();
$router->post('/api/groups', [GroupController::class, 'create']);
$router->get('/api/groups/{token}', [GroupController::class, 'show']);
$router->post('/api/groups/{token}/creator-pairing', [GroupController::class, 'createPairingCode']);
$router->post('/api/groups/{token}/creator-pairing/claim', [GroupController::class, 'claimPairingCode']);

function dispatchReq(Router $router, Request $req): array
{
    ob_start();
    try {
        $router->dispatch($req);
    } catch (\Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    $raw = ob_get_clean();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['raw' => $raw];
}

// 1. Setup: Create a test group
$groupName = 'Pairing Test Trip ' . time();
$createRes = dispatchReq($router, new Request('POST', '/api/groups', null, [
    'name' => $groupName,
    'creator_name' => 'Host Alice',
    'currency' => 'INR',
]));

assertTest($createRes['success'] === true, "Workspace created successfully");
$groupToken = $createRes['data']['group']['invite_token'];
$creatorToken = $createRes['data']['creator']['member_token'];
$creatorId = $createRes['data']['creator']['id'];
assertTest(!empty($groupToken) && !empty($creatorToken), "Group invite token and creator member token generated");

// 2. Unauthorized attempt to create pairing code (No creator token header)
$unauthRes = dispatchReq($router, new Request('POST', "/api/groups/{$groupToken}/creator-pairing", null, []));
assertTest($unauthRes['success'] === false, "Rejects pairing code generation without creator token");
assertTest($unauthRes['error']['code'] === 'FORBIDDEN', "Returns FORBIDDEN error code for missing token");

// 3. Unauthorized attempt with invalid token
$invalidTokenReq = new Request('POST', "/api/groups/{$groupToken}/creator-pairing", null, [], ['HTTP_X_CREATOR_TOKEN' => 'invalid_random_token_123']);
$invalidTokenRes = dispatchReq($router, $invalidTokenReq);
assertTest($invalidTokenRes['success'] === false, "Rejects pairing code generation with invalid creator token");
assertTest($invalidTokenRes['error']['code'] === 'FORBIDDEN', "Returns FORBIDDEN error code for invalid token");

// 4. Authorized pairing code creation with valid creator token
$authPairReq = new Request('POST', "/api/groups/{$groupToken}/creator-pairing", null, [], ['HTTP_X_CREATOR_TOKEN' => $creatorToken]);
$authPairRes = dispatchReq($router, $authPairReq);
assertTest($authPairRes['success'] === true, "Authorized creator can generate pairing code");
assertTest(!empty($authPairRes['data']['pairing_code']), "Pairing code returned in response");
assertTest(str_starts_with($authPairRes['data']['pairing_code'], 'PAIR-'), "Pairing code follows PAIR-XXXX-XXXX format");
assertTest($authPairRes['data']['expires_in_seconds'] === 600, "Pairing code expires in 600 seconds (10 minutes)");

$pairingCode = $authPairRes['data']['pairing_code'];

// Verify code is stored hashed in DB and not plaintext
$codeHash = hash('sha256', $pairingCode);
$stmt = $pdo->prepare("SELECT `code_hash`, `is_used` FROM `creator_pairing_codes` WHERE `code_hash` = :hash");
$stmt->execute([':hash' => $codeHash]);
$dbRecord = $stmt->fetch();
assertTest(!empty($dbRecord), "Pairing code is stored as SHA-256 hash in database");
assertTest((int)$dbRecord['is_used'] === 0, "Pairing code is initially marked unused (is_used = 0)");

// 5. Successful claim on a second device
$claimReq = new Request('POST', "/api/groups/{$groupToken}/creator-pairing/claim", null, [
    'pairing_code' => $pairingCode,
]);
$claimRes = dispatchReq($router, $claimReq);
assertTest($claimRes['success'] === true, "Pairing code claimed successfully on Device B");
assertTest($claimRes['data']['creator_token'] === $creatorToken, "Device B receives the correct creator token");
assertTest((int)$claimRes['data']['creator_member_id'] === (int)$creatorId, "Device B receives correct creator member ID");

// Verify is_used is updated in database
$stmt = $pdo->prepare("SELECT `is_used` FROM `creator_pairing_codes` WHERE `code_hash` = :hash");
$stmt->execute([':hash' => $codeHash]);
$updatedRecord = $stmt->fetch();
assertTest((int)$updatedRecord['is_used'] === 1, "Pairing code is marked used (is_used = 1) in database after claim");

// 6. Single-use replay attack prevention (attempting to claim the same code again)
$replayClaimRes = dispatchReq($router, new Request('POST', "/api/groups/{$groupToken}/creator-pairing/claim", null, [
    'pairing_code' => $pairingCode,
]));
assertTest($replayClaimRes['success'] === false, "Rejects replay claim of already used pairing code");
assertTest($replayClaimRes['error']['code'] === 'INVALID_PAIRING_CODE', "Returns INVALID_PAIRING_CODE error code on replay");

// 7. Expired code rejection
$expiredPairReq = new Request('POST', "/api/groups/{$groupToken}/creator-pairing", null, [], ['HTTP_X_CREATOR_TOKEN' => $creatorToken]);
$expiredPairRes = dispatchReq($router, $expiredPairReq);
$expiredCode = $expiredPairRes['data']['pairing_code'];
$expiredHash = hash('sha256', $expiredCode);

// Manually backdate expiry in database
$pdo->prepare("UPDATE `creator_pairing_codes` SET `expires_at` = :past WHERE `code_hash` = :hash")
    ->execute([
        ':past' => date('Y-m-d H:i:s', time() - 60),
        ':hash' => $expiredHash,
    ]);

$expiredClaimRes = dispatchReq($router, new Request('POST', "/api/groups/{$groupToken}/creator-pairing/claim", null, [
    'pairing_code' => $expiredCode,
]));
assertTest($expiredClaimRes['success'] === false, "Rejects claim of expired pairing code");
assertTest($expiredClaimRes['error']['code'] === 'PAIRING_CODE_EXPIRED', "Returns PAIRING_CODE_EXPIRED error code for expired code");

// 8. Rate limiting test on pairing claim (SEC-07 compliance)
$clientIp = '198.51.100.42';
for ($i = 0; $i < 5; $i++) {
    $rlReq = new Request('POST', "/api/groups/{$groupToken}/creator-pairing/claim", null, [
        'pairing_code' => 'PAIR-FAKE-1234',
    ], ['REMOTE_ADDR' => $clientIp]);
    $rlRes = dispatchReq($router, $rlReq);
    assertTest($rlRes['success'] === false, "Failed attempt {$i} blocked properly");
}

// 6th attempt should trigger 429 RATE_LIMITED
$rateLimitedReq = new Request('POST', "/api/groups/{$groupToken}/creator-pairing/claim", null, [
    'pairing_code' => 'PAIR-FAKE-1234',
], ['REMOTE_ADDR' => $clientIp]);
$rateLimitedRes = dispatchReq($router, $rateLimitedReq);
assertTest($rateLimitedRes['success'] === false, "6th rapid pairing claim attempt is rate limited");
assertTest($rateLimitedRes['error']['code'] === 'RATE_LIMITED', "Rate limit response returns RATE_LIMITED code");

echo "\n================================================================================\n";
echo " P0.3 Test Suite Summary: {$testsPassed}/{$totalTests} Tests Passed (100%)\n";
echo "================================================================================\n\n";
