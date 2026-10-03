<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;
use InvalidArgumentException;

/**
 * Lightweight, zero-dependency RESTful regex router with middleware support.
 */
class Router
{
    /**
     * @var array<array{method: string, pattern: string, regex: string, paramNames: array<string>, handler: callable|array, middlewares: array<callable>}>
     */
    private array $routes = [];

    /**
     * @var array<callable> Global middlewares executed on every dispatch.
     */
    private array $globalMiddlewares = [];

    /**
     * Register a GET route.
     *
     * @param string $path
     * @param callable|array $handler
     * @param array<callable> $middlewares
     * @return self
     */
    public function get(string $path, callable|array $handler, array $middlewares = []): self
    {
        return $this->add('GET', $path, $handler, $middlewares);
    }

    /**
     * Register a POST route.
     *
     * @param string $path
     * @param callable|array $handler
     * @param array<callable> $middlewares
     * @return self
     */
    public function post(string $path, callable|array $handler, array $middlewares = []): self
    {
        return $this->add('POST', $path, $handler, $middlewares);
    }

    /**
     * Register a PUT route.
     *
     * @param string $path
     * @param callable|array $handler
     * @param array<callable> $middlewares
     * @return self
     */
    public function put(string $path, callable|array $handler, array $middlewares = []): self
    {
        return $this->add('PUT', $path, $handler, $middlewares);
    }

    /**
     * Register a DELETE route.
     *
     * @param string $path
     * @param callable|array $handler
     * @param array<callable> $middlewares
     * @return self
     */
    public function delete(string $path, callable|array $handler, array $middlewares = []): self
    {
        return $this->add('DELETE', $path, $handler, $middlewares);
    }

    /**
     * Add a route with custom HTTP verb.
     *
     * @param string $method
     * @param string $path
     * @param callable|array $handler
     * @param array<callable> $middlewares
     * @return self
     */
    public function add(string $method, string $path, callable|array $handler, array $middlewares = []): self
    {
        $normalizedPath = '/' . trim($path, '/');
        if ($normalizedPath !== '/' && str_ends_with($normalizedPath, '/')) {
            $normalizedPath = rtrim($normalizedPath, '/');
        }

        // Convert {paramName} into named regex capture groups
        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($matches) use (&$paramNames) {
            $paramNames[] = $matches[1];
            return '(?P<' . $matches[1] . '>[^/]+)';
        }, $normalizedPath);

        $regex = '#^' . $regex . '$#u';

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $normalizedPath,
            'regex' => $regex,
            'paramNames' => $paramNames,
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];

        return $this;
    }

    /**
     * Attach a global middleware.
     *
     * @param callable $middleware
     * @return self
     */
    public function use(callable $middleware): self
    {
        $this->globalMiddlewares[] = $middleware;
        return $this;
    }

    /**
     * Dispatch an incoming Request through the routing pipeline.
     *
     * @param Request $request
     * @return void
     */
    public function dispatch(Request $request): void
    {
        // Execute global middlewares across all requests (API & web shell)
        foreach ($this->globalMiddlewares as $middleware) {
            $middleware($request);
        }

        $requestMethod = $request->getMethod();
        $requestPath = '/' . trim($request->getPath(), '/');
        if ($requestPath !== '/' && str_ends_with($requestPath, '/')) {
            $requestPath = rtrim($requestPath, '/');
        }

        $allowedMethodsForPath = [];
        $matchedRoute = null;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $requestPath, $matches)) {
                $allowedMethodsForPath[] = $route['method'];

                if ($route['method'] === $requestMethod) {
                    $matchedRoute = $route;
                    // Extract named parameters
                    $params = [];
                    foreach ($route['paramNames'] as $paramName) {
                        $params[$paramName] = $matches[$paramName] ?? null;
                    }
                    $request->setRouteParams($params);
                    break;
                }
            }
        }

        // 1. Matched Route Execution
        if ($matchedRoute !== null) {
            $this->executePipeline($request, $matchedRoute);
            return;
        }

        // 2. HTTP 405 Method Not Allowed
        if (!empty($allowedMethodsForPath)) {
            $allowedList = implode(', ', array_unique($allowedMethodsForPath));
            if (!headers_sent()) {
                header("Allow: {$allowedList}");
            }
            Response::error(
                "Method {$requestMethod} not allowed for {$requestPath}. Allowed methods: {$allowedList}",
                'METHOD_NOT_ALLOWED',
                ['allowed_methods' => $allowedMethodsForPath],
                405
            );
            return;
        }

        // 3. HTTP 404 Not Found for API routes
        if (str_starts_with($requestPath, '/api/')) {
            Response::error(
                "API endpoint '{$requestPath}' not found.",
                'NOT_FOUND',
                null,
                404
            );
        }

        // If non-API route, let index.php fall through to web shell
    }

    /**
     * Execute global middlewares, route middlewares, and the target handler.
     *
     * @param Request $request
     * @param array $route
     * @return void
     */
    private function executePipeline(Request $request, array $route): void
    {
        // Execute route-specific middlewares
        foreach ($route['middlewares'] as $middleware) {
            $middleware($request);
        }

        // Execute handler
        $handler = $route['handler'];

        if (is_callable($handler)) {
            $handler($request);
            return;
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            if (is_string($class)) {
                if (!class_exists($class)) {
                    throw new InvalidArgumentException("Controller class '{$class}' not found.", 500);
                }
                $instance = new $class();
            } else {
                $instance = $class;
            }

            if (!method_exists($instance, $method)) {
                throw new InvalidArgumentException("Method '{$method}' does not exist on controller.", 500);
            }

            $instance->$method($request);
            return;
        }

        throw new InvalidArgumentException("Invalid route handler configured for {$route['pattern']}", 500);
    }
}
