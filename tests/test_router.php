<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Core/Env.php';
require dirname(__DIR__) . '/src/Core/Response.php';
require dirname(__DIR__) . '/src/Core/Request.php';
require dirname(__DIR__) . '/src/Core/BaseController.php';
require dirname(__DIR__) . '/src/Core/Router.php';

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\BaseController;

echo "=====================================================\n";
echo " Smart Split – REST Router & API Core Tests\n";
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

// Dummy Test Controller
class DummyTestController extends BaseController
{
    public function show(Request $request): void
    {
        echo "CONTROLLER_SUCCESS: token=" . $request->getParam('token') . ", id=" . $request->getParam('id');
    }
}

$router = new Router();

// Track middleware execution
$middlewareRan = false;
$router->use(function (Request $req) use (&$middlewareRan) {
    $middlewareRan = true;
});

// Register routes
$router->get('/api/test-static', function (Request $req) {
    echo "STATIC_MATCH";
});

$router->get('/api/groups/{token}/workspace', function (Request $req) {
    echo "WORKSPACE_MATCH: " . $req->getParam('token');
});

$router->get('/api/groups/{token}', function (Request $req) {
    echo "GROUP_SHOW_MATCH: " . $req->getParam('token');
});

$router->get('/api/groups/{token}/expenses/{id}', [DummyTestController::class, 'show']);

$router->post('/api/groups/{token}/members', function (Request $req) {
    echo "POST_MATCH: " . $req->getParam('token') . ", body_name=" . ($req->get('name') ?? 'none');
});

// 1. Test Static Route Dispatch
ob_start();
$req1 = new Request('GET', '/api/test-static');
$router->dispatch($req1);
$out1 = ob_get_clean();
assertTest($out1 === "STATIC_MATCH", "Static GET route matches and executes handler");
assertTest($middlewareRan === true, "Global middleware executes on dispatch");

// 2. Test Consolidated Workspace Route Dispatch
ob_start();
$reqWs = new Request('GET', '/api/groups/workspace_token_999/workspace');
$router->dispatch($reqWs);
$outWs = ob_get_clean();
assertTest($outWs === "WORKSPACE_MATCH: workspace_token_999", "Consolidated /workspace endpoint correctly matches and extracts token");

// 3. Test Show Route Disambiguation
ob_start();
$reqShow = new Request('GET', '/api/groups/workspace_token_999');
$router->dispatch($reqShow);
$outShow = ob_get_clean();
assertTest($outShow === "GROUP_SHOW_MATCH: workspace_token_999", "Standard /groups/{token} route disambiguates properly");

// 4. Test Dynamic Parameter Extraction & Controller Invocation
ob_start();
$req2 = new Request('GET', '/api/groups/tok_abc123/expenses/456');
$router->dispatch($req2);
$out2 = ob_get_clean();
assertTest($out2 === "CONTROLLER_SUCCESS: token=tok_abc123, id=456", "Dynamic route parameters extracted and passed to Controller");

// 5. Test POST Route with JSON payload
ob_start();
$req3 = new Request('POST', '/api/groups/tok_xyz/members', null, ['name' => 'Bob']);
$router->dispatch($req3);
$out3 = ob_get_clean();
assertTest($out3 === "POST_MATCH: tok_xyz, body_name=Bob", "POST route dispatches with body payload and route params");

echo "\n=====================================================\n";
echo " All {$testsPassed} / {$totalTests} Router & Request Tests PASSED!\n";
echo "=====================================================\n";
