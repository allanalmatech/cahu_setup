<?php
declare(strict_types=1);

/**
 * Autoloader and shared bootstrap. Registers the Core namespace, loads the
 * global helper functions and prepares the storage directories.
 */

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Core\\'     => __DIR__ . '/Core/',
        'Console\\'  => __DIR__ . '/Console/',
        'App\\'      => __DIR__ . '/Support/',
        'Models\\'   => __DIR__ . '/Models/',
        'Services\\' => __DIR__ . '/Services/',
        'Http\\'     => __DIR__ . '/Http/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file     = $directory . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;

            return;
        }
    }
});

foreach (glob(__DIR__ . '/Support/helpers.php') ?: [] as $helperFile) {
    require $helperFile;
}

$storage = dirname(__DIR__) . '/storage';

foreach ([$storage, $storage . '/logs', $storage . '/uploads', $storage . '/cache'] as $directory) {
    if (!is_dir($directory)) {
        @mkdir($directory, 0770, true);
    }
}
