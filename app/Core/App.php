<?php
declare(strict_types=1);

namespace Core;

/**
 * Front controller kernel. Boots configuration, opens the database, resolves
 * the route, enforces authentication/permissions and converts the controller
 * result into the HTTP response.
 */
final class App
{
    private array $config;
    private Database $db;
    private Auth $auth;
    private View $view;
    private Router $router;
    private Request $request;
    private string $basePath;

    public function __construct(array $config)
    {
        $this->config = $config;

        $this->config['app']['debug'] = (bool) ($this->config['app']['debug'] ?? false);

        Logger::bind($this->config['paths']['storage'] . '/logs');

        $this->request = new Request();

        // Before the View is built, because it shares currentPath with every
        // layout, and before dispatch, because routes are matched against it.
        $this->basePath = self::resolveBasePath($this->config['app']);
        $this->request->setBasePath($this->basePath);

        $this->db = Database::instance($this->config['database']);

        $this->auth = new Auth($this->db, $this->config['app']);
        $this->auth->startSession();

        $this->view = new View($this->config['paths']['views'], $this->config['app']['layout']);
        $this->view->share('appName', $this->config['app']['name']);
        $this->view->share('appShort', $this->config['app']['short_name']);
        $this->view->share('appVersion', $this->config['app']['version']);
        $this->view->share('auth', $this->auth);
        $this->view->share('db', $this->db);
        $this->view->share('currentUser', $this->auth->user());
        $this->view->share('currentPath', $this->request->path());
        $this->view->share('currentSeason', $this->currentSeason());
        $this->view->share('unreadNotifications', $this->unreadNotificationCount());

        Audit::bind($this->db, $this->auth->user(), $this->request);
        Setting::bind($this->db);
        $GLOBALS['cahu_app']    = $this;
        $GLOBALS['cahu_config'] = $this->config;

        $this->router = new Router();
    }

    public function run(): void
    {
        try {
            // The config is handed to the route file because one route's path is
            // derived from configuration: the upload prefix. tools/check-routes.php
            // calls the same closure with the router alone, which is why the second
            // parameter is optional and falls back to the documented default.
            (require $this->config['paths']['routes'])($this->router, $this->config);

            $response = $this->dispatch();
        } catch (HttpException $exception) {
            $response = $this->renderError($exception->statusCode(), $exception->getMessage());
        } catch (\PDOException $exception) {
            Logger::exception($exception);
            $response = $this->renderError(
                500,
                $this->config['app']['debug']
                    ? $exception->getMessage()
                    : 'A database error occurred. The operation was not completed.'
            );
        } catch (\Throwable $exception) {
            Logger::exception($exception);
            $response = $this->renderError(
                500,
                $this->config['app']['debug']
                    ? $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine()
                    : 'An unexpected error occurred. The operation was not completed.'
            );
        }

        $response->send();
    }

    public function db(): Database
    {
        return $this->db;
    }

    public function auth(): Auth
    {
        return $this->auth;
    }

    public function view(): View
    {
        return $this->view;
    }

    public function request(): Request
    {
        return $this->request;
    }

