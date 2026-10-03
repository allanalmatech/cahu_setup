<?php
declare(strict_types=1);

/**
 * Static check of the view layer for the classes of failure that php -l cannot
 * see, because they only surface once a page is requested:
 *
 *   1. A template that is rendered but does not exist on disk.
 *   2. A call to a function that does not exist.
 *   3. A controller calling a render method that does not exist.
 *
 * The tokeniser is used rather than a regular expression so that inline HTML,
 * JavaScript and comments cannot produce false matches.
 *
 * Usage: php tools/check-views.php
 */

/*
 * Command line only, and for the same reason as check-routes.php: the output
 * names every template, every helper it calls and every controller action, which
 * is a map of the application. tools/.htaccess refuses the directory on Apache;
 * this guard covers the servers that ignore it.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This check may only be run from the command line.\n");
}

require __DIR__ . '/../app/Support/helpers.php';
// Core\Controller has no parent and no dependencies, so it is safe to load
// directly. The autoloader is not booted here.
require_once __DIR__ . '/../app/Core/Controller.php';

$viewPath       = __DIR__ . '/../app/Views';
$controllerPath = __DIR__ . '/../app/Http/Controllers';

/** Names that appear in call position but are language constructs, not calls. */
const CONSTRUCTS = [
    'if', 'elseif', 'else', 'while', 'for', 'foreach', 'switch', 'break', 'continue', 'goto',
    'declare', 'enddeclare', 'return', 'echo', 'print', 'exit', 'die', 'isset', 'unset', 'empty',
    'list', 'array', 'eval', 'include', 'include_once', 'require', 'require_once', 'clone', 'yield',
    'match', 'fn', 'catch', 'try', 'finally', 'throw', 'new', 'and', 'or', 'xor', 'instanceof',
    'static', 'function', 'use', 'namespace', 'global', 'const', 'class', 'endif', 'endforeach',
    'endwhile', 'endfor', 'endswitch', 'as', 'do', 'insteadof',
];

/**
 * Tokens that, when immediately before a name, mean the name is not a call.
 *
 * T_NS_SEPARATOR is included so that a namespaced call such as \App\thing() is
 * not resolved against the global helper table: the autoloader is not booted
 * here, so such a call cannot be checked reliably. Views do not use them.
 */
const NON_CALLS = [
    T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW,
    T_CLASS, T_CONST, T_NS_SEPARATOR, T_ATTRIBUTE,
];

/** Tokens that carry no meaning for adjacency purposes. */
const NOISE = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

/**
 * Path of a file relative to a root directory, always with forward slashes.
 *
 * realpath() is applied to both sides: the iterator reports whatever casing the
 * filesystem hands back, which does not always match the string the root was
 * given, and a mismatched prefix silently truncates the name.
 */
function relativePath(string $file, string $root): string
{
    $base = (string) realpath($root);
    $path = (string) realpath($file);

    return str_replace('\\', '/', str_starts_with($path, $base) ? substr($path, strlen($base) + 1) : $path);
}

/**
 * Maps a short class name to the file that declares it, across the whole app.
 *
 * Classes are matched by short name because that is what an `extends` clause
 * in this codebase uses: `use Core\Controller;` then `extends Controller`.
 * Loading the parent file directly avoids needing the autoloader, which is not
 * booted for a command line check.
 *
 * @return array<string,string>
 */
function classIndex(string $appPath): array
{
    static $index = null;

    if ($index !== null) {
        return $index;
    }

    $index = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath));

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/mi', $source, $matches)) {
            $index[$matches[1]] ??= (string) $file->getPathname();
        }
    }

    return $index;
}

/**
 * Every method a class can call on itself: those it declares, plus those of each
 * class it extends.
 *
 * The chain is walked rather than only reading the current file, because the
 * API controllers extend ApiController, which in turn extends Controller. A
 * check that only looked at one file would flag every inherited helper.
 *
 * @param array<string,string> $index
 * @return array<int,string>
 */
function availableMethods(string $file, array $index): array
{
    $methods = [];
    $visited = [];
    $current = $file;

    while ($current !== '' && !isset($visited[$current])) {
        $visited[$current] = true;

        $source = (string) file_get_contents($current);

        if (preg_match_all('/function\s+(\w+)\s*\(/i', $source, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $methods[] = $name;
            }
        }

        if (preg_match('/\bextends\s+([A-Za-z_][A-Za-z0-9_]*)/', $source, $matches) !== 1) {
            break;
        }

        $current = $index[$matches[1]] ?? '';
    }

    return $methods;
}

$missing = 0;
$seen    = 0;

/**
 * Reports calls to a render method on $this that the base Controller does not
 * provide and the controller does not define itself.
 *
 * The base class exposes render(); an earlier draft of this project called
 * view(), which no controller defines. That is a fatal error on the first
 * request to the affected screen, and no syntax check sees it, because
 * $this->view(...) is well-formed PHP.
 *
 * @return array{0:int,1:int} [calls seen, problems found]
 */
