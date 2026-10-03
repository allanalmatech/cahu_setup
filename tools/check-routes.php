<?php
declare(strict_types=1);

/**
 * Development check: loads every class in the application (which surfaces
 * inheritance and method signature conflicts that php -l cannot see) and lists
 * every route whose controller class or action method does not exist yet.
 *
 * Run from the project root:
 *
 *   php tools/check-routes.php
 */

/*
 * Command line only. This loads every controller, which means reaching it over
 * HTTP would execute the application and then print the route table, the
 * controller list and the permission map to whoever asked. tools/.htaccess also
 * refuses the directory, but nginx ignores .htaccess entirely, so the guard
 * lives here as well: a script that is safe on one server and unsafe on the
 * next is not a control.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This check may only be run from the command line.\n");
}

require __DIR__ . '/../app/bootstrap.php';

/* -------------------------------------------------------------------------- */
/* 1. Class loading                                                           */
/* -------------------------------------------------------------------------- */

$appDir   = dirname(__DIR__) . '/app';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));
$failed   = [];

// Mirrors the autoloader mapping so class names are derived the same way.
$prefixes = [
    'app/Core/'     => 'Core\\',
    'app/Support/'  => 'App\\',
    'app/Models/'   => 'Models\\',
    'app/Services/' => 'Services\\',
    'app/Http/'     => 'Http\\',
];

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    $root = str_replace('\\', '/', dirname(__DIR__)) . '/';

    if (!str_starts_with($path, $root)) {
        continue;
    }

    $relative = substr($path, strlen($root));

    $prefix = null;
    foreach ($prefixes as $directory => $namespace) {
        if (str_starts_with($relative, $directory)) {
            $prefix = $namespace;
            break;
        }
    }

    if ($prefix === null) {
        continue;
    }

    $class = $prefix . str_replace('/', '\\', substr(substr($relative, strlen($directory)), 0, -4));

    // Views are plain templates, not classes, and bootstrap/routes are already
    // loaded by the autoloader.
    if (str_contains($class, '\\Views\\') || in_array($class, ['App\\helpers', 'App\\bootstrap', 'App\\routes'], true)) {
        continue;
    }

    try {
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
            $failed[] = $class . ' (not autoloadable)';
        }
    } catch (\Throwable $exception) {
        $failed[] = $class . ' -> ' . $exception->getMessage();
    }
}

if ($failed === []) {
    echo "Classes: all load cleanly\n";
} else {
    echo "Classes failing to load: " . count($failed) . "\n";

    foreach ($failed as $class) {
        echo '  - ' . $class . "\n";
    }
}

echo "\n";

/* -------------------------------------------------------------------------- */
/* 2. Route coverage                                                          */
/* -------------------------------------------------------------------------- */

$missingClasses = [];
$missingMethods = [];
$permissions    = [];

$router = new Core\Router();
$register = require __DIR__ . '/../app/routes.php';
$register($router);

$registered = count($router->routes());

/*
 * The total is printed as well as the failures on purpose. "Controllers
 * missing: 0" is also what a routes.php that registered nothing at all would
 * print - a fatal inside the closure, a rename that moved the file, or a
 * require that returned null - so on its own the check cannot tell "every
 * controller exists" from "no routes were ever registered". A route count in
 * the low hundreds is the signal that something regressed.
 */
if ($registered < 100) {
    fwrite(STDERR, "WARNING: only {$registered} route(s) registered; routes.php looks truncated.\n");
}

foreach ($router->routes() as $route) {
    $method = $route['method'];
    $path   = $route['regex'];
    $handler = $route['handler'];

    if (!is_array($handler)) {
        continue;
    }

    [$class, $action] = $handler;

    if (!class_exists($class)) {
        $missingClasses[$class][] = strtoupper($method) . ' ' . $path;
    } elseif (!method_exists($class, $action)) {
        $missingMethods[$class . '::' . $action][] = strtoupper($method) . ' ' . $path;
    }

    foreach ($route['middleware'] as $permission) {
        if (!in_array($permission, ['auth', 'guest', 'api'], true)) {
            $permissions[$permission] = ($permissions[$permission] ?? 0) + 1;
        }
    }
}

$total = static function (array $map): int {
    return array_sum(array_map('count', $map));
};

echo "Routes registered: {$registered}\n";
echo "Controllers missing: " . count($missingClasses) . " (" . $total($missingClasses) . " routes)\n";

foreach ($missingClasses as $class => $routes) {
    echo '  - ' . $class . ' (' . count($routes) . " routes)\n";
}

if ($missingMethods !== []) {
    echo "\nActions missing: " . count($missingMethods) . "\n";

    foreach ($missingMethods as $method => $routes) {
        echo '  - ' . $method . "\n";
    }
}

echo "\nPermissions referenced: " . count($permissions) . "\n";
echo '  ' . implode(', ', array_keys($permissions)) . "\n";
