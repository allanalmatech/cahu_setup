<?php
declare(strict_types=1);

/**
 * Static check for unqualified class references.
 *
 * Controllers live in Http\Controllers but reference Core and Services classes
 * constantly. A missing "use" line only fails when that action is actually
 * reached, which is exactly the kind of bug that reaches production, so this
 * check resolves every reference statically instead.
 *
 * Usage: php tools/check-references.php [directory]
 */

/*
 * Command line only, for the same reason as check-routes.php: the output lists
 * the application's class layout. tools/.htaccess refuses the directory on
 * Apache; this guard covers the servers that ignore it.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This check may only be run from the command line.\n");
}

$directory = $argv[1] ?? 'app';

$known = [
    'App', 'Audit', 'Auth', 'Controller', 'Csrf', 'Database', 'Flash', 'Form',
    'HttpException', 'Identifier', 'Logger', 'Mailer', 'Model', 'Notifier',
    'Paginator', 'Request', 'Response', 'Router', 'Setting', 'Sms', 'Uploader',
    'Validator', 'View',
    'CropLifecycle', 'Forecaster', 'InputLedger', 'Lookups', 'Traceability',
];

$pattern = '/(?<![\\\\\w$>:])(' . implode('|', $known) . ')::/';

$problems = 0;
$checked  = 0;

/**
 * Blanks out comments while leaving every other token exactly where it was.
 *
 * The reference pattern is a plain regular expression, so a docblock that names
 * a class - "read through Setting::get()" - looks identical to real code. The
 * replacement is a single space rather than nothing, so a comment sitting
 * between two tokens cannot weld them into a name that was never there.
 */
function withoutComments(string $source): string
{
    $stripped = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            $stripped .= ' ';
            continue;
        }

        $stripped .= is_array($token) ? $token[1] : $token;
    }

    return $stripped;
}

/** Collects the short names brought in by the file's use statements. */
function importsOf(string $source): array
{
    preg_match_all('/^use\s+([^;]+);/m', $source, $matches);

    $names = [];

    foreach ($matches[1] as $imported) {
        $imported = trim($imported);
        $position = strrpos($imported, '\\');
        $names[]  = $position === false ? $imported : substr($imported, $position + 1);
    }

    return array_flip($names);
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path   = $file->getPathname();
    $source = (string) file_get_contents($path);

    // Comments are removed from the whole file before anything is sliced, not
    // after: token_get_all() degrades on a fragment that begins mid-file, and
    // would hand back the rest of the class as plain text.
    $code = withoutComments($source);

    // Only the body matters; the use block is what we compare against.
    $classPosition = strpos($code, "\nclass ") ?: strpos($code, "\nfinal class ") ?: 0;
    $body          = substr($code, $classPosition);
    $imported      = importsOf($source);

    preg_match_all($pattern, $body, $matches);
    $checked++;

    preg_match('/^namespace\s+([^;]+);/m', $source, $namespaceMatch);
    $namespace = $namespaceMatch[1] ?? '';

    // Classes that exist in this codebase, keyed by fully qualified name.
    static $classes = null;

    if ($classes === null) {
        $classes = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('app')) as $candidate) {
            if (!$candidate->isFile() || $candidate->getExtension() !== 'php') {
                continue;
            }

            $candidateSource = (string) file_get_contents($candidate->getPathname());

            if (!preg_match('/^namespace\s+([^;]+);/m', $candidateSource, $found)
                || !preg_match('/^(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', $candidateSource, $name)) {
                continue;
            }

            $classes[$found[1] . '\\' . $name[1]] = true;
        }
    }

    foreach (array_unique($matches[1]) as $class) {
        if (isset($imported[$class])) {
            continue;
        }

        // An unqualified reference resolves against the file's own namespace
        // first, which is why Core classes need no use statement.
        if ($namespace !== '' && isset($classes[$namespace . '\\' . $class])) {
            continue;
        }

        printf("%s: %s:: is used without an import\n", $path, $class);
        $problems++;
    }
}

printf(
    "%s\nChecked %d file(s), %d unresolved reference(s).\n",
    $problems === 0 ? 'All class references resolve.' : 'Unresolved references found.',
    $checked,
    $problems
);

exit($problems === 0 ? 0 : 1);