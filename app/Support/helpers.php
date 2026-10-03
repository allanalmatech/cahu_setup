<?php
declare(strict_types=1);

use Core\Csrf;
use Core\Flash;
use Core\Setting;

if (!function_exists('e')) {
    /**
     * Escapes a value for safe output in HTML. Every dynamic value printed by
     * a view must pass through this function.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('old')) {
    /**
     * Returns a previously submitted value after a validation redirect.
     */
    function old(string $key, mixed $default = ''): mixed
    {
        return Flash::input()[$key] ?? $default;
    }
}

if (!function_exists('error_for')) {
    /**
     * Returns a validation error message for a field.
     */
    function error_for(string $field): ?string
    {
        $errors = Flash::errors();

        return $errors[$field] ?? null;
    }
}

if (!function_exists('has_error')) {
    function has_error(string $field): bool
    {
        return error_for($field) !== null;
    }
}

if (!function_exists('invalid_class')) {
    /**
     * Returns the CSS class for a form control that failed validation.
     */
    function invalid_class(string $field, string $base = 'form-control'): string
    {
        return $base . (has_error($field) ? ' is-invalid' : '');
    }
}

if (!function_exists('old_input_exists')) {
    function old_input_exists(): bool
    {
        return $_SESSION['_old_input'] !== null && $_SESSION['_old_input'] !== [];
    }
}

if (!function_exists('active_class')) {
    /**
     * Marks the active navigation item.
     */
    function active_class(string $prefix, string $current, string $class = 'active'): string
    {
        return str_starts_with($current, $prefix) ? $class : '';
    }
}

if (!function_exists('status_badge')) {
    /**
     * Renders a consistent status pill for the given status value.
     */
    function status_badge(?string $status, ?string $label = null): string
    {
        if ($status === null || $status === '') {
            return '<span class="badge text-bg-secondary">Unknown</span>';
        }

        $colours = [
            'active'        => 'success',
            'completed'     => 'success',
            'resolved'      => 'success',
            'delivered'     => 'success',
            'paid'          => 'success',
            'fulfilled'     => 'success',
            'harvested'     => 'success',
            'sold'          => 'success',
            'exported'      => 'success',
            'closed'        => 'success',
            'confirmed'     => 'success',
            'action_taken'  => 'info',
            'in_transit'    => 'info',
            'investigating' => 'info',
            'assigned'      => 'info',
            'planted'       => 'info',
            'submitted'     => 'info',
            'reviewed'      => 'info',
            'aggregated'    => 'info',
            'open'          => 'info',
            'draft'         => 'secondary',
            'pending'       => 'secondary',
            'planned'       => 'secondary',
            'future'        => 'secondary',
            'inactive'      => 'secondary',
            'cancelled'     => 'secondary',
            'deactivated'   => 'secondary',
            'due'           => 'warning',
            'partial'       => 'warning',
            'partially_used' => 'warning',
            'low'           => 'warning',
            'fair'          => 'warning',
            'fallow'        => 'warning',
            'partially_delivered' => 'warning',
            'overdue'       => 'danger',
            'critical'      => 'danger',
            'severe'        => 'danger',
            'poor'          => 'danger',
            'failed'        => 'danger',
            'suspended'     => 'danger',
            'disputed'      => 'danger',
            'unpaid'        => 'danger',
            'deceased'      => 'dark',
            'exited'        => 'dark',
            'abandoned'     => 'dark',
            'reported'      => 'primary',
        ];

        $colour = $colours[strtolower($status)] ?? 'secondary';

        return sprintf(
            '<span class="badge text-bg-%s">%s</span>',
            $colour,
            e($label ?? ucfirst(str_replace('_', ' ', $status)))
        );
    }
}

if (!function_exists('priority_badge')) {
    /**
     * Priority is drawn differently from status: a critical task must be
     * noticeable in a list without reading the label.
     */
    function priority_badge(?string $priority, ?string $label = null): string
    {
        if ($priority === null || $priority === '') {
            return '<span class="badge text-bg-light text-dark">Not set</span>';
        }

        [$colour, $mark] = match (strtolower($priority)) {
            'critical' => ['danger', '!!'],
            'high'     => ['warning', '!'],
            'low'      => ['light', ''],
            default    => ['info', ''],
        };

        $text = $label ?? ucfirst(str_replace('_', ' ', $priority));
        $text = $mark === '' ? e($text) : '<span aria-hidden="true">' . $mark . ' </span>' . e($text);

        return sprintf(
            '<span class="badge text-bg-%s%s">%s</span>',
            $colour,
            $colour === 'light' ? ' text-dark' : '',
            $text
        );
    }
}

if (!function_exists('format_number')) {
    function format_number(mixed $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        return number_format((float) $value, $decimals);
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $date, string $format = 'd M Y'): string
    {
        if ($date === null || $date === '' || str_starts_with($date, '0000')) {
            return '-';
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? '-' : date($format, $timestamp);
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime(?string $value, string $format = 'd M Y, H:i'): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? '-' : date($format, $timestamp);
    }
}

if (!function_exists('format_money')) {
    function format_money(mixed $value, string $currency = 'UGX'): string
    {
        return $currency . ' ' . number_format((float) $value, 0);
    }
}

if (!function_exists('format_quantity')) {
    function format_quantity(mixed $value, ?string $unit = null): string
    {
        $formatted = number_format((float) $value, 2);

        return $unit === null || $unit === '' ? $formatted : $formatted . ' ' . $unit;
    }
}

if (!function_exists('percent')) {
    function percent(float $part, float $total, int $decimals = 1): float
    {
        return $total <= 0.0 ? 0.0 : round(($part / $total) * 100, $decimals);
    }
}

if (!function_exists('query_string')) {
    /**
     * Rebuilds the current query string with one parameter changed, used by
     * pagination and sorting links.
     */
    function query_string(array $overrides, ?string $keep = null): string
    {
        $params = $keep === null ? $_GET : array_merge($_GET, $keep);

        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($params[$key]);
            } else {
                $params[$key] = $value;
            }
        }

        return http_build_query($params);
    }
}

if (!function_exists('setting')) {
    /**
     * Reads a value from the system_settings table (with a per-request cache).
     */
    function setting(string $key, mixed $default = null): mixed
    {
        return Setting::get($key, $default);
    }
}

if (!function_exists('asset')) {
    /**
     * Resolves to the base URL when the kernel is up.
     *
     * It is called from layouts and error pages alike, so it has to cope with
     * being reached before an App exists. Returning the bare path there would
     * point every stylesheet and script at the server root, which is the one
     * thing guaranteed to be wrong in a subdirectory install.
     */
    function asset(string $path): string
    {
        $app = $GLOBALS['cahu_app'] ?? null;

        return $app === null
            ? \Core\App::resolveBasePath([]) . '/' . ltrim($path, '/')
            : $app->asset($path);
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        $app = $GLOBALS['cahu_app'] ?? null;

        return $app === null
            ? \Core\App::resolveBasePath([]) . '/' . ltrim($path, '/')
            : $app->url($path);
    }
}

if (!function_exists('setting_url')) {
    function setting_url(string $key, mixed $default = null): mixed
    {
        return Setting::get($key, $default);
    }
}
