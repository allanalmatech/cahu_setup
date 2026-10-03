<?php
declare(strict_types=1);

namespace Core;

/**
 * Response helper. Controllers return a Response (or a string/array which the
 * App converts) and the App writes headers plus the body.
 */
final class Response
{
    private string $body;
    private int $status;
    /** @var array<string,string> */
    private array $headers;

    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body    = $body;
        $this->status  = $status;
        $this->headers = $headers;
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * @param array<string,mixed>|array<int,mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function error(string $message, int $status = 400, array $extra = []): self
    {
        return self::json(array_merge(['success' => false, 'message' => $message], $extra), $status);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public static function download(string $content, string $filename, string $contentType = 'text/csv'): self
    {
        return new self($content, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $filename) . '"',
            'Content-Length'      => (string) strlen($content),
        ]);
    }

    public static function notFound(string $message = 'The requested resource was not found.'): self
    {
        return new self($message, 404);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }
}
