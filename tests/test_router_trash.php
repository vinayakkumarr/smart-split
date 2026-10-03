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
echo " Smart Split – Trash & Restore Route Resolution Tests\n";
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

class MockExpenseController extends BaseController
{
    public function trash(Request $request): void
    {
        echo "TRASH_ROUTE_MATCH: token=" . $request->getParam('token');
    }

    public function restore(Request $request): void
    {
        echo "RESTORE_ROUTE_MATCH: token=" . $request->getParam('token') . ", id=" . $request->getParam('id');
    }

    public function show(Request $request): void
    {
        echo "SHOW_ROUTE_MATCH: token=" . $request->getParam('token') . ", id=" . $request->getParam('id');
    }
}

$router = new Router();
$router->get('/api/groups/{token}/expenses/trash', [MockExpenseController::class, 'trash']);
$router->get('/api/groups/{token}/expenses/{id}', [MockExpenseController::class, 'show']);
$router->put('/api/groups/{token}/expenses/{id}/restore', [MockExpenseController::class, 'restore']);

// 1. Test GET /api/groups/{token}/expenses/trash
ob_start();
$req1 = new Request('GET', '/api/groups/grp_alpha_99/expenses/trash');
$router->dispatch($req1);
$out1 = ob_get_clean();
assertTest($out1 === "TRASH_ROUTE_MATCH: token=grp_alpha_99", "GET /api/groups/{token}/expenses/trash correctly matches trash handler without being shadowed by show handler");

// 2. Test GET /api/groups/{token}/expenses/55
ob_start();
$req2 = new Request('GET', '/api/groups/grp_alpha_99/expenses/55');
$router->dispatch($req2);
$out2 = ob_get_clean();
assertTest($out2 === "SHOW_ROUTE_MATCH: token=grp_alpha_99, id=55", "GET /api/groups/{token}/expenses/{id} matches show handler");

// 3. Test PUT /api/groups/{token}/expenses/55/restore
ob_start();
$req3 = new Request('PUT', '/api/groups/grp_alpha_99/expenses/55/restore');
$router->dispatch($req3);
$out3 = ob_get_clean();
assertTest($out3 === "RESTORE_ROUTE_MATCH: token=grp_alpha_99, id=55", "PUT /api/groups/{token}/expenses/{id}/restore matches restore handler");

echo "\n=====================================================\n";
echo " ALL {$testsPassed} / {$totalTests} ROUTE RESOLUTION TESTS PASSED!\n";
echo "=====================================================\n";
