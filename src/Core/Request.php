<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Immutable HTTP Request Representation, Payload Extractor & User Context.
 */
class Request
{
    private string $method;
    private string $path;
    private array $queryParams;
    private array $body;
    private array $headers;
    private array $routeParams = [];
    private ?array $user = null;

    /**
     * Build a Request instance from PHP superglobals or explicit values.
     */
    public function __construct(
        ?string $method = null,
        ?string $path = null,
        ?array $queryParams = null,
        ?array $body = null,
        ?array $headers = null
    ) {
        $this->method = strtoupper($method ?? $_SERVER['REQUEST_METHOD'] ?? 'GET');
        
        $rawUri = $path ?? $_SERVER['REQUEST_URI'] ?? '/';
        $this->path = rawurldecode(parse_url($rawUri, PHP_URL_PATH) ?? '/');
        
        $urlQuery = [];
        $rawQuery = parse_url($rawUri, PHP_URL_QUERY);
        if ($rawQuery) {
            parse_str($rawQuery, $urlQuery);
        }
        $this->queryParams = $queryParams ?? array_merge($_GET ?? [], $urlQuery);
        if ($headers !== null) {
            $normalizedHeaders = [];
            foreach ($headers as $k => $v) {
                $normKey = strtolower(str_replace('_', '-', str_starts_with((string)$k, 'HTTP_') ? substr((string)$k, 5) : (string)$k));
                $normalizedHeaders[$normKey] = (string) $v;
            }
            $this->headers = $normalizedHeaders;
        } else {
            $this->headers = $this->extractHeaders();
        }
        
        if ($body !== null) {
            $this->body = $body;
        } else {
            $this->body = $this->parseIncomingBody();
        }
    }

    /**
     * Create a Request instance capturing the current PHP runtime environment.
     *
     * @return self
     */
    public static function createFromGlobals(): self
    {
        return new self();
    }

    /**
     * Get the HTTP method (e.g. GET, POST, PUT, DELETE).
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get the sanitized request URI path (e.g. /api/groups).
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get query string parameters ($_GET).
     *
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * Get a specific query parameter with default fallback.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getQuery(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * Alias for getQuery.
     */
    public function getQueryParam(string $key, mixed $default = null): mixed
    {
        return $this->getQuery($key, $default);
    }

    /**
     * Get parsed request body array (JSON or Form POST).
     *
     * @return array<string, mixed>
     */
    public function getBody(): array
    {
        return $this->body;
    }

