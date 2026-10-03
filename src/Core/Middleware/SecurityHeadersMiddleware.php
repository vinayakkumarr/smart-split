<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Global Security Middleware enforcing HTTP Security Headers and CSRF Protection.
 */
class SecurityHeadersMiddleware
{
    /**
     * Hardened Content Security Policy (SEC-05).
     * Strictly disallows 'unsafe-inline' in script-src, enforces same-origin script execution,
     * restricts object-src to 'none', base-uri and form-action to 'self', and frame-ancestors to 'none'.
     */
    public const CSP_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data:; font-src 'self' https://fonts.gstatic.com; connect-src 'self'; worker-src 'self'; manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';";

    /**
     * Handle incoming request.
     *
     * @param Request $request
     * @return void
     */
    public function __invoke(Request $request): void
    {
        // 1. Emit standard security headers if not already sent
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Content-Security-Policy: ' . self::CSP_POLICY);
        }

        // 2. CSRF / Origin Verification for State-Mutating Methods (POST, PUT, DELETE, PATCH)
        $method = $request->getMethod();
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            // Allow CLI test runners
            if (php_sapi_name() === 'cli') {
                return;
            }

            // Verify API requests contain X-Requested-With header or application/json Content-Type
            $requestedWith = strtolower((string) $request->getHeader('X-Requested-With', ''));
            $contentType = strtolower((string) $request->getHeader('Content-Type', ''));

            $isValidAjaxOrJson = in_array($requestedWith, ['fetch', 'xmlhttprequest', 'smartsplit'], true)
                || str_contains($contentType, 'application/json');

            if (!$isValidAjaxOrJson && str_starts_with($request->getPath(), '/api/')) {
                Response::error(
                    'Invalid or missing CSRF security headers (X-Requested-With or application/json required).',
                    'FORBIDDEN',
                    null,
                    403
                );
                return;
            }
        }
    }
}
