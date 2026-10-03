<?php
declare(strict_types=1);

namespace Http\Controllers;

use Core\Controller;
use Core\HttpException;
use Core\Response;

/**
 * Serves uploaded files out of storage/.
 *
 * Uploaded files are farmer identity documents, signed consent forms and
 * photographs of their fields, and they are stored outside the document root.
 * Something has to put them back on the wire, and that something is this
 * controller rather than the web server, for two reasons:
 *
 *   1. It works. The document root is public/ and storage/ is refused, so a
 *      static URL under /storage/uploads/ would be a 404 for every file ever
 *      stored, which is what the paths in the database point at.
 *   2. It keeps the files private. A static file is reachable by anyone who
 *      has the URL, forever, with no session and no audit trail. Every stored
 *      path is written into an href in a page, so "has the URL" turns out to
 *      mean "was forwarded a link in a message". Requiring a session costs
 *      nothing for the web UI, where the browser already holds the cookie, and
 *      it means a national ID scan is not a public URL.
 *
 * The route is registered from the configured uploads.url prefix, so moving the
 * uploads moves the route with them.
 */
final class UploadController extends Controller
{
    /**
     * Extensions that may be shown in the browser rather than downloaded.
     *
     * This is a second line of defence, not the first. Anything not on this list
     * is sent as an attachment with a neutral content type, so a file that got
     * past the uploader's own check cannot be rendered as a document in the
     * user's session - an HTML file in an uploads folder is stored XSS, and an
     * SVG is the same problem wearing an image extension.
     *
     * @var array<string,string>
     */
    private const INLINE_TYPES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
    ];

    /**
     * GET /storage/uploads/{path}
     *
     * The path arrives URL-encoded and is decoded exactly once here. Decoding
     * later - in the filesystem call - is what turns a traversal into a real
     * one, so the decode and the checks belong in the same place.
     */
    public function serve(string $path): Response
    {
        $name = $this->safeName($path);

        $root = $this->uploadRoot();
        $file = $root . '/' . $name;

        /*
         * realpath() collapses "..", resolves symlinks, and returns false for a
         * file that does not exist. Every one of those is needed, and the prefix
         * comparison catches the case where the resolved path lands outside the
         * uploads directory anyway - through a symlink, or a name that decodes
         * to something unexpected on this platform.
         */
        $real = realpath($file);

        if ($real === false || !is_file($real)) {
            throw new HttpException(404, 'The requested file does not exist.');
        }

        $realRoot = realpath($root);

        if ($realRoot === false || !str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) {
            throw new HttpException(404, 'The requested file does not exist.');
        }

        // A 404 rather than a 403 for a path that escapes the uploads directory,
        // so the response does not confirm that a file exists somewhere else.
        $contents = @file_get_contents($real);

        if ($contents === false) {
            throw new HttpException(404, 'The requested file could not be read.');
        }

        $extension = strtolower((string) pathinfo($real, PATHINFO_EXTENSION));
        $inline    = self::INLINE_TYPES[$extension] ?? null;
        $filename  = basename($real);

        $response = new Response($contents, 200, [
            'Content-Type'        => $inline ?? 'application/octet-stream',
            'Content-Length'      => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
            // The browser must not treat the response as part of the app's
            // origin for script purposes.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
        ]);

        if ($inline === null) {
            $response = $response->withHeader('Content-Disposition', 'attachment; filename="' . $this->headerSafe($filename) . '"');
        } else {
            $response = $response->withHeader('Content-Disposition', 'inline; filename="' . $this->headerSafe($filename) . '"');
        }

        // A private response: these are documents, so they must not be kept by a
        // shared proxy or written to a browser cache that the next user of the
        // handset can read.
        $response = $response->withHeader('Cache-Control', 'private, max-age=0, no-store');

        return $response;
    }

    /**
     * Turns a request path into a relative path that is safe to join.
     *
     * Rejected outright: a null byte (truncates the path inside a C call), a
     * backslash (a separator on Windows, so a "../.." bypass written with
     * forward slashes still works on a Windows host), an absolute path, and any
     * "." or ".." segment.
     *
     * The decode happens once, here, and the result is then checked segment by
     * segment - which is stronger than a single str_contains('..') test, because
     * a check on the raw string can be defeated by the encoding rather than by
     * the path.
     */
    private function safeName(string $path): string
    {
        $decoded = rawurldecode($path);

        if (str_contains($decoded, "\0") || str_contains($decoded, '\\')) {
            throw new HttpException(404, 'The requested file does not exist.');
        }

        // Tested before the leading slash is stripped. Stripping first and then
        // testing for a leading slash can never be true, which would leave
        // "/etc/passwd" quietly rewritten to "etc/passwd" and carried on with -
        // it is caught later by the realpath containment check, but silently
        // mangling an absolute path is not something to leave to a later layer.
        if ($decoded === '' || str_starts_with($decoded, '/')) {
            throw new HttpException(404, 'The requested file does not exist.');
        }

        $segments = [];

        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new HttpException(404, 'The requested file does not exist.');
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * The configured uploads directory, with the same default config.php applies
     * when UPLOAD_PATH is blank.
     */
    private function uploadRoot(): string
    {
        $path = (string) $this->config('uploads.path', '');

        if ($path === '') {
            throw new HttpException(404, 'Uploads are not configured.');
        }

        return rtrim($path, '/');
    }

    /**
     * Strips a filename down to something that cannot break out of the
     * Content-Disposition header.
     */
    private function headerSafe(string $filename): string
    {
        return str_replace(['"', "\r", "\n", '\\'], '', $filename);
    }
}
