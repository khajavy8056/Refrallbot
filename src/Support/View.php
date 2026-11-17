<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * رندر قالب‌های PHP با Layout
 */
final class View
{
    private static array $globals = [];

    public static function share(string $key, mixed $value): void
    {
        self::$globals[$key] = $value;
    }

    public static function render(string $view, array $data = [], string $layout = 'app'): string
    {
        $data = array_merge(self::$globals, $data);
        $file = RB_VIEWS . '/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $view);
        }
        $content = (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        })($file, $data);

        if ($layout === '' || $layout === 'none') {
            return $content;
        }
        $layoutFile = RB_VIEWS . '/layouts/' . $layout . '.php';
        if (!is_file($layoutFile)) {
            return $content;
        }
        return (static function (string $__layout, array $__data, string $__content): string {
            extract($__data, EXTR_SKIP);
            $content = $__content;
            ob_start();
            require $__layout;
            return (string) ob_get_clean();
        })($layoutFile, $data, $content);
    }

    public static function partial(string $view, array $data = []): void
    {
        echo self::render($view, $data, 'none');
    }
}
