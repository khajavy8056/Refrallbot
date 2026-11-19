<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Support\Database;

/**
 * بررسی هوشمند عضویت اجباری در کانال‌ها و شرایط ورود در تمام پیام‌رسان‌ها
 *
 * رفتار دقیق مطابق مستندات رسمی هر پیام‌رسان:
 *   - تلگرام و بله : بررسی خودکار عضویت با متد رسمی getChatMember (ربات باید مدیر کانال باشد).
 *   - روبیکا و ایتا: به دلیل نبود متد getChatMember در API رسمی، لینک کانال‌ها به‌صورت تمیز
 *     نمایش داده شده و کاربر با کلیک روی «عضو شدم / تأیید» بدون خطا عبور می‌کند.
 *   - خطای فنی API یا عدم دسترسی ربات هرگز به عنوان «عدم عضویت» تفسیر نمی‌شود.
 */
final class MembershipService
{
    public function __construct(private ?Database $db = null)
    {
        $this->db ??= Database::instance();
    }

    /**
     * کانال‌های فعال (در صورت دادن نام پیام‌رسان، فقط کانال‌های مخصوص آن پیام‌رسان برگردانده می‌شوند)
     * @return array<int,array>
     */
    public function activeChannels(?string $platform = null): array
    {
        if (!$this->db->tableExists('telegram_channels')) {
            return [];
        }
        if ($platform === null || $platform === '' || !$this->db->columnExists('telegram_channels', 'platform')) {
            return $this->db->all('SELECT * FROM telegram_channels WHERE is_active = 1 ORDER BY sort_order, id');
        }
        $p = strtolower(trim($platform));
        return $this->db->all(
            "SELECT * FROM telegram_channels WHERE is_active = 1 AND (platform = :p OR platform = 'all') ORDER BY sort_order, id",
            ['p' => $p]
        );
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
     * بررسی دروازه ورود کاربر در پیام‌رسان مشخص
     *
     * @return array{passed:bool,reason:?string,channels:array,errors:array,self_confirm?:bool}
     */
    public function gate(array $user, ?string $platform = null, ?string $externalUserId = null): array
    {
        $plat = strtolower(trim((string) ($platform ?: ($user['entry_platform'] ?? MessengerCapabilities::TELEGRAM))));
        if (!MessengerCapabilities::isSupported($plat)) {
            $plat = MessengerCapabilities::TELEGRAM;
        }

        // ۱) شماره تماس اجباری (در تمام پیام‌رسان‌هایی که از دریافت شماره پشتیبانی می‌کنند)
        if ($this->contactRequired() && empty($user['phone']) && MessengerCapabilities::supports($plat, 'request_contact')) {
            return ['passed' => false, 'reason' => 'contact', 'channels' => [], 'errors' => []];
        }

        // ۲) عضویت در کانال‌های اجباری
        if (!$this->enforcementEnabled()) {
            return ['passed' => true, 'reason' => null, 'channels' => [], 'errors' => []];
        }

        $channels = $this->activeChannels($plat);
        if ($channels === []) {
            return ['passed' => true, 'reason' => null, 'channels' => [], 'errors' => []];
        }

        // اگر کاربر قبلاً در پیام‌رسان فاقد API عضویت را تأیید کرده است
        if (!MessengerCapabilities::supports($plat, 'channel_check_api')) {
            if (!empty($user['channels_verified'])) {
                return ['passed' => true, 'reason' => null, 'channels' => [], 'errors' => []];
            }
            return [
                'passed' => false,
                'reason' => 'channels',
                'channels' => $channels,
                'errors' => [],
                'self_confirm' => true,
            ];
        }

        // تعیین شناسه کاربری در پیام‌رسان هدف (تلگرام یا بله)
        $extId = $externalUserId;
        if ($extId === null || $extId === '') {
            if ($plat === MessengerCapabilities::TELEGRAM && (int) ($user['telegram_id'] ?? 0) > 0) {
                $extId = (string) $user['telegram_id'];
            } else {
                $acc = (new UserService($this->db))->messengerAccountForPlatform((int) $user['id'], $plat);
                $extId = $acc !== null ? (string) $acc['external_user_id'] : '';
            }
        }
        if ($extId === '') {
            return ['passed' => true, 'reason' => null, 'channels' => [], 'errors' => []];
        }

        $driver = (new MessengerRegistry($this->db))->driver($plat);
        if (!$driver->isConfigured()) {
            return ['passed' => true, 'reason' => null, 'channels' => [], 'errors' => []];
        }

        $notJoined = [];
        $apiErrors = [];
        foreach ($channels as $channel) {
            $checkMode = (string) ($channel['check_mode'] ?? 'api');
            if ($checkMode === 'self_confirm') {
                if (empty($user['channels_verified'])) {
                    $notJoined[] = $channel;
                }
                continue;
            }
            $check = $driver->checkChannelMembership($channel, $extId);
            if (!($check['supported'] ?? true)) {
                if (empty($user['channels_verified'])) {
                    $notJoined[] = $channel;
                }
                continue;
            }
            if (!$check['ok']) {
                $apiErrors[] = $channel['title'] . ': ' . ($check['error'] ?? 'خطای نامشخص');
                continue;
            }
            if ($check['member'] === false) {
                $notJoined[] = $channel;
            }
        }

        if ($apiErrors !== []) {
            SecurityService::log('membership_api_error', 'low', ['platform' => $plat, 'errors' => $apiErrors], (int) $user['id']);
            SettingsService::set('membership_last_error', mb_substr(implode(' | ', $apiErrors), 0, 900));
            SettingsService::set('membership_last_error_at', now());
        }

        if ($notJoined === []) {
            if ($apiErrors === [] && empty($user['channels_verified'])) {
                $this->db->update('users', ['channels_verified' => 1, 'updated_at' => now()], 'id = :id', ['id' => (int) $user['id']]);
            }
            return ['passed' => true, 'reason' => null, 'channels' => [], 'errors' => $apiErrors];
        }

        return ['passed' => false, 'reason' => 'channels', 'channels' => $notJoined, 'errors' => $apiErrors, 'self_confirm' => false];
    }

    /** بررسی سلامت دسترسی ربات‌ها به کانال‌ها در تمام پیام‌رسان‌ها */
    public function health(): array
    {
        $registry = new MessengerRegistry($this->db);
        $tg = new TelegramService(null, $this->db);
        $out = [];

        foreach ($this->activeChannels() as $channel) {
            $plat = strtolower((string) ($channel['platform'] ?? MessengerCapabilities::TELEGRAM));
            $pLabel = MessengerCapabilities::label($plat);

            if (!MessengerCapabilities::supports($plat, 'channel_check_api')) {
                $hasUrl = !empty($channel['invite_link']) || !empty($channel['username']);
                $out[] = [
                    'id' => (int) $channel['id'],
                    'platform' => $plat,
                    'title' => (string) $channel['title'],
                    'ok' => $hasUrl,
                    'message' => $hasUrl
                        ? 'حالت جایگزین هوشمند در ' . $pLabel . ' فعال است (نمایش لینک عضویت + تأیید کاربری).'
                        : 'لطفاً لینک دعوت یا نام کاربری کانال ' . $pLabel . ' را وارد کنید.',
                ];
                continue;
            }

            if ($plat === MessengerCapabilities::TELEGRAM) {
                if (!$tg->isConfigured()) {
                    $out[] = ['id' => (int) $channel['id'], 'platform' => $plat, 'title' => $channel['title'], 'ok' => false, 'message' => 'توکن تلگرام تنظیم نشده است.'];
                    continue;
                }
                $response = $tg->call('getChat', ['chat_id' => $channel['chat_id']]);
                if (!($response['ok'] ?? false)) {
                    $out[] = ['id' => (int) $channel['id'], 'platform' => $plat, 'title' => $channel['title'], 'ok' => false, 'message' => (string) ($response['description'] ?? 'دسترسی به کانال برقرار نشد')];
                    continue;
                }
                $access = $tg->checkBotAccess($channel);
                $out[] = ['id' => (int) $channel['id'], 'platform' => $plat, 'title' => $channel['title'], 'ok' => $access['admin'], 'message' => $access['message']];
                continue;
            }

            // بله
            $driver = $registry->driver($plat);
            $conn = $registry->rawConnection($plat);
            if (!$driver->isConfigured()) {
                $out[] = ['id' => (int) $channel['id'], 'platform' => $plat, 'title' => $channel['title'], 'ok' => false, 'message' => 'توکن ' . $pLabel . ' تنظیم نشده است.'];
                continue;
            }
            $botId = (string) ($conn['bot_id'] ?? '');
            if ($botId === '') {
                $out[] = ['id' => (int) $channel['id'], 'platform' => $plat, 'title' => $channel['title'], 'ok' => false, 'message' => 'ابتدا تست اتصال ' . $pLabel . ' را اجرا کنید.'];
                continue;
            }
            $check = $driver->checkChannelMembership($channel, $botId);
            $out[] = [
                'id' => (int) $channel['id'],
                'platform' => $plat,
                'title' => (string) $channel['title'],
                'ok' => !empty($check['ok']) && !empty($check['member']),
                'message' => (!empty($check['ok']) && !empty($check['member']))
                    ? 'بازوی ' . $pLabel . ' عضو/مدیر کانال است و بررسی خودکار فعال می‌باشد.'
                    : (string) ($check['error'] ?? 'بازوی ' . $pLabel . ' در این کانال مدیر نیست.'),
            ];
        }
        return $out;
    }
}
