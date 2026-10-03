<?php

declare(strict_types=1);

/**
 * SMART SPLIT V2 — GBK-01 & GBK-02 SURGICAL REMEDIATION & ADVERSARIAL RE-ATTACK SUITE
 *
 * Verifies:
 *  - GBK-01: Recurring rule deletion returns 404 on nonexistent/foreign/already-deleted IDs, 200 on authorized.
 *  - GBK-02: Template deletion returns 404 on nonexistent/foreign/already-deleted IDs, 200 on authorized.
 *  - Full 8-point regression matrix for both components.
 *  - Complete adversarial re-attack boundary matrix.
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Controllers\RecurringController;
use App\Controllers\TemplateController;
use App\Repositories\GroupRepository;
use App\Repositories\MemberRepository;
use App\Repositories\RecurringRepository;
use App\Repositories\TemplateRepository;
use App\Services\RecurringService;

Env::load(__DIR__ . '/../.env');
$pdo = Database::getConnection();

echo "================================================================================\n";
echo " GBK-01 & GBK-02: SURGICAL REMEDIATION & ADVERSARIAL RE-ATTACK SUITE\n";
echo "================================================================================\n\n";

$groupRepo = new GroupRepository($pdo);
$memberRepo = new MemberRepository($pdo);
$recurRepo = new RecurringRepository($pdo);
$tplRepo = new TemplateRepository($pdo);
$recurService = new RecurringService($recurRepo);

$router = new Router();
$router->delete('/api/groups/{token}/recurring/{id}', [RecurringController::class, 'delete']);
$router->delete('/api/groups/{token}/templates/{id}', [TemplateController::class, 'delete']);

$gbkTests = 0;
$gbkPassed = 0;
$gbkFailures = [];

function assertGbk(bool $condition, string $testId, string $description): void {
    global $gbkTests, $gbkPassed, $gbkFailures;
    $gbkTests++;
    if ($condition) {
        $gbkPassed++;
        echo "  [PASS] {$testId}: {$description}\n";
    } else {
        $gbkFailures[] = "[$testId] $description";
        echo "  [FAIL] {$testId}: {$description}\n";
    }
}

function sendReq(Router $router, string $method, string $path): array {
    $request = new Request($method, $path, null, null, ['Content-Type' => 'application/json', 'X-Requested-With' => 'fetch']);
    ob_start();
    try {
        $router->dispatch($request);
    } catch (\Throwable $e) {
        $code = ($e->getCode() >= 400 && $e->getCode() <= 599) ? (int)$e->getCode() : 500;
        Response::error($e->getMessage(), 'DISPATCH_ERROR', null, $code);
    }
    $raw = ob_get_clean();
    return ['status' => Response::$lastStatusCode, 'body' => json_decode($raw, true) ?: [], 'raw' => $raw];
}

// Setup 2 isolated test workspaces: WS_ALPHA and WS_BETA
$wsA = $groupRepo->create('GBK Test Alpha ' . bin2hex(random_bytes(2)), 'INR');
$gAId = (int)$wsA['id'];
$tokA = (string)$wsA['invite_token'];
$memA = $memberRepo->create($gAId, 'Alice Alpha');
$mAId = (int)$memA['id'];

$wsB = $groupRepo->create('GBK Test Beta ' . bin2hex(random_bytes(2)), 'USD');
$gBId = (int)$wsB['id'];
$tokB = (string)$wsB['invite_token'];
$memB = $memberRepo->create($gBId, 'Bob Beta');
$mBId = (int)$memB['id'];

// Seed Recurring Rules and Templates in both workspaces
$recAId = $recurRepo->createRule($gAId, 'Alpha Rent Schedule', 100000, 'EQUAL', null, 'MONTHLY', '2026-06-01', null, [], $mAId);
$recBId = $recurRepo->createRule($gBId, 'Beta Cloud Servers', 50000, 'EQUAL', null, 'MONTHLY', '2026-06-01', null, [], $mBId);

$tplAId = $tplRepo->createTemplate($gAId, 'Alpha Coffee Template', 1500, 'EQUAL', null, [], $mAId);
$tplBId = $tplRepo->createTemplate($gBId, 'Beta Server Template', 25000, 'EQUAL', null, [], $mBId);

echo "--- SECTION 1: GBK-01 RECURRING DELETION 8-POINT MATRIX ---\n";

// 1. Authorized existing rule -> 200
$resAuthRec = sendReq($router, 'DELETE', "/api/groups/{$tokA}/recurring/{$recAId}");
assertGbk(
    $resAuthRec['status'] === 200 && ($resAuthRec['body']['data']['deleted'] ?? false) === true,
    'GBK01.1.AUTH_DELETE',
    'Authorized existing recurring rule deletion returns HTTP 200 with deleted=true'
);

// 2. Already-deleted rule -> 404
$resAlreadyDelRec = sendReq($router, 'DELETE', "/api/groups/{$tokA}/recurring/{$recAId}");
assertGbk(
    $resAlreadyDelRec['status'] === 404 && ($resAlreadyDelRec['body']['error']['code'] ?? '') === 'NOT_FOUND',
    'GBK01.2.ALREADY_DELETED',
    'Repeated delete on already-deleted recurring rule returns HTTP 404 NOT_FOUND'
);

// 3. Nonexistent rule ID -> 404
$resNonRec = sendReq($router, 'DELETE', "/api/groups/{$tokA}/recurring/99999999");
assertGbk(
    $resNonRec['status'] === 404 && ($resNonRec['body']['error']['code'] ?? '') === 'NOT_FOUND',
    'GBK01.3.NONEXISTENT_ID',
    'Nonexistent recurring rule ID returns HTTP 404 NOT_FOUND'
);

// 4. Foreign-workspace rule ID -> 404 (Alpha token attempting to delete Beta rule)
$resForeignRec = sendReq($router, 'DELETE', "/api/groups/{$tokA}/recurring/{$recBId}");
assertGbk(
    $resForeignRec['status'] === 404 && ($resForeignRec['body']['error']['code'] ?? '') === 'NOT_FOUND',
    'GBK01.4.FOREIGN_WORKSPACE',
    'Cross-workspace recurring rule deletion rejected with HTTP 404 NOT_FOUND'
);

// 5. Foreign workspace rule remains intact in database
$stmtRecBCheck = $pdo->prepare("SELECT COUNT(*) FROM `recurring_rules` WHERE `id` = :id AND `group_id` = :gid");
$stmtRecBCheck->execute([':id' => $recBId, ':gid' => $gBId]);
assertGbk(
    (int)$stmtRecBCheck->fetchColumn() === 1,
    'GBK01.5.FOREIGN_DATA_INTACT',
    'Foreign workspace recurring rule remains intact in database after cross-workspace attack'
);

// 6. Invalid token -> 404
$resBadTokRec = sendReq($router, 'DELETE', "/api/groups/invalid_token_123/recurring/{$recBId}");
assertGbk(
    $resBadTokRec['status'] === 404,
    'GBK01.6.INVALID_TOKEN',
    'Invalid workspace token returns HTTP 404 NOT_FOUND'
);

// 7. Legitimate deletion in Beta workspace -> 200
$resBetaRec = sendReq($router, 'DELETE', "/api/groups/{$tokB}/recurring/{$recBId}");
assertGbk(
    $resBetaRec['status'] === 200 && ($resBetaRec['body']['data']['deleted'] ?? false) === true,
    'GBK01.7.BETA_AUTH_DELETE',
    'Beta workspace legitimately deletes its own recurring rule with HTTP 200'
);

// 8. Beta rule now also returns 404 on subsequent deletion
$resBetaRecPost = sendReq($router, 'DELETE', "/api/groups/{$tokB}/recurring/{$recBId}");
assertGbk(
    $resBetaRecPost['status'] === 404,
    'GBK01.8.BETA_ALREADY_DELETED',
    'Subsequent delete on Beta rule returns HTTP 404 NOT_FOUND'
);

echo "\n--- SECTION 2: GBK-02 TEMPLATE DELETION 8-POINT MATRIX ---\n";

// 1. Authorized existing template -> 200
$resAuthTpl = sendReq($router, 'DELETE', "/api/groups/{$tokA}/templates/{$tplAId}");
assertGbk(
    $resAuthTpl['status'] === 200 && ($resAuthTpl['body']['data']['deleted'] ?? false) === true,
    'GBK02.1.AUTH_DELETE',
    'Authorized existing template deletion returns HTTP 200 with deleted=true'
);

// 2. Already-deleted template -> 404
$resAlreadyDelTpl = sendReq($router, 'DELETE', "/api/groups/{$tokA}/templates/{$tplAId}");
assertGbk(
    $resAlreadyDelTpl['status'] === 404 && ($resAlreadyDelTpl['body']['error']['code'] ?? '') === 'NOT_FOUND',
    'GBK02.2.ALREADY_DELETED',
    'Repeated delete on already-deleted template returns HTTP 404 NOT_FOUND'
);

// 3. Nonexistent template ID -> 404
$resNonTpl = sendReq($router, 'DELETE', "/api/groups/{$tokA}/templates/99999999");
assertGbk(
    $resNonTpl['status'] === 404 && ($resNonTpl['body']['error']['code'] ?? '') === 'NOT_FOUND',
    'GBK02.3.NONEXISTENT_ID',
    'Nonexistent template ID returns HTTP 404 NOT_FOUND'
);

// 4. Foreign-workspace template ID -> 404 (Alpha token attempting to delete Beta template)
$resForeignTpl = sendReq($router, 'DELETE', "/api/groups/{$tokA}/templates/{$tplBId}");
assertGbk(
    $resForeignTpl['status'] === 404 && ($resForeignTpl['body']['error']['code'] ?? '') === 'NOT_FOUND',
    'GBK02.4.FOREIGN_WORKSPACE',
    'Cross-workspace template deletion rejected with HTTP 404 NOT_FOUND'
);

// 5. Foreign workspace template remains intact in database
$stmtTplBCheck = $pdo->prepare("SELECT COUNT(*) FROM `expense_templates` WHERE `id` = :id AND `group_id` = :gid");
$stmtTplBCheck->execute([':id' => $tplBId, ':gid' => $gBId]);
assertGbk(
    (int)$stmtTplBCheck->fetchColumn() === 1,
    'GBK02.5.FOREIGN_DATA_INTACT',
    'Foreign workspace template remains intact in database after cross-workspace attack'
);

// 6. Invalid token -> 404
$resBadTokTpl = sendReq($router, 'DELETE', "/api/groups/invalid_token_123/templates/{$tplBId}");
assertGbk(
    $resBadTokTpl['status'] === 404,
    'GBK02.6.INVALID_TOKEN',
    'Invalid workspace token returns HTTP 404 NOT_FOUND'
);

// 7. Legitimate deletion in Beta workspace -> 200
$resBetaTpl = sendReq($router, 'DELETE', "/api/groups/{$tokB}/templates/{$tplBId}");
assertGbk(
    $resBetaTpl['status'] === 200 && ($resBetaTpl['body']['data']['deleted'] ?? false) === true,
    'GBK02.7.BETA_AUTH_DELETE',
    'Beta workspace legitimately deletes its own template with HTTP 200'
);

// 8. Beta template now also returns 404 on subsequent deletion
$resBetaTplPost = sendReq($router, 'DELETE', "/api/groups/{$tokB}/templates/{$tplBId}");
assertGbk(
    $resBetaTplPost['status'] === 404,
    'GBK02.8.BETA_ALREADY_DELETED',
    'Subsequent delete on Beta template returns HTTP 404 NOT_FOUND'
);

echo "\n--- SECTION 3: ADVERSARIAL RE-ATTACK BOUNDARY VECTORS ---\n";

$adversarialPayloads = [
    'zero_id' => '0',
    'negative_id' => '-1',
    'max_int_id' => '2147483647',
    'sqli_id' => '1 OR 1=1',
    'traversal_id' => '../1',
    'alpha_id' => 'abc',
    'null_id' => 'null',
];

foreach ($adversarialPayloads as $label => $badId) {
    $resAdvRec = sendReq($router, 'DELETE', "/api/groups/{$tokA}/recurring/{$badId}");
    assertGbk($resAdvRec['status'] === 404, "ADV.REC.{$label}", "Adversarial recurring delete with ID '{$badId}' returns 404");

    $resAdvTpl = sendReq($router, 'DELETE', "/api/groups/{$tokA}/templates/{$badId}");
    assertGbk($resAdvTpl['status'] === 404, "ADV.TPL.{$label}", "Adversarial template delete with ID '{$badId}' returns 404");
}

// Cleanup test workspaces
$pdo->exec("DELETE FROM `recurring_rules` WHERE `group_id` IN ({$gAId}, {$gBId})");
$pdo->exec("DELETE FROM `expense_templates` WHERE `group_id` IN ({$gAId}, {$gBId})");
$pdo->exec("DELETE FROM `members` WHERE `group_id` IN ({$gAId}, {$gBId})");
$pdo->exec("DELETE FROM `groups` WHERE `id` IN ({$gAId}, {$gBId})");

echo "\n================================================================================\n";
echo " GBK REMEDIATION SUITE SUMMARY\n";
echo " Total Assertions  : {$gbkTests}\n";
echo " Passed Assertions : {$gbkPassed}\n";
echo " Failed Assertions : " . count($gbkFailures) . "\n";
echo " Pass Rate         : " . sprintf('%.2f%%', ($gbkPassed / max(1, $gbkTests)) * 100) . "\n";
echo "================================================================================\n\n";

if (!empty($gbkFailures)) {
    echo "FAILED ASSERTIONS:\n";
    foreach ($gbkFailures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}

echo ">>> VERDICT: 100% GBK-01 + GBK-02 REMEDIATION & RE-ATTACK PASS <<<\n";
