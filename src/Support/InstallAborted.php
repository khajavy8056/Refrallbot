<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * در حالت تست، به‌جای exit() در کنترلر نصب پرتاب می‌شود تا فرآیند تست
 * متوقف نشود و بتوان کد وضعیت و بدنهٔ پاسخ را بررسی کرد.
 */
final class InstallAborted extends \RuntimeException
{
    public function __construct(private array $payload, private int $statusCode)
    {
        parent::__construct((string) ($payload['error'] ?? 'aborted'), $statusCode);
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
