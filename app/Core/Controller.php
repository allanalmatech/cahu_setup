<?php
declare(strict_types=1);

namespace Core;

/**
 * Base controller. Provides the shared plumbing every screen needs: rendering,
 * redirects, JSON responses, permission checks and the standard list-filter
 * handling used across the register pages.
 */
abstract class Controller
{
    protected Database $db;
    protected Auth $auth;
    protected View $view;
    protected Request $request;

    public function __construct(Database $db, Auth $auth, View $view, Request $request)
    {
        $this->db      = $db;
        $this->auth    = $auth;
        $this->view    = $view;
        $this->request = $request;
    }

    /**
     * @param array<string,mixed> $data
     */
    protected function render(string $template, array $data = [], ?string $layout = null): Response
    {
        return Response::html($this->view->render($template, $data, $layout));
    }

    /**
     * @param array<string,mixed> $data
     */
    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $url): Response
    {
        return Response::redirect($url);
    }

    /**
     * Reads application configuration by dotted key, e.g.
     * $this->config('uploads.max_image_kb'). Keeps controllers free of any
     * dependency on how configuration is loaded.
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        $app = $GLOBALS['cahu_app'] ?? null;

        return $app === null ? $default : $app->config($key, $default);
    }

    /**
     * Renders back to the referring form with validation errors and the old
     * input preserved, so the user does not lose their typing.
     *
     * @param array<string,string> $errors
     */
    protected function backWithErrors(string $url, array $errors, array $oldInput = []): Response
    {
        Flash::withInput($oldInput === [] ? $this->request->all() : $oldInput);
        $_SESSION['_errors'] = $errors;

        return $this->redirect($url);
    }

    /**
     * Guards a route. Called from the dispatcher via route middleware.
     */
    public function requirePermission(string $permission): void
    {
        if (!$this->auth->can($permission)) {
            if ($this->request->wantsJson()) {
                throw new HttpException(403, 'You do not have permission to perform this action.');
            }

            throw new HttpException(403, 'You do not have permission to view this page.');
        }
    }

    /**
     * Standard pagination + search parameters for register screens.
     *
     * @return array{page:int,per_page:int,search:string,sort:string,direction:string,filters:array<string,string>}
     */
    protected function listParams(int $defaultPerPage = 25): array
    {
        return [
            'page'       => max(1, $this->request->int('page', 1)),
            'per_page'   => min(200, max(5, $this->request->int('per_page', $defaultPerPage))),
            'search'     => $this->request->string('search'),
            'sort'       => $this->request->string('sort'),
            'direction'  => strtolower($this->request->string('direction')) === 'asc' ? 'asc' : 'desc',
            'filters'    => array_filter(
                $this->request->only([
                    'status', 'district_id', 'region_id', 'sub_county_id', 'group_id', 'officer_id',
                    'crop_id', 'season_id', 'category_id', 'problem_type_id', 'buyer_id', 'year', 'month',
                ]),
                static fn ($value): bool => $value !== null && $value !== ''
            ),
        ];
    }

    /**
     * Builds a WHERE clause from prepared-statement friendly fragments.
     *
     * @param array<string,mixed> $conditions
     * @param array<string,mixed> $params
     * @return array{0:string,1:array<string,mixed>}
     */
    protected function buildWhere(array $conditions, array $params = []): array
    {
        if ($conditions === []) {
            return ['', []];
        }

        $clauses = [];

        foreach ($conditions as $sql) {
            $clauses[] = '(' . $sql . ')';
        }

        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * Escapes output for HTML. Every dynamic value printed in a view goes
     * through this function.
     */
    protected function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Reads the coordinate pair posted by a Form::GPS field named `$base`.
     *
     * The control renders two inputs, so it posts `<base>_lat` and `<base>_lng`
     * and the base name itself never appears in the body. Reading the base name
     * therefore returns nothing and drops the location silently.
     *
     * A half-filled pair is treated as no location: a latitude with no longitude
     * is not a place. So is a pair outside the valid range, which is a typo
     * rather than a spot.
     *
     * @param array<string,mixed> $data
     * @return array{0:?float,1:?float}
     */
    protected function gps(array $data, string $base = 'latitude'): array
    {
        $lat = $data[$base . '_lat'] ?? null;
        $lng = $data[$base . '_lng'] ?? null;

        if (!is_numeric($lat) || !is_numeric($lng)) {
            return [null, null];
        }

        $latitude  = (float) $lat;
        $longitude = (float) $lng;

        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            return [null, null];
        }

        return [$latitude, $longitude];
    }

    /**
     * Turns a list of rows into a CSV download.
     *
     * @param array<int,string>                $columns
     * @param array<int,array<string,mixed>>    $rows
     */
    protected function csv(string $filename, array $columns, array $rows): Response
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, $columns);

        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = (string) ($row[$column] ?? '');
            }
            fputcsv($handle, $line);
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return Response::download("\xEF\xBB\xBF" . $content, $filename);
    }
}
