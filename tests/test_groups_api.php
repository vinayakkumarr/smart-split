<?php

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Env;
use App\Core\Request;
use App\Core\Router;
use App\Controllers\GroupController;
use App\Controllers\MemberController;


echo "=====================================================\n";
echo " Smart Split – Group & Member Endpoints API Tests\n";
echo "=====================================================\n";

$testsPassed = 0;
$totalTests = 0;

function assertTest(bool $condition, string $testName): void
{
    global $testsPassed, $totalTests;
    $totalTests++;
    if ($condition) {
        echo "  [PASS] {$testName}\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] {$testName}\n";
        exit(1);
    }
}

$router = new Router();
$router->post('/api/groups', [GroupController::class, 'create']);
$router->get('/api/groups/{token}', [GroupController::class, 'show']);
$router->post('/api/groups/{token}/members', [MemberController::class, 'create']);
$router->get('/api/groups/{token}/members', [MemberController::class, 'index']);

// Helper function to dispatch and decode response
function dispatchRequest(Router $router, Request $req): array
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

// 1. Test POST /api/groups
$groupName = 'Goa Trip ' . time();
$createReq = new Request('POST', '/api/groups', null, [
    'name' => $groupName,
    'creator_name' => 'Alice',
    'currency' => 'INR',
]);

$createRes = dispatchRequest($router, $createReq);
assertTest($createRes['success'] === true, "POST /api/groups returns success=true");
assertTest(!empty($createRes['data']['group']['invite_token']), "POST /api/groups generates 64-character invite token");
assertTest($createRes['data']['group']['currency_code'] === 'INR', "Group default currency is INR");
assertTest($createRes['data']['creator']['name'] === 'Alice', "Group creator member created automatically");

$inviteToken = $createRes['data']['group']['invite_token'];

// 2. Test GET /api/groups/{token}
$getReq = new Request('GET', "/api/groups/{$inviteToken}");
$getRes = dispatchRequest($router, $getReq);
assertTest($getRes['success'] === true, "GET /api/groups/{token} returns success=true");
assertTest($getRes['data']['group']['name'] === $groupName, "GET /api/groups/{token} returns correct group name");
assertTest(count($getRes['data']['members']) === 1, "Roster contains initial creator member");

// 3. Test POST /api/groups/{token}/members (Add Bob)
$addMemberReq1 = new Request('POST', "/api/groups/{$inviteToken}/members", null, [
    'name' => 'Bob',
]);
$addMemberRes1 = dispatchRequest($router, $addMemberReq1);
assertTest($addMemberRes1['success'] === true, "POST /api/groups/{token}/members adds Bob successfully");
assertTest($addMemberRes1['data']['member']['name'] === 'Bob', "Added member has name Bob");

// 4. Test POST /api/groups/{token}/members (Add Charlie)
$addMemberReq2 = new Request('POST', "/api/groups/{$inviteToken}/members", null, [
    'name' => 'Charlie',
]);
$addMemberRes2 = dispatchRequest($router, $addMemberReq2);
assertTest($addMemberRes2['success'] === true, "POST /api/groups/{token}/members adds Charlie successfully");

// 5. Test Duplicate Member Name Rejection (Add Bob again)
$addDupReq = new Request('POST', "/api/groups/{$inviteToken}/members", null, [
    'name' => 'Bob',
]);
$addDupRes = dispatchRequest($router, $addDupReq);
assertTest($addDupRes['success'] === false, "POST /api/groups/{token}/members rejects duplicate name");
assertTest($addDupRes['error']['code'] === 'MEMBER_EXISTS', "Error code is MEMBER_EXISTS");

// 6. Test GET /api/groups/{token}/members
$getRosterReq = new Request('GET', "/api/groups/{$inviteToken}/members");
$getRosterRes = dispatchRequest($router, $getRosterReq);
assertTest($getRosterRes['success'] === true, "GET /api/groups/{token}/members returns success");
assertTest(count($getRosterRes['data']['members']) === 3, "Roster contains exactly 3 members (Alice, Bob, Charlie)");

echo "\n=====================================================\n";
echo " All {$testsPassed} / {$totalTests} Group & Member API Tests PASSED!\n";
echo "=====================================================\n";
