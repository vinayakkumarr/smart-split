<?php

declare(strict_types=1);

// Static Asset Bypass for PHP Built-in Server (cli-server)
if (php_sapi_name() === 'cli-server') {
    $requestedPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $filePath = __DIR__ . $requestedPath;
    if ($requestedPath !== '/' && is_file($filePath)) {
        return false;
    }
}

require_once dirname(__DIR__) . '/includes/logo.php';

/**
 * Smart Split – Front Controller & Application Bootstrap
 */

// 1. PSR-4 Compliant Class Autoloader
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
        require $file;
    }
});

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Middleware\SecurityHeadersMiddleware;
use App\Core\Middleware\AuthSessionMiddleware;

// 2. Load Environment Variables
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    Env::load($envFile);
}

// 3. Configure Error Reporting & Debug Mode Boundary (SEC-06)
$envMode = strtolower((string) Env::get('APP_ENV', 'production'));
$isProduction = in_array($envMode, ['production', 'prod'], true);
$rawDebug = Env::get('APP_DEBUG', false);
$isDebug = false;

// Fail-closed debug mode: only active in non-production when explicitly truthy
if (!$isProduction) {
    if (is_bool($rawDebug)) {
        $isDebug = $rawDebug;
    } elseif (is_string($rawDebug)) {
        $isDebug = in_array(strtolower(trim($rawDebug)), ['true', '1', 'yes', 'on'], true);
    } elseif (is_int($rawDebug)) {
        $isDebug = ($rawDebug === 1);
    }
}

if ($isDebug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// 4. Global Error & Exception Handlers (SEC-06)
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) use ($isDebug): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    error_log(sprintf("PHP Error [%d]: %s in %s on line %d", $errno, $errstr, $errfile, $errline));
    return true;
});

set_exception_handler(function (Throwable $e) use ($isDebug): void {
    $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 500;
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

    // Server-side diagnostic logging for 5xx errors
    if ($statusCode >= 500) {
        error_log(sprintf(
            "[%s] Uncaught %s: %s in %s:%d\nStack trace:\n%s",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));
    }

    // Determine production-safe message vs development diagnostics
    if ($isDebug) {
        $clientMessage = $e->getMessage() ?: 'An error occurred.';
        $details = [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString()),
        ];
    } else {
        $clientMessage = ($statusCode < 500 && $e->getMessage() !== '')
            ? $e->getMessage()
            : 'An unexpected error occurred. Please try again later.';
        $details = null;
    }

    // Determine request context (API vs HTML web shell)
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?? '/';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isApi = str_starts_with($path, '/api/') || str_contains($accept, 'application/json');

    if ($isApi) {
        Response::error(
            $clientMessage,
            $errorCode,
            $details,
            $statusCode
        );
        return;
    }

    // Web Shell HTML 500 error page
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
    }

    if ($isDebug) {
        echo "<!DOCTYPE html><html><head><title>500 Internal Server Error</title><style>body{font-family:monospace;padding:20px;background:#1e1e1e;color:#f87171;}pre{background:#111;padding:15px;border-radius:6px;overflow:auto;color:#e2e8f0;}</style></head><body>";
        echo "<h1>" . htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8') . ": " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</h1>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8') . " <strong>Line:</strong> " . (int)$e->getLine() . "</p>";
        echo "<h2>Stack Trace:</h2><pre>" . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . "</pre>";
        echo "</body></html>";
    } else {
        echo "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\"><title>500 - Internal Server Error</title><style>body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;text-align:center;padding:80px 20px;background:#090B0E;color:#f8fafc;}h1{font-size:2rem;margin-bottom:0.5rem;color:#f87171;}p{color:#94a3b8;font-size:1.1rem;}</style></head><body><h1>500 - Internal Server Error</h1><p>An unexpected error occurred on the server. Please try again later.</p></body></html>";
    }

    if (php_sapi_name() !== 'cli') {
        exit();
    }
});

// 5. Global Security Headers & Middleware
$request = Request::createFromGlobals();
$router = new Router();
$router->use(new SecurityHeadersMiddleware());
$router->use(new AuthSessionMiddleware());

// Core Diagnostic Health Check
$router->get('/api/health', function (Request $req): void {
    Response::json([
        'status' => 'ok',
        'app' => Env::get('APP_NAME', 'Smart Split'),
        'version' => '1.0.0',
        'currency' => 'INR',
        'environment' => Env::get('APP_ENV', 'local'),
        'timestamp' => time(),
    ], 200, [
        'php_version' => PHP_VERSION,
    ]);
});

