<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;
use RB\Support\ErrorHandler;

/**
 * ضدتکرار آپدیت‌های تلگرام.
 *
 * مسئله: تلگرام اگر پاسخ 200 نگیرد همان آپدیت را دوباره می‌فرستد. بدون
 * ثبت update_id، یک دکمهٔ «ثبت درخواست برداشت» می‌تواند دو بار اجرا شود.
 *
 * طراحی:
 *   ۱) begin()  → درج اتمیک update_id با وضعیت processing. اگر UNIQUE
 *                 بشکند یعنی آپدیت تکراری/هم‌زمان است و پردازش نمی‌شود.
 *   ۲) done()   → ثبت موفقیت.
 *   ۳) fail()   → ثبت خطا و آزاد کردن قفل تا تلاش مجدد تلگرام ممکن باشد.
 *   ۴) reclaim()→ آپدیت‌هایی که در حین پردازش کرش کرده‌اند (قفل منقضی) دوباره
 *                 قابل پردازش می‌شوند.
 *
 * نکتهٔ مهم دربارهٔ پردازش موازی: درج با UNIQUE اتمیک است؛ دو درخواست
 * هم‌زمان یکی موفق و دیگری DuplicateKey می‌گیرد. پس بدون قفل خارجی هم
 * پردازش دوباره رخ نمی‌دهد.
 */
final class UpdateDeduplicator
{
    /** مدت اعتبار قفل پردازش (ثانیه) — بعد از آن آپدیت گیرکرده آزاد می‌شود */
    public const LOCK_TTL = 180;

    /** نگهداری رکوردهای پردازش‌شده (روز) */
    public const RETENTION_DAYS = 14;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * شروع پردازش یک آپدیت.
     *
     * @return array{proceed:bool,reason:string,id:int}
     *   proceed=false یعنی این آپدیت قبلاً پردازش شده یا همین حالا در حال
     *   پردازش است و باید نادیده گرفته شود.
     */
    public function begin(int $updateId, array $update = []): array
    {
        if ($updateId <= 0) {
            // آپدیت بدون update_id (مثلاً فراخوانی دستی/تست) —
            // بدون dedupe پردازش می‌شود تا رفتار قبلی حفظ شود.
            return ['proceed' => true, 'reason' => 'no_update_id', 'id' => 0];
        }

        $type = 'unknown';
        foreach (['message', 'callback_query', 'edited_message', 'inline_query', 'chat_member', 'my_chat_member'] as $key) {
            if (isset($update[$key])) {
                $type = $key;
                break;
            }
        }
        $telegramUserId = (int) (
            $update['message']['from']['id']
            ?? $update['callback_query']['from']['id']
            ?? $update['edited_message']['from']['id']
            ?? 0
        );

        // ---- تلاش برای درج اتمیک
        try {
            $id = $this->db->insert('telegram_updates', [
                'update_id' => $updateId,
                'update_type' => $type,
                'telegram_user_id' => $telegramUserId > 0 ? $telegramUserId : null,
                'status' => 'processing',
                'attempts' => 1,
                'locked_until' => date('Y-m-d H:i:s', time() + self::LOCK_TTL),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return ['proceed' => true, 'reason' => 'new', 'id' => $id];
        } catch (\Throwable $e) {
            // Duplicate → این update_id قبلاً دیده شده
            $existing = $this->db->first(
                'SELECT * FROM telegram_updates WHERE update_id = :u LIMIT 1',
                ['u' => $updateId]
            );
            if ($existing === null) {
                // خطای واقعی دیتابیس — به‌جای گم کردن آپدیت، پردازش را ادامه بده
                ErrorHandler::write('warning', 'dedupe insert failed: ' . $e->getMessage());
                return ['proceed' => true, 'reason' => 'dedupe_unavailable', 'id' => 0];
            }

            $status = (string) $existing['status'];
            $lockedUntil = (string) ($existing['locked_until'] ?? '');

            if ($status === 'done') {
                return ['proceed' => false, 'reason' => 'already_done', 'id' => (int) $existing['id']];
            }
            if ($status === 'processing' && $lockedUntil !== '' && strtotime($lockedUntil) > time()) {
                return ['proceed' => false, 'reason' => 'in_progress', 'id' => (int) $existing['id']];
            }

            // failed یا processing با قفل منقضی → تلاش مجدد مجاز است
            $this->db->update('telegram_updates', [
                'status' => 'processing',
                'attempts' => (int) $existing['attempts'] + 1,
                'locked_until' => date('Y-m-d H:i:s', time() + self::LOCK_TTL),
                'updated_at' => now(),
            ], 'id = :id', ['id' => (int) $existing['id']]);

            return ['proceed' => true, 'reason' => 'retry', 'id' => (int) $existing['id']];
        }
    }

    /** ثبت موفقیت */
    public function done(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        try {
            $this->db->update('telegram_updates', [
                'status' => 'done',
                'locked_until' => null,
                'processed_at' => now(),
                'updated_at' => now(),
            ], 'id = :id', ['id' => $id]);
        } catch (\Throwable $e) {
            ErrorHandler::write('warning', 'dedupe done failed: ' . $e->getMessage());
        }
    }

    /**
     * ثبت شکست.
     *
     * قفل آزاد می‌شود تا اگر تلگرام همان آپدیت را دوباره فرستاد، دوباره
     * پردازش شود (سیاست: «از دست نرفتن آپدیت» مهم‌تر از «اجرای دوباره» است،
     * چون عملیات مالی خودشان idempotency_key دارند).
     */
    public function fail(int $id, string $message): void
    {
        if ($id <= 0) {
            return;
        }
        try {
            $this->db->update('telegram_updates', [
                'status' => 'failed',
                'locked_until' => null,
                'last_error' => mb_substr(ErrorHandler::redact($message), 0, 250),
                'updated_at' => now(),
            ], 'id = :id', ['id' => $id]);
        } catch (\Throwable $e) {
            ErrorHandler::write('warning', 'dedupe fail failed: ' . $e->getMessage());
        }
    }

    /** پاک‌سازی رکوردهای قدیمی (از CronService) */
    public function prune(): int
    {
        try {
            $cutoff = date('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400);
            return $this->db->delete(
                'telegram_updates',
                'created_at < :c AND status <> :p',
                ['c' => $cutoff, 'p' => 'processing']
            );
        } catch (\Throwable $e) {
            ErrorHandler::write('warning', 'dedupe prune failed: ' . $e->getMessage());
            return 0;
        }
    }
}
