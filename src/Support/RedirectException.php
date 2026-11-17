<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * در حالت عادی، redirect() خروجی را می‌فرستد و exit می‌کند.
 * وقتی یک test handler روی Request::redirect() ثبت شده باشد، به‌جای exit این استثنا پرتاب می‌شود
 * تا تست‌ها بتوانند مقصد ریدایرکت را بررسی کنند.
 */
final class RedirectException extends \RuntimeException
{
    public function __construct(public readonly string $target, public readonly int $statusCode = 302)
    {
        parent::__construct('Redirect to ' . $target, $statusCode);
    }
}
