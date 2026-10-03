<?php
declare(strict_types=1);

namespace Core;

/**
 * Secure upload handler. User supplied filenames are never reused, the MIME
 * type is verified against the file contents, and files are written outside
 * the web-servable application directory where possible.
 */
final class Uploader
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private const DOC_MIMES   = ['application/pdf', 'image/jpeg', 'image/png'];
    private const VIDEO_MIMES = ['video/mp4'];

    private string $basePath;
    private string $urlPrefix;

    public function __construct(string $basePath, string $urlPrefix)
    {
        $this->basePath  = rtrim($basePath, '/\\');
        $this->urlPrefix = '/' . trim($urlPrefix, '/');
    }

    /**
     * @return array{success:bool,path?:string,error?:string}
     */
    public function image(string $field, string $folder, int $maxKb = 4096): array
    {
        return $this->store($field, $folder, self::IMAGE_MIMES, $maxKb);
    }

    /**
     * @return array{success:bool,path?:string,error?:string}
     */
    public function document(string $field, string $folder, int $maxKb = 10240): array
    {
        return $this->store($field, $folder, self::DOC_MIMES, $maxKb);
    }

    /**
     * @return array{success:bool,path?:string,error?:string}
     */
    public function video(string $field, string $folder, int $maxKb = 102400): array
    {
        return $this->store($field, $folder, self::VIDEO_MIMES, $maxKb);
    }

    /**
     * @param array<int,string> $allowedMimes
     * @return array{success:bool,path?:string,error?:string}
     */
    private function store(string $field, string $folder, array $allowedMimes, int $maxKb): array
    {
        $file = $_FILES[$field] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['success' => false, 'error' => 'No file was uploaded.'];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => $this->uploadErrorMessage((int) $file['error'])];
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'error' => 'The uploaded file could not be verified.'];
        }

        if ((int) $file['size'] > $maxKb * 1024) {
            return ['success' => false, 'error' => 'The file is larger than the ' . $maxKb . ' KB limit.'];
        }

        $mime = $this->detectMime((string) $file['tmp_name']);

        if (!in_array($mime, $allowedMimes, true)) {
            return ['success' => false, 'error' => 'Unsupported file type (' . $mime . ').'];
        }

        $extension = $this->extensionForMime($mime);
        $directory = $this->basePath . '/' . trim($folder, '/');

        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            return ['success' => false, 'error' => 'The upload folder is not writable.'];
        }

        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $path = $directory . '/' . $name;

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            return ['success' => false, 'error' => 'The file could not be stored.'];
        }

        @chmod($path, 0640);

        return ['success' => true, 'path' => $this->urlPrefix . '/' . trim($folder, '/') . '/' . $name];
    }

    private function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $path);
            finfo_close($finfo);

            if (is_string($mime)) {
                return $mime;
            }
        }

        $info = @getimagesize($path);

        return $info['mime'] ?? 'application/octet-stream';
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/gif'       => 'gif',
            'image/webp'      => 'webp',
            'application/pdf' => 'pdf',
            'video/mp4'       => 'mp4',
            default           => 'bin',
        };
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            UPLOAD_ERR_PARTIAL                        => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'The server is missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE                     => 'The file could not be written to disk.',
            UPLOAD_ERR_EXTENSION                      => 'The upload was blocked by the server.',
            default                                   => 'The file could not be uploaded.',
        };
    }
}