function inspectControllers(string $appPath, string $controllerPath, int &$missing): array
{
    if (!is_dir($controllerPath)) {
        return [0, 0];
    }

    $classMap = classIndex($appPath);
    $seen     = 0;
    $problems = 0;

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($controllerPath));

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = relativePath($file->getPathname(), $controllerPath);
        $source   = (string) file_get_contents($file->getPathname());

        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $error) {
            printf("%s does not parse: %s\n", $relative, $error->getMessage());
            $missing++;
            $problems++;

            continue;
        }

        $methods = availableMethods($file->getPathname(), $classMap);

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$this') {
                continue;
            }

            $operator = $tokens[$index + 1] ?? null;

            if (!is_array($operator) || $operator[0] !== T_OBJECT_OPERATOR) {
                continue;
            }

            $name = $tokens[$index + 2] ?? null;

            if (!is_array($name) || $name[0] !== T_STRING) {
                continue;
            }

            $next = $tokens[$index + 3] ?? null;

            if ($next !== '(') {
                continue;
            }

            $seen++;

            if (in_array($name[1], $methods, true)) {
                continue;
            }

            printf("%s calls undefined Controller method \$this->%s()\n", $relative, $name[1]);
            $missing++;
            $problems++;
        }
    }

    return [$seen, $problems];
}

/**
 * Walks one template and reports problems found in its PHP sections.
 *
 * @return array{0:int,1:int} [template references seen, problems found]
 */
function inspectView(string $source, string $viewPath, string $relative, int &$missing, int &$seen): array
{
    // TOKEN_PARSE makes the tokeniser reject invalid syntax instead of
    // recovering from it, so a file that does not compile is reported rather
    // than silently producing meaningless tokens.
    try {
        $tokens = token_get_all($source, TOKEN_PARSE);
    } catch (\ParseError $error) {
        printf("%s does not parse: %s\n", $relative, $error->getMessage());
        $missing++;

        return [$seen, 1];
    }

    $count = count($tokens);
    $inPhp     = false;
    $problems  = 0;

    // Index of the previous token that was not whitespace or a comment.
    $previousSignificant = null;

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (is_array($token)) {
            if ($token[0] === T_OPEN_TAG) {
                $inPhp = true;
                continue;
            }

            if ($token[0] === T_CLOSE_TAG) {
                $inPhp = false;
                continue;
            }

            if (!$inPhp || in_array($token[0], NOISE, true)) {
                continue;
            }

            // Only a bare name immediately followed by "(" can be a call.
            if ($token[0] !== T_STRING) {
                continue;
            }

            $name = strtolower($token[1]);

            if (in_array($name, CONSTRUCTS, true)) {
                continue;
            }

            // Skip whitespace to find the opening parenthesis.
            $cursor = $i + 1;
            while (is_array($tokens[$cursor] ?? null) && $tokens[$cursor][0] === T_WHITESPACE) {
                $cursor++;
            }

            if (($tokens[$cursor] ?? null) !== '(') {
                continue;
            }

            // The token immediately before the name, ignoring whitespace and
            // comments. This is read backwards from $i because $token is the
            // name itself.
            $scanBack = $i - 1;
            while (is_array($tokens[$scanBack] ?? null) && in_array($tokens[$scanBack][0], NOISE, true)) {
                $scanBack--;
            }

            $before = $tokens[$scanBack] ?? null;

            // A name being declared is not a call.
            if (is_array($before) && in_array($before[0], [T_FUNCTION, T_FN], true)) {
                continue;
            }

            $afterObjectOperator = is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);

            // render() and partial() name a template, whether called on the View
            // instance ($view->partial('x')) or globally (render('x')).
            if (in_array($name, ['render', 'partial'], true)) {
                $isStaticCall = is_array($before) && $before[0] === T_DOUBLE_COLON;

                if (!$isStaticCall) {
                    $argument = $tokens[$cursor + 1] ?? null;

                    if (is_array($argument) && $argument[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $target = trim($argument[1], "'\"");
                        $seen++;

                        if (!file_exists($viewPath . '/' . $target . '.php')) {
                            printf("%s references missing template '%s'\n", $relative, $target);
                            $missing++;
                        }

                        continue;
                    }
                }
            }

            // $view->partial(...) and Foo::bar(...) are methods, not functions.
            if ($afterObjectOperator) {
                continue;
            }

            if (is_array($before) && in_array($before[0], NON_CALLS, true)) {
                continue;
            }

            if (!function_exists($name)) {
                printf("%s calls undefined function '%s()'\n", $relative, $token[1]);
                $missing++;
                $problems++;
            }
        }
    }

    return [$seen, $problems];
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewPath));

foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $relative = relativePath($file->getPathname(), $viewPath);

    inspectView(
        (string) file_get_contents($file->getPathname()),
        $viewPath,
        $relative,
        $missing,
        $seen
    );
}

[$controllerCalls] = inspectControllers(dirname($viewPath), $controllerPath, $missing);

printf(
    "%s\nChecked %d template reference(s), %d Controller call(s), %d problem(s).\n",
    $missing === 0 ? 'All templates and helpers resolve.' : 'View problems found.',
    $seen,
    $controllerCalls,
    $missing
);

exit($missing === 0 ? 0 : 1);