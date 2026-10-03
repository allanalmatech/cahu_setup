<?php
declare(strict_types=1);

namespace Core;

/**
 * Regex based router. Supports GET/POST/PUT/DELETE, {param} placeholders and
 * an optional per-route auth/permission guard.
 */
final class Router
{
    /** @var array<int,array{method:string,regex:string,params:array<int,string>,handler:mixed,middleware:array<int,string>}> */
    private array $routes = [];

    /** @var array<int,array{param:string,regex:string}> */
    private array $patterns = [];

    public function get(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    public function any(string $path, mixed $handler, array $middleware = []): void
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            $this->add($method, $path, $handler, $middleware);
        }
    }

    private function add(string $method, string $path, mixed $handler, array $middleware): void
    {
        $params = [];

        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            function (array $matches) use (&$params): string {
                $params[]                  = $matches[1];
                $this->patterns[$matches[1]] = $matches[2] ?? '[^/]+';

                return '(' . $this->patterns[$matches[1]] . ')';
            },
            $path
        );

        $this->routes[] = [
            'method'     => $method,
            'regex'      => '#^' . $regex . '$#',
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * All registered routes, used by the development route check and by tests.
     *
     * @return array<int,array{method:string,regex:string,params:array<int,string>,handler:mixed,middleware:array<int,string>}>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @return array{handler:mixed,params:array<string,string>,middleware:array<int,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && !($method === 'HEAD' && $route['method'] === 'GET')) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            array_shift($matches);

            return [
                'handler'    => $route['handler'],
                'params'     => array_combine($route['params'], $matches) ?: [],
                'middleware' => $route['middleware'],
            ];
        }

        return null;
    }
}