// Authentication & Identity Endpoints
$router->post('/api/auth/register', [\App\Controllers\AuthController::class, 'register']);
$router->post('/api/auth/login', [\App\Controllers\AuthController::class, 'login']);
$router->post('/api/auth/logout', [\App\Controllers\AuthController::class, 'logout']);
$router->get('/api/auth/me', [\App\Controllers\AuthController::class, 'me']);
$router->put('/api/auth/profile', [\App\Controllers\AuthController::class, 'updateProfile']);
$router->post('/api/auth/recover', [\App\Controllers\AuthController::class, 'recoverPassword']);
$router->post('/api/auth/recover-password', [\App\Controllers\AuthController::class, 'recoverPassword']);

// Member Identity Claiming & Cloud Workspaces
$router->post('/api/groups/{token}/claim-workspace', [\App\Controllers\AuthController::class, 'claimWorkspace']);
$router->post('/api/groups/{token}/claim-member', [\App\Controllers\AuthController::class, 'claimMember']);
$router->post('/api/groups/{token}/members/{memberId}/claim', [\App\Controllers\AuthController::class, 'claimMember']);
$router->post('/api/groups/{token}/unlink-member', [\App\Controllers\AuthController::class, 'unlinkMember']);
$router->post('/api/groups/{token}/members/{memberId}/unlink', [\App\Controllers\AuthController::class, 'unlinkMember']);
$router->get('/api/user/workspaces', [\App\Controllers\AuthController::class, 'userWorkspaces']);
$router->put('/api/user/profile', [\App\Controllers\AuthController::class, 'updateProfile']);
$router->post('/api/user/profile', [\App\Controllers\AuthController::class, 'updateProfile']);
$router->delete('/api/user/account', [\App\Controllers\AuthController::class, 'deleteAccount']);

// Group Endpoints
$router->post('/api/groups', [\App\Controllers\GroupController::class, 'create']);
$router->get('/api/groups/{token}/workspace', [\App\Controllers\GroupController::class, 'workspace']);
$router->get('/api/groups/{token}', [\App\Controllers\GroupController::class, 'show']);
$router->delete('/api/groups/{token}', [\App\Controllers\GroupController::class, 'delete']);
$router->post('/api/groups/{token}/creator-pairing', [\App\Controllers\GroupController::class, 'createPairingCode']);
$router->post('/api/groups/{token}/creator-pairing/claim', [\App\Controllers\GroupController::class, 'claimPairingCode']);

// Member Endpoints
$router->post('/api/groups/{token}/members', [\App\Controllers\MemberController::class, 'create']);
$router->get('/api/groups/{token}/members', [\App\Controllers\MemberController::class, 'index']);
$router->put('/api/groups/{token}/members/{id}', [\App\Controllers\MemberController::class, 'update']);
$router->delete('/api/groups/{token}/members/{id}', [\App\Controllers\MemberController::class, 'delete']);

// Category Endpoints
$router->get('/api/categories', [\App\Controllers\CategoryController::class, 'index']);
$router->get('/api/groups/{token}/categories', [\App\Controllers\CategoryController::class, 'index']);
$router->post('/api/groups/{token}/categories', [\App\Controllers\CategoryController::class, 'create']);
$router->delete('/api/groups/{token}/categories/{id}', [\App\Controllers\CategoryController::class, 'delete']);

// Expense Endpoints
$router->post('/api/groups/{token}/expenses', [\App\Controllers\ExpenseController::class, 'create']);
$router->get('/api/groups/{token}/expenses', [\App\Controllers\ExpenseController::class, 'index']);
$router->get('/api/groups/{token}/expenses/trash', [\App\Controllers\ExpenseController::class, 'trash']);
$router->get('/api/groups/{token}/expenses/{id}', [\App\Controllers\ExpenseController::class, 'show']);
$router->put('/api/groups/{token}/expenses/{id}', [\App\Controllers\ExpenseController::class, 'update']);
$router->put('/api/groups/{token}/expenses/{id}/restore', [\App\Controllers\ExpenseController::class, 'restore']);
$router->delete('/api/groups/{token}/expenses/{id}', [\App\Controllers\ExpenseController::class, 'delete']);
$router->get('/api/groups/{token}/export.csv', [\App\Controllers\ExpenseController::class, 'exportCsv']);

