<?php
declare(strict_types=1);

namespace Core;

/**
 * Immutable-ish wrapper around the current request. Keeps controllers free of
 * direct superglobal access so that input can be sanitised in one place.
 */
final class Request
{
    private string $method;
    private string $path;
    /** @var array<string,mixed> */
    private array $query;
    /** @var array<string,mixed> */
    private array $body;
    /** @var array<string,mixed> */
    private array $files;
    /** @var array<string,mixed> */
    private array $server;

    public function __construct()
    {
        $this->server = $_SERVER;
        $this->query  = $_GET;
        $this->body   = $_POST;
        $this->files  = $_FILES;
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->path = '/' . trim(parse_url($uri, PHP_URL_PATH) ?: '', '/');
    }

    /**
     * Strips the configured base path from the front of the request path.
     *
     * Without this the application only works at the root of a domain. A
     * subfolder install is configured with APP_BASE_PATH=/cahu, and every URL
     * the application generates then correctly includes /cahu - but the
     * incoming request path still has it on the front, so it arrives as
     * "/cahu/farmers", matches no route, and returns 404 for every page and
     * every API endpoint. Generation and matching have to agree on the same
     * path, which is what this restores.
     *
     * Only a whole leading segment is removed, and only when what follows is a
     * path boundary. Without that, a base path of "/app" would also swallow
     * the first segment of a genuine "/applications" request. The value is not
     * decoded here, for the same reason the path is not decoded in the
     * constructor: a route pattern is written against the raw form.
     *
     * Called by Core\App once the configuration is known, and deliberately not
     * by the constructor, which has no access to it.
     */
    public function setBasePath(string $basePath): void
    {
        $basePath = '/' . trim($basePath, '/');

        if ($basePath === '/') {
            return;
        }

        if ($this->path === $basePath) {
            $this->path = '/';

            return;
        }

        if (str_starts_with($this->path, $basePath . '/')) {
            $this->path = substr($this->path, strlen($basePath));
        }
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    public function wantsJson(): bool
    {
        return $this->isAjax()
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_starts_with($this->path, '/api/');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * Trimmed string input. Arrays are returned untouched so callers can decide
     * how to handle them.
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $key): bool
    {
        return filter_var($this->input($key, false), FILTER_VALIDATE_BOOL);
    }

    /**
     * Date input normalised to Y-m-d, or null when the value is absent or not a
     * real calendar date. This keeps '0000-00-00' and malformed values out of
     * date columns, which MySQL strict mode would otherwise reject.
     */
    public function date(string $key): ?string
    {
        $value = $this->string($key);

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /**
     * @return array<string,mixed>
     */
    public function only(array $keys): array
    {
        $data = [];
        foreach ($keys as $key) {
            if ($this->has($key)) {
                $data[$key] = $this->input($key);
            }
        }

        return $data;
    }

    /**
     * Raw JSON body decoded into the body array. Used by the REST API.
     */
    public function json(): array
    {
        if ($this->body !== []) {
            return $this->body;
        }

        $raw = file_get_contents('php://input');

        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if ($header !== '' && preg_match('/Bearer\s+(\S+)/i', (string) $header, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function files(string $key): array
    {
        $files = $this->files[$key] ?? null;

        if (!is_array($files) || !isset($files['name'])) {
            return [];
        }

        if (!is_array($files['name'])) {
            return [$key => $files];
        }

        $normalised = [];
        foreach (array_keys($files['name']) as $index) {
            $normalised[$key . '[' . $index . ']'] = [
                'name'     => $files['name'][$index],
                'type'     => $files['type'][$index],
                'tmp_name' => $files['tmp_name'][$index],
                'error'    => $files['error'][$index],
                'size'     => $files['size'][$index],
            ];
        }

        return $normalised;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function fullUrl(): string
    {
        return (string) ($this->server['REQUEST_URI'] ?? '/');
    }
}
