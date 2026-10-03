<?php
declare(strict_types=1);

/**
 * Checks that every url('...') literal used in a view actually corresponds to a
 * route in routes.php, and that it is reached with a method the route allows.
 *
 * This catches the mistakes that survive every other check and only show up as
 * a 404 or a 405 in the browser:
 *
 *   - a link to a page whose controller was never written,
 *   - a GET link pointed at a POST-only endpoint,
 *   - a form posting to a route that maps to a different action.
 *
 * Usage: php tools/check-links.php
 */

/*
 * Command line only, for the same reason as check-routes.php: the output is a
 * dump of every route and every link in the views. tools/.htaccess refuses the
 * directory on Apache; this guard covers the servers that ignore it.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This check may only be run from the command line.\n");
}

$root      = __DIR__ . '/..';
$viewPath  = $root . '/app/Views';
$routesSrc = (string) file_get_contents($root . '/app/routes.php');

/** @var array<int,array{method:string,path:string,segments:array<int,string>}> $routes */
$routes = [];

if (preg_match_all("/\\\$router->(get|post|put|patch|delete)\(\s*'([^']+)'/", $routesSrc, $matches, PREG_SET_ORDER) === false) {
    fwrite(STDERR, "Could not parse routes.php\n");
    exit(2);
}

foreach ($matches as $match) {
    $path    = trim($match[2], '/');
    $routes[] = [
        'method'   => strtoupper($match[1]),
        'path'     => $path,
        'segments' => $path === '' ? [] : explode('/', $path),
    ];
}

/**
 * True when the literal in the view is a prefix of, or identical to, a route.
 *
 * Views usually interpolate the identifier: url('harvest/' . $row[...]), so the
 * literal is a segment prefix rather than a whole path.
 */
function matchesLiteral(string $literal, array $route): bool
{
    $literalSegments = $literal === '' ? [] : explode('/', $literal);

    if (count($literalSegments) > count($route['segments'])) {
        return false;
    }

    foreach ($literalSegments as $index => $segment) {
        if ($route['segments'][$index] !== $segment) {
            return false;
        }
    }

    return true;
}

$problems = 0;
$checked  = 0;

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewPath));

foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen((string) realpath($viewPath)) + 1));
    $source   = (string) file_get_contents($file->getPathname());

    // A file that does not compile cannot be analysed meaningfully, and a
    // regex over it would happily report nonsense. Fail loudly instead.
    try {
        token_get_all($source, TOKEN_PARSE);
    } catch (\ParseError $error) {
        printf("%s does not parse: %s\n", $relative, $error->getMessage());
        $problems++;
        continue;
    }

    // CSS also has a url() function, and a background image is not a route.
    // A <style> block or a style attribute is stripped before matching so a
    // stylesheet url() cannot be reported as a dead link.
    $routesSource = (string) preg_replace(
        ['#<style\b[^>]*>.*?</style>#is', '#\sstyle\s*=\s*("[^"]*"|\'[^\']*\')#i'],
        ['', ''],
        $source
    );

    if (preg_match_all("/url\(\s*'([^']*)'/", $routesSource, $calls, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
        continue;
    }

    foreach ($calls as $call) {
        // The literal often carries a query string, which is not part of the
        // route: url('activities?status=overdue') addresses the activities route.
        $literal = trim(explode('?', $call[1][0], 2)[0], '/');
        $offset  = $call[0][1];
        $checked++;

        $candidates = array_values(array_filter($routes, static fn (array $r): bool => matchesLiteral($literal, $r)));

        if ($candidates === []) {
            printf("%s: url('%s') matches no route\n", $relative, $literal);
            $problems++;
            continue;
        }

        // Work out whether this url() is being used as a GET link or as the
        // action of a POST form, by looking at the nearest enclosing markup.
        // The stripped copy is used for both the offset and the lookbehind so
        // the two stay aligned after a <style> block was removed.
        $before  = substr($routesSource, max(0, $offset - 400), min(400, $offset));
        $formPos = strrpos($before, '<form');
        $anchorPos = strrpos($before, '<a ');

        $expectsPost = $formPos !== false
            && ($anchorPos === false || $formPos > $anchorPos)
            && preg_match('/<form[^>]*method\s*=\s*["\']post["\']/i', $before) === 1;

        if (!$expectsPost) {
            continue;
        }

        $postable = array_filter($candidates, static fn (array $r): bool => in_array($r['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true));

        if ($postable === []) {
            printf(
                "%s: form posts to url('%s') but only %s route(s) exist there\n",
                $relative,
                $literal,
                implode(', ', array_unique(array_column($candidates, 'method')))
            );
            $problems++;
        }
    }
}

printf(
    "%s\nChecked %d url() call(s) across views, %d problem(s).\n",
    $problems === 0 ? 'Every view link resolves to a route.' : 'Link problems found.',
    $checked,
    $problems
);

exit($problems === 0 ? 0 : 1);