// Balance, Analytics, Activity, Events & Ledger Endpoints
$router->get('/api/groups/{token}/balances', [\App\Controllers\BalanceController::class, 'index']);
$router->get('/api/groups/{token}/bilateral-balances', [\App\Controllers\BalanceController::class, 'bilateralBalances']);
$router->get('/api/groups/{token}/settlement-plan', [\App\Controllers\BalanceController::class, 'settlementPlan']);
$router->get('/api/groups/{token}/analytics/summary', [\App\Controllers\BalanceController::class, 'analyticsSummary']);
$router->get('/api/groups/{token}/activity-feed', [\App\Controllers\ActivityController::class, 'index']);
$router->get('/api/groups/{token}/events', [\App\Controllers\EventController::class, 'stream']);
$router->get('/api/groups/{token}/members/{memberId}/ledger', [\App\Controllers\BalanceController::class, 'memberLedger']);

// Settlement Endpoints
$router->post('/api/groups/{token}/settlements', [\App\Controllers\SettlementController::class, 'create']);
$router->get('/api/groups/{token}/settlements', [\App\Controllers\SettlementController::class, 'index']);
$router->post('/api/groups/{token}/settlements/{id}/confirm', [\App\Controllers\SettlementController::class, 'confirm']);
$router->post('/api/groups/{token}/settlements/{id}/dispute', [\App\Controllers\SettlementController::class, 'dispute']);
$router->post('/api/groups/{token}/settlements/{id}/reverse', [\App\Controllers\SettlementController::class, 'reverse']);
$router->delete('/api/groups/{token}/settlements/{id}', [\App\Controllers\SettlementController::class, 'delete']);

// Recurring Schedule Endpoints
$router->get('/api/groups/{token}/recurring', [\App\Controllers\RecurringController::class, 'index']);
$router->post('/api/groups/{token}/recurring', [\App\Controllers\RecurringController::class, 'create']);
$router->delete('/api/groups/{token}/recurring/{id}', [\App\Controllers\RecurringController::class, 'delete']);
$router->post('/api/groups/{token}/recurring/evaluate', [\App\Controllers\RecurringController::class, 'evaluate']);

// Expense Template Endpoints
$router->get('/api/groups/{token}/templates', [\App\Controllers\TemplateController::class, 'index']);
$router->post('/api/groups/{token}/templates', [\App\Controllers\TemplateController::class, 'create']);
$router->delete('/api/groups/{token}/templates/{id}', [\App\Controllers\TemplateController::class, 'delete']);

// Currency & Exchange Rate Endpoints
$router->get('/api/currencies', [\App\Controllers\CurrencyController::class, 'index']);
$router->get('/api/exchange-rates', [\App\Controllers\CurrencyController::class, 'exchangeRate']);

// Receipt Attachment Endpoints
$router->get('/api/groups/{token}/expenses/{id}/receipts', [\App\Controllers\ReceiptController::class, 'index']);
$router->post('/api/groups/{token}/expenses/{id}/receipts', [\App\Controllers\ReceiptController::class, 'create']);
$router->get('/api/groups/{token}/expenses/{id}/receipts/{receiptId}/download', [\App\Controllers\ReceiptController::class, 'download']);
$router->get('/api/groups/{token}/expenses/{id}/receipts/{receiptId}/view', [\App\Controllers\ReceiptController::class, 'download']);
$router->get('/api/groups/{token}/expenses/{id}/receipts/{receiptId}', [\App\Controllers\ReceiptController::class, 'download']);
$router->delete('/api/groups/{token}/expenses/{id}/receipts/{receiptId}', [\App\Controllers\ReceiptController::class, 'delete']);

// Dispatch Request
$router->dispatch($request);