    /**
     * Reads application configuration, either the whole array or a dotted key
     * such as 'uploads.max_image_kb'. Services and controllers use this instead
     * of re-reading environment files.
     */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        $value = $this->config;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function dispatch(): Response
    {
        $route = $this->router->match($this->request->method(), $this->request->path());

        if ($route === null) {
            if ($this->request->wantsJson()) {
                return Response::error('Endpoint not found.', 404);
            }

            throw new HttpException(404, 'The page you requested could not be found.');
        }

        foreach ($route['middleware'] as $permission) {
            if ($permission === 'api') {
                $this->guardApi();

                continue;
            }

            $this->guard($permission);
        }

        $handler = $route['handler'];

        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller        = new $class($this->db, $this->auth, $this->view, $this->request);
            $result            = $controller->{$method}(...array_values($route['params']));
        } elseif (is_callable($handler)) {
            $result = $handler(...array_values($route['params']));
        } else {
            throw new HttpException(500, 'Route handler is not callable.');
        }

        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        return Response::html((string) $result);
    }

    private function guard(string $permission): void
    {
        if ($permission === 'guest') {
            if ($this->auth->check()) {
                throw new HttpException(302, '');
            }

            return;
        }

        if (!$this->auth->check()) {
            if ($this->request->wantsJson()) {
                throw new HttpException(401, 'Authentication is required.');
            }

            $_SESSION['_intended'] = $this->request->fullUrl();
            Flash::warning('Please sign in to continue.');

            throw new HttpException(302, '');
        }

        if ($permission !== 'auth' && !$this->auth->can($permission)) {
            throw new HttpException(403, 'You do not have permission to perform this action.');
        }
    }

    /**
     * Machine-to-machine access for the REST API. A bearer token is accepted
     * first so the mobile app works without cookies; a signed-in browser session
     * is still honoured, which keeps the API explorable from the documentation
     * screen.
     */
    private function guardApi(): void
    {
        if ($this->auth->check()) {
            return;
        }

        if ($this->auth->authenticateToken($this->request->bearerToken(), $this->request)) {
            return;
        }

        throw new HttpException(401, 'A valid API token is required.');
    }

    private function renderError(int $status, string $message): Response
    {
        if ($status === 302) {
            $target = $_SESSION['_intended'] ?? $this->config['app']['base_path'] . '/login';
            unset($_SESSION['_intended']);

            return Response::redirect($target);
        }

        if ($this->request->wantsJson()) {
            return Response::error($message, $status);
        }

        $template = match ($status) {
            403     => 'errors/403',
            404     => 'errors/404',
            default => 'errors/500',
        };

        try {
            return Response::html($this->view->render($template, ['message' => $message, 'status' => $status]), $status);
        } catch (\Throwable) {
            return Response::html(
                '<!doctype html><html><head><meta charset="utf-8"><title>Error ' . $status . '</title></head>'
                . '<body style="font-family:system-ui;padding:3rem"><h1>Error ' . $status . '</h1><p>'
                . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>',
                $status
            );
        }
    }

    private function currentSeason(): ?array
    {
        try {
            return $this->db->selectOne(
                "SELECT * FROM seasons WHERE status = 'open' ORDER BY start_date DESC LIMIT 1"
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function unreadNotificationCount(): int
    {
        if (!$this->auth->check()) {
            return 0;
        }

        try {
            return (int) $this->db->scalar(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0',
                ['uid' => $this->auth->id()]
            );
        } catch (\Throwable) {
            return 0;
        }
    }

/* ------------------------------------------------------------------ */
/* Global view helpers                                                 */
/* ------------------------------------------------------------------ */

    /**
     * The path the application is served from: '' at the server root, or a
     * string like '/cahu3' in a subdirectory. Never has a trailing slash.
     *
     * config['app']['base_path'] is the authority, but an empty value is not
     * a reason for every stylesheet and script to 404. It is empty before the
     * installer has run, and it can be cleared by hand; in either case a
     * subdirectory install still needs its prefix, and without one
     * asset('assets/js/app.js') resolves to '/assets/js/app.js', which the
     * server does not serve. The fallbacks below recover the prefix from the
     * two other places it is recorded: the APP_URL the installer writes, and
     * the path of the front controller as the web server resolved it, which
     * is where the running request actually lives.
     *
     * Static and public so helpers.php can reach it before the kernel has been
     * constructed - a fatal error page still has to emit usable markup.
     */
    public static function resolveBasePath(array $app): string
    {
        foreach ([
            $app['base_path'] ?? '',
            $app['url'] ?? '',
            self::scriptBasePath(),
        ] as $candidate) {
            $path = self::normaliseBasePath((string) $candidate);

            if ($path !== '') {
                return $path;
            }
        }

        return '';
    }

    /**
     * Accepts a bare path or a whole URL and returns '' or '/prefix'.
     */
    private static function normaliseBasePath(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value));

        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $value)) {
            $value = (string) parse_url($value, PHP_URL_PATH);
        }

        $value = '/' . trim($value, '/');

        return $value === '/' ? '' : rtrim($value, '/');
    }

    /**
     * SCRIPT_NAME for a request this project is serving, minus the front
     * controller: '/cahu3/public/index.php' becomes '/cahu3'.
     *
     * Only usable once the request has been routed to public/index.php. Asked
     * for anything else - public/install.php, or a static asset - it is not the
     * base of the application, and the rewrite rules in public/.htaccess already
     * answer those requests before the kernel runs.
     */
    private static function scriptBasePath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        return (string) preg_replace('~/public/index\.php$~', '', $script);
    }

    /**
     * The resolved base path, for callers that need it as a value rather than
     * through url().
     */
    public function basePath(): string
    {
        return $this->basePath;
    }

    public function url(string $path = ''): string
    {
        return $this->basePath . '/' . ltrim($path, '/');
    }

    public function asset(string $path): string
    {
        return $this->url(ltrim($path, '/'));
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        static $cache = [];

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $row = $this->db->selectOne('SELECT setting_value FROM system_settings WHERE setting_key = :k', ['k' => $key]);
            $cache[$key] = $row === null ? $default : $row['setting_value'];
        } catch (\Throwable) {
            $cache[$key] = $default;
        }

        return $cache[$key];
    }
}
