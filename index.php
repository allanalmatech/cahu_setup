<?php
declare(strict_types=1);

/**
 * Project-root entry point.
 *
 * The application itself lives in public/, and that is the directory the
 * document root should point at. This file exists for the case where it does
 * not: someone opens the site and gets the project root, and without it they
 * would get either a directory listing or a 403 from the deny rules in
 * .htaccess.
 *
 * So this is a redirect and nothing else. It deliberately does not boot the
 * application: the config may not exist yet (fresh checkout), and a landing
 * page that fails because the database is unreachable is worse than one that
 * simply sends the browser to the real front controller.
 *
 * A fresh install is pointed at install.php by hand. The browser installer
 * lives in public/ rather than here, because this directory's own rules refuse
 * every .php that is not this file:
 *     https://example.org/public/install.php
 * and the same six steps are available from a shell:  php install.php
 */

$cahuRequested = $_SERVER['REQUEST_URI'] ?? '/';

/*
 * Work out the prefix this project is served under.
 *
 * SCRIPT_NAME is used rather than a hard-coded path so the redirect is correct
 * both when the document root is the project root and when the whole project
 * sits in a subfolder such as /cahu. dirname() gives the directory containing
 * this file, relative to the document root.
 */
$cahuDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
$cahuDir = rtrim($cahuDir, '/');

/*
 * The target. A subfolder install also has to be in the application's base
 * path for Core\Request to strip it off the request path, which is what
 * APP_BASE_PATH is for; until that is configured the routes would 404, so the
 * subfolder is passed through as a query hint for the README rather than
 * silently producing a redirect loop.
 *
 * The trailing slash matters. Sending the browser to public/index.php instead
 * would arrive as the path "/index.php" once APP_BASE_PATH has been stripped,
 * and that matches no route, so the project root would 404 even on a perfectly
 * good install. A request for public/ is served by the front controller with
 * the path "/", which is a route.
 */
$cahuTarget = $cahuDir . '/public/';

// Keep the query string, so ?page=2 and friends survive the hop.
$cahuQuery = parse_url($cahuRequested, PHP_URL_QUERY);

if (is_string($cahuQuery) && $cahuQuery !== '') {
    $cahuTarget .= '?' . $cahuQuery;
}

// A redirect rather than a require: the browser's address bar ends up on the
// real route, so a reload does not come back here.
header('Location: ' . $cahuTarget, true, 302);
header('Cache-Control: no-store');
