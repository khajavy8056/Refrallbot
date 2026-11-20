<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;
use RB\Support\Request;

/**
 * شناسایی فعالیت مشکوک و ثبت رویدادهای امنیتی
 * سیستم هرگز صرفاً بر اساس یک نشانه ضعیف، درآمد کاربر را حذف نمی‌کند؛
 * فقط «امتیاز ریسک» و رویداد برای بررسی مدیر ثبت می‌شود.
 */
final class SecurityService
{
    public static function log(string $type, string $severity, array $details = [], ?int $userId = null): int
    {
        $db = Database::instance();
        $id = $db->insert('security_events', [
            'user_id' => $userId,
            'type' => $type,
            'severity' => $severity,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'status' => 'open',
            'created_at' => now(),
        ]);

        if ($userId !== null) {
            $weight = match ($severity) {
                'high' => 30,
                'medium' => 15,
                default => 5,
            };
            $db->run('UPDATE users SET risk_score = risk_score + :w WHERE id = :id', ['w' => $weight, 'id' => $userId]);
        }
        return $id;
    }

    /**
     * ارزیابی ریسک کاربر پس از ثبت‌نام یا فعالیت
     * نشانه‌ها: چند حساب با IP یکسان، نام‌های تکراری، زنجیره غیرعادی، سرعت ثبت‌نام
     */
    public static function evaluateUser(int $userId): void
    {
        $db = Database::instance();
        $user = $db->first('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        if ($user === null) {
            return;
        }

        $flags = [];

        // ۱) چند حساب با IP یکسان
        if (!empty($user['signup_ip']) && $user['signup_ip'] !== '0.0.0.0') {
            $sameIp = (int) $db->value(
                'SELECT COUNT(*) FROM users WHERE signup_ip = :ip AND id <> :id',
                ['ip' => $user['signup_ip'], 'id' => $userId]
            );
            $threshold = (int) SettingsService::get('risk_same_ip_threshold', '3');
            if ($sameIp >= $threshold) {
                $flags[] = ['type' => 'multiple_accounts_same_ip', 'severity' => 'medium', 'count' => $sameIp];
            }
        }

        // ۲) ثبت‌نام پرتعداد در بازه کوتاه
        $recent = (int) $db->value(
            'SELECT COUNT(*) FROM users WHERE joined_at >= :t',
            ['t' => date('Y-m-d H:i:s', time() - 600)]
        );
        $burst = (int) SettingsService::get('risk_signup_burst', '10');
        if ($recent >= $burst) {
            $flags[] = ['type' => 'signup_burst', 'severity' => 'low', 'count' => $recent];
        }

        // ۳) زنجیره غیرعادی — معرف با تعداد زیرمجموعه بسیار بالا در زمان کوتاه
        if (!empty($user['referred_by'])) {
            $rapid = (int) $db->value(
                'SELECT COUNT(*) FROM users WHERE referred_by = :r AND joined_at >= :t',
                ['r' => (int) $user['referred_by'], 't' => date('Y-m-d H:i:s', time() - 3600)]
            );
            $burstRef = (int) SettingsService::get('risk_referral_burst', '15');
            if ($rapid >= $burstRef) {
                $flags[] = ['type' => 'referral_burst', 'severity' => 'medium', 'count' => $rapid, 'referrer_id' => (int) $user['referred_by']];
            }
        }

        // ۴) اطلاعات پروفایل تکراری (نام خالی / بدون username و با نام تکراری)
        if (empty($user['username']) && empty($user['first_name'])) {
            $flags[] = ['type' => 'incomplete_profile', 'severity' => 'low'];
        }

        // ۵) نام تکراری با کاربر دیگر
        if (!empty($user['first_name'])) {
            $dupName = (int) $db->value(
                'SELECT COUNT(*) FROM users WHERE first_name = :n AND id <> :id AND joined_at >= :t',
                ['n' => $user['first_name'], 'id' => $userId, 't' => date('Y-m-d H:i:s', time() - 86400)]
            );
            if ($dupName >= 3) {
                $flags[] = ['type' => 'duplicate_names', 'severity' => 'low', 'count' => $dupName];
            }
        }

        foreach ($flags as $flag) {
            $exists = $db->value(
                "SELECT id FROM security_events WHERE user_id = :u AND type = :t AND status = 'open' AND created_at >= :t2",
                ['u' => $userId, 't' => $flag['type'], 't2' => date('Y-m-d H:i:s', time() - 86400)]
            );
            if ($exists) {
                continue;
            }
            self::log($flag['type'], $flag['severity'], array_merge($flag, ['auto' => true]), $userId);
        }

        $riskScore = (int) $db->value('SELECT risk_score FROM users WHERE id = :id', ['id' => $userId]);
        $markThreshold = (int) SettingsService::get('risk_mark_threshold', '45');
        if ($riskScore >= $markThreshold && $user['status'] === 'active') {
            // فقط علامت‌گذاری برای بررسی مدیر — نه مسدودسازی خودکار
            $db->update('users', ['status' => 'suspicious', 'updated_at' => now()], 'id = :id', ['id' => $userId]);
            self::log('auto_flagged', 'high', ['risk_score' => $riskScore], $userId);
        }
    }

    /** بررسی شماره کارت تکراری میان کاربران */
    public static function checkCardReuse(string $cardHash, int $userId): void
    {
        $db = Database::instance();
        $count = (int) $db->value(
            "SELECT COUNT(DISTINCT user_id) FROM withdrawal_requests WHERE card_last4 IS NOT NULL AND meta LIKE :h AND user_id <> :u",
            ['h' => '%"card_hash":"' . $cardHash . '"%', 'u' => $userId]
        );
        if ($count > 0) {
            self::log('card_reuse', 'high', ['other_users' => $count, 'card_hash' => $cardHash], $userId);
        }
    }
}