    /**
     * Get a specific field from the request body.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->queryParams[$key] ?? $default;
    }

    /**
     * Get a specific field from the parsed request body.
     */
    public function getBodyParam(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Set matched route parameters (e.g. ['token' => 'abc', 'id' => '12']).
     *
     * @param array<string, string> $params
     * @return void
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /**
     * Get all matched route parameters.
     *
     * @return array<string, string>
     */
    public function getRouteParams(): array
    {
        return $this->routeParams;
    }

    /**
     * Get a single route parameter by name.
     *
     * @param string $key
     * @param string|null $default
     * @return string|null
     */
    public function getParam(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * Get an HTTP header value by name (case-insensitive).
     *
     * @param string $name
     * @param string|null $default
     * @return string|null
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        $normalized = strtolower($name);
        return $this->headers[$normalized] ?? $default;
    }

    /**
     * Check if request expects a JSON response.
     *
     * @return bool
     */
    public function isJson(): bool
    {
        $contentType = $this->getHeader('Content-Type') ?? '';
        $accept = $this->getHeader('Accept') ?? '';
        return str_contains($contentType, 'application/json') || str_contains($accept, 'application/json');
    }

    /**
     * Set the authenticated user context for this request.
     *
     * @param array{id: int, email: string, display_name: string, avatar_emoji: string, avatar_color: string, session_id: int}|null $user
     * @return void
     */
    public function setUser(?array $user): void
    {
        $this->user = $user;
    }

    /**
     * Get the authenticated user context, or null if guest/anonymous.
     *
     * @return array{id: int, email: string, display_name: string, avatar_emoji: string, avatar_color: string, session_id: int}|null
     */
    public function getUser(): ?array
    {
        return $this->user;
    }

    /**
     * Check if the current request is from an authenticated user.
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    /**
     * Get reliable client IP address.
     * When behind a configured trusted reverse proxy (via TRUSTED_PROXIES env var),
     * resolves the originating client IP by traversing the X-Forwarded-For header right-to-left.
     * If TRUSTED_PROXIES is not configured or the immediate peer is untrusted,
     * strictly returns REMOTE_ADDR to prevent header spoofing.
     *
     * @return string
     */
    public function getClientIp(): string
    {
        $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        if (filter_var($remoteAddr, FILTER_VALIDATE_IP) === false) {
            $remoteAddr = '127.0.0.1';
        }

        $trustedProxiesConfig = trim((string) (Env::get('TRUSTED_PROXIES') ?? ''));
        if ($trustedProxiesConfig === '') {
            return $remoteAddr;
        }

        $trustedList = array_filter(array_map('trim', explode(',', $trustedProxiesConfig)));
        if (empty($trustedList)) {
            return $remoteAddr;
        }

        // Only trust proxy headers if the immediate peer (REMOTE_ADDR) is in the trusted proxy list
        if (!$this->isIpTrusted($remoteAddr, $trustedList)) {
            return $remoteAddr;
        }

        $forwardedFor = $this->getHeader('X-Forwarded-For') ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (trim($forwardedFor) === '') {
            return $remoteAddr;
        }

        // Parse hops: client, proxy1, proxy2
        $hops = array_filter(array_map('trim', explode(',', $forwardedFor)));
        if (empty($hops)) {
            return $remoteAddr;
        }

        // Walk right-to-left (from nearest proxy back towards client)
        $hops = array_reverse($hops);
        $validHops = [];
        foreach ($hops as $hop) {
            // Strip port if present in IPv4 (e.g. "203.0.113.195:8080") or IPv6 bracketed (e.g. "[2001:db8::1]:8080")
            if (preg_match('/^\[([a-fA-F0-9:]+)\](?::\d+)?$/', $hop, $m)) {
                $hop = $m[1];
            } elseif (preg_match('/^(\d+\.\d+\.\d+\.\d+)(?::\d+)?$/', $hop, $m)) {
                $hop = $m[1];
            }

            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            if (!$this->isIpTrusted($hop, $trustedList)) {
                return $hop;
            }

            $validHops[] = $hop;
        }

        // If all valid hops are trusted proxies, fallback to the furthest valid hop, or remoteAddr
        return !empty($validHops) ? end($validHops) : $remoteAddr;
    }

    /**
     * Check if an IP matches any entry in the trusted proxy list (exact IP or CIDR block).
     *
     * @param string $ip
     * @param array<string> $trustedList
     * @return bool
     */
    private function isIpTrusted(string $ip, array $trustedList): bool
    {
        foreach ($trustedList as $trusted) {
            $trusted = trim($trusted);
            if ($trusted === '') {
                continue;
            }
            if ($trusted === '*' || $trusted === $ip) {
                return true;
            }
            if (str_contains($trusted, '/')) {
                [$subnet, $mask] = explode('/', $trusted, 2);
                $mask = (int) $mask;

                // IPv4 CIDR matching
                if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    if ($mask < 0 || $mask > 32) {
                        continue;
                    }
                    $ipLong = ip2long($ip);
                    $subnetLong = ip2long($subnet);
                    if ($ipLong === false || $subnetLong === false) {
                        continue;
                    }
                    $netmask = $mask === 0 ? 0 : (~0 << (32 - $mask));
                    if (($ipLong & $netmask) === ($subnetLong & $netmask)) {
                        return true;
                    }
                }

                // IPv6 CIDR matching
                if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    if ($mask < 0 || $mask > 128) {
                        continue;
                    }
                    $ipBytes = inet_pton($ip);
                    $subnetBytes = inet_pton($subnet);
                    if ($ipBytes === false || $subnetBytes === false) {
                        continue;
                    }
                    $fullBytes = intdiv($mask, 8);
                    $extraBits = $mask % 8;
                    if (substr($ipBytes, 0, $fullBytes) !== substr($subnetBytes, 0, $fullBytes)) {
                        continue;
                    }
                    if ($extraBits > 0) {
                        $c1 = ord($ipBytes[$fullBytes]);
                        $c2 = ord($subnetBytes[$fullBytes]);
                        $bitMask = (~0 << (8 - $extraBits)) & 0xFF;
                        if (($c1 & $bitMask) !== ($c2 & $bitMask)) {
                            continue;
                        }
                    }
                    return true;
                }
            } else {
                // Exact match (normalizing IPv6)
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($trusted, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    if (inet_pton($ip) === inet_pton($trusted)) {
                        return true;
                    }
                } elseif ($ip === $trusted) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Parse raw request body (JSON or Form data).
     *
     * @return array<string, mixed>
     */
    private function parseIncomingBody(): array
    {
        if (in_array($this->method, ['GET', 'HEAD'], true)) {
            return [];
        }

        $contentType = $this->getHeader('Content-Type') ?? '';

        if (str_contains($contentType, 'application/json') || empty($contentType)) {
            $rawInput = file_get_contents('php://input');
            if ($rawInput !== false && trim($rawInput) !== '') {
                try {
                    $decoded = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
                    return is_array($decoded) ? $decoded : [];
                } catch (\JsonException $e) {
                    throw new InvalidArgumentException("Malformed JSON payload: " . $e->getMessage(), 400);
                }
            }
        }

        return $_POST ?? [];
    }

    /**
     * Extract HTTP headers from $_SERVER.
     *
     * @return array<string, string>
     */
    private function extractHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerName] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $headerName = strtolower(str_replace('_', '-', $key));
                $headers[$headerName] = (string) $value;
            }
        }
        return $headers;
    }
}
