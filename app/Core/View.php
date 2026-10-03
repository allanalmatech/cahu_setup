<?php
declare(strict_types=1);

namespace Core;

/**
 * Renders a plain PHP template inside a layout. Templates receive their data as
 * extracted local variables and have access to $this via the $view helper
 * (e.g. $view->url(...)).
 */
final class View
{
    private string $viewPath;
    private string $layout;
    /** @var array<string,mixed> */
    private array $shared = [];

    public function __construct(string $viewPath, string $layout = 'layouts/app')
    {
        $this->viewPath = $viewPath;
        $this->layout   = $layout;
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = $this->capture($template, array_merge($this->shared, $data));

        if ($layout === null) {
            $layout = $this->layout;
        }

        if ($layout === '') {
            return $content;
        }

        return $this->capture($layout, array_merge($this->shared, $data, ['content' => $content]));
    }

    /**
     * @param array<string,mixed> $data
     */
    public function capture(string $template, array $data = []): string
    {
        $file = $this->viewPath . '/' . str_replace('.', '/', $template) . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }

        // Every template receives the renderer itself, so partials and nested
        // renders can be called without threading a helper through every layer.
        $data['view'] = $this;

        $level = ob_get_level();
        ob_start();

        try {
            (function () use ($file, $data): void {
                extract($data, EXTR_SKIP);
                require $file;
            })->call($this);
        } catch (\Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        }

        return (string) ob_get_clean();
    }

    public function exists(string $template): bool
    {
        return is_file($this->viewPath . '/' . str_replace('.', '/', $template) . '.php');
    }

    /**
     * Includes a partial from inside a template.
     *
     * @param array<string,mixed> $data
     */
    public function partial(string $template, array $data = []): void
    {
        echo $this->capture($template, array_merge($this->shared, $data));
    }
}