// 7. If non-API request fell through, check if it's a missing asset or SPA route
$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (preg_match('/\.(png|jpg|jpeg|webp|pdf|svg|css|js|map|ico|txt|json)$/i', $requestedPath) || str_starts_with($requestedPath, '/uploads/') || str_starts_with($requestedPath, '/storage/')) {
    http_response_code(404);
    echo "404 Not Found";
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars((string) Env::get('APP_NAME', 'Smart Split'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="apple-touch-icon" href="/favicon.svg">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#18352B">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Smart Split">
    <script src="/assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="/assets/css/brand.css">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div id="app">
        <nav class="navbar">
            <div class="navbar-inner">
                <a href="#/" style="text-decoration: none; display: flex; align-items: center;">
                    <!-- Desktop: Full Horizontal Logo -->
                    <div class="ss-logo-desktop">
                        <?php render_smart_split_logo('horizontal', 'light', '36px'); ?>
                    </div>
                    <!-- Mobile: 1:1 Symbol Only -->
                    <div class="ss-logo-mobile">
                        <?php render_smart_split_logo('symbol', 'light', '32px'); ?>
                    </div>
                </a>
                <style>
                    .ss-logo-mobile { display: none; }
                    @media (max-width: 640px) {
                        .ss-logo-desktop { display: none; }
                        .ss-logo-mobile { display: block; }
                    }
                </style>
                <div class="navbar-actions" id="navbar-actions">
                    <button type="button" class="navbar-workspaces-btn" id="btn-navbar-workspaces" title="Workspaces Hub & Quick Switcher">
                        <span>Workspaces</span>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.7;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>
                    <button type="button" class="navbar-theme-btn" id="btn-navbar-theme" title="Toggle Light/Dark Theme" aria-label="Toggle theme">
                        <span class="theme-toggle-icon-wrap" id="theme-toggle-icon" aria-hidden="true">
                            <svg class="theme-toggle-svg theme-toggle-moon" width="15" height="15" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M15.2 12.8A6.5 6.5 0 0 1 7.2 4.8 6.5 6.5 0 1 0 15.2 12.8z"/>
                            </svg>
                        </span>
                    </button>
                    <span class="navbar-chip" id="navbar-currency-badge">
                        <span class="navbar-chip-indicator"></span>
                        <span>INR (₹)</span>
                    </span>
                    <div id="navbar-auth-container" class="navbar-auth-container" style="position: relative;"></div>
                </div>
            </div>
        </nav>

        <main class="main-container" id="main-content">
            <div class="flex-center" style="min-height: 200px; flex-direction: column; gap: var(--space-3);" id="app-startup-loader">
                <div class="badge badge-settled">Loading Smart Split...</div>
            </div>
            <noscript>
                <div class="panel" style="text-align: center; max-width: 420px; margin: 40px auto; padding: 24px;">
                    <p style="color: var(--financial-debt); font-weight: 700; margin-bottom: 8px;">JavaScript Required</p>
                    <p style="color: var(--text-muted); font-size: 0.85rem;">Smart Split requires JavaScript to manage the real-time financial ledger. Please enable JavaScript in your browser.</p>
                </div>
            </noscript>
        </main>
    </div>

    <!-- Mount points for Modals & Toasts -->
    <div id="modal-overlay" class="modal-overlay"></div>
    <div id="toast-container"></div>

    <script>
        // Defensive Startup Boundary: If modules fail to initialize within 5s, provide 1-click recovery
        window.__smartSplitStartupTimer = setTimeout(function() {
            var loader = document.getElementById('app-startup-loader');
            if (loader && loader.parentElement) {
                loader.innerHTML = '<div class="panel" style="text-align: center; max-width: 440px; margin: 30px auto; padding: 24px; border: 1px solid var(--border-color, #334155); border-radius: 8px;">' +
                    '<p style="font-weight: 700; font-size: 0.95rem; margin-bottom: 8px; color: var(--text-primary, #f8fafc);">Workspace Taking Longer Than Usual</p>' +
                    '<p style="color: var(--text-muted, #94a3b8); font-size: 0.8rem; margin-bottom: 16px; line-height: 1.4;">A cached browser asset may need refreshing to sync with the latest ledger engine.</p>' +
                    '<div style="display: flex; gap: 8px; justify-content: center;">' +
                    '<button type="button" class="btn btn-primary btn-sm" onclick="if(\'serviceWorker\' in navigator){navigator.serviceWorker.getRegistrations().then(function(r){for(var i=0;i<r.length;i++){r[i].unregister();}});if(window.caches){caches.keys().then(function(k){for(var j=0;j<k.length;j++){caches.delete(k[j]);}});}}window.location.reload(true);" style="padding: 6px 14px; font-weight: 700; cursor: pointer;">Reload & Refresh Cache</button>' +
                    '</div>' +
                '</div>';
            }
        }, 5000);
    </script>
    <script type="module" src="/assets/js/app.js?v=2.0.3"></script>
    <script src="/assets/js/pwa-init.js?v=2.0.3"></script>
</body>
</html>
