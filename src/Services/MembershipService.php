<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;

/**
 * بررسی عضویت اجباری در کانال‌ها و تکمیل شرایط ورود
 *
 * نکته مهم: خطای API یا نبود دسترسی ربات به کانال هرگز به‌عنوان «عدم عضویت»
 * تفسیر نمی‌شود؛ در این حالت ورود کاربر مسدود نمی‌گردد و خطا به مدیر گزارش می‌شود.
 */
final class MembershipService
{
    public function __construct(private ?Database $db = null)
    {
        $this->db ??= Database::instance();
    }

    /** @return array<int,array> */
    public function activeChannels(): array
    {
        if (!$this->db->tableExists('telegram_channels')) {
            return [];
        }
        return $this->db->all('SELECT * FROM telegram_channels WHERE is_active = 1 ORDER BY sort_order, id');
    }

    public function enforcementEnabled(): bool
    {
        return SettingsService::bool('force_channel_membership', false);
    }

    public function contactRequired(): bool
    {
        return SettingsService::bool('contact_required', false);
    }

    /**
     * @return array{passed:bool,reason:?string,channels:array,errors:array}
     */
    public function gate(array $user): array
    {
        // ۱) شماره تماس اجباری
        if ($this->contactRequired() && empty($user['phone'])) {
            return ['passed' => false, 'reason' => 'contact', 'channels' => [], 'errors' => []];
        }

        // ۲) عضویت در کانال‌های اجباری
        if (!$this->enforcementEnabled()) {
            return ['passed' => true, 'reason' => null, 'channels' => [], 'errors' => []];
        }
        $channels = $this->activeChannels();
        if ($channels === []) {
            return ['passed' => true, 'reason' => null, 'channels' => [], 'errors' => []];
        }

        $tg = new TelegramService(null, $this->db);
        if (!$tg->isConfigured()) {
            return ['passed' => true, 'reason' => null, 'channels' => [], 'errors' => []];
        }

        $notJoined = [];
        $apiErrors = [];
        foreach ($channels as $channel) {
            $check = $tg->checkMembership($channel, (int) $user['telegram_id']);
            if (!$check['ok']) {
                // خطای فنی/دسترسی — هرگز «عدم عضویت» تلقی نمی‌شود و کاربر را مسدود نمی‌کند
                $apiErrors[] = $channel['title'] . ': ' . ($check['error'] ?? 'خطای نامشخص');
                continue;
            }
            if ($check['member'] === false) {
                $notJoined[] = $channel;
            }
        }

        if ($apiErrors) {
            SecurityService::log('membership_api_error', 'low', ['errors' => $apiErrors], (int) $user['id']);
            // آخرین خطای بررسی برای نمایش در پنل مدیریت
            SettingsService::set('membership_last_error', mb_substr(implode(' | ', $apiErrors), 0, 900));
            SettingsService::set('membership_last_error_at', now());
        }

        if ($notJoined === []) {
            // فقط وقتی همهٔ کانال‌ها با موفقیت بررسی شدند «تأییدشده» علامت می‌خورد
            if ($apiErrors === [] && empty($user['channels_verified'])) {
                $this->db->update('users', ['channels_verified' => 1, 'updated_at' => now()], 'id = :id', ['id' => (int) $user['id']]);
            }
            return ['passed' => true, 'reason' => null, 'channels' => [], 'errors' => $apiErrors];
        }

        return ['passed' => false, 'reason' => 'channels', 'channels' => $notJoined, 'errors' => $apiErrors];
    }

    /**
     * بررسی سلامت دسترسی ربات به کانال‌ها (برای پنل مدیریت)
     * @return array<int,array{id:int,title:string,ok:bool,message:string}>
     */
    public function health(): array
    {
        $tg = new TelegramService(null, $this->db);
        $out = [];
        foreach ($this->activeChannels() as $channel) {
            if (!$tg->isConfigured()) {
                $out[] = ['id' => (int) $channel['id'], 'title' => $channel['title'], 'ok' => false, 'message' => 'توکن ربات تنظیم نشده است.'];
                continue;
            }
            $response = $tg->call('getChat', ['chat_id' => $channel['chat_id']]);
            if (!($response['ok'] ?? false)) {
                $out[] = [
                    'id' => (int) $channel['id'],
                    'title' => $channel['title'],
                    'ok' => false,
                    'message' => (string) ($response['description'] ?? 'دسترسی به کانال برقرار نشد'),
                ];
                continue;
            }
            $access = $tg->checkBotAccess($channel);
            $out[] = [
                'id' => (int) $channel['id'],
                'title' => $channel['title'],
                'ok' => $access['admin'],
                'message' => $access['message'],
            ];
        }
        return $out;
    }
}
