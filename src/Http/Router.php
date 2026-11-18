<?php
declare(strict_types=1);

namespace RB\Http;

use RB\Support\Request;

/**
 * روتر سبک با پارامترهای مسیر ({id})
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,handler:array,middleware:array}> */
    private array $routes = [];

    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    public function any(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('ANY', $pattern, $handler, $middleware);
    }

    private function add(string $method, string $pattern, array $handler, array $middleware): void
    {
        $regex = '#^' . preg_replace('~\{(\w+)\}~', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [
            'method' => $method,
            'pattern' => $regex,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(): void
    {
        $method = Request::method();
        // متدهای PUT/DELETE از طریق POST + _method پشتیبانی می‌شوند
        if ($method === 'POST' && !empty($_POST['_method'])) {
            $method = strtoupper((string) $_POST['_method']);
        }
        $path = Request::path();
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        $trace = \RB\Support\Env::bool('APP_DEBUG', false);
        if ($trace) {
            error_log('[router] dispatch ' . $method . ' ' . $path . ' routes=' . count($this->routes));
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== 'ANY' && $route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['pattern'], $path, $matches)) {
                if ($trace) {
                    error_log('[router] matched ' . $route['pattern'] . ' mw=' . json_encode($route['middleware']));
                }
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                foreach ($route['middleware'] as $middleware) {
                    $result = Middleware::run($middleware);
                    if ($result === false) {
                        return;
                    }
                }
                [$class, $action] = $route['handler'];
                $controller = new $class();
                $controller->{$action}($params);
                if ($trace) {
                    error_log('[router] handled by ' . $class . '::' . $action
                        . ' ob=' . ob_get_level() . ' sent=' . var_export(headers_sent(), true));
                }
                return;
            }
        }

        http_response_code(404);
        if (str_starts_with($path, '/api/')) {
            json_out(['ok' => false, 'error' => 'مسیر مورد نظر یافت نشد.'], 404);
            return;
        }
        echo \RB\Support\View::render('errors/404', ['path' => $path], 'none');
    }
}
