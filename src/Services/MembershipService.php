<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Support\Database;

/**
 * موتور هوشمند بررسی عضویت اجباری کانال‌ها (مستقیم و میان‌پیام‌رسانی قابل راستی‌آزمایی)
 *
 * اصول فنی منطبق با مستندات رسمی پیام‌رسان‌ها:
 *   ۱) تلگرام (api.telegram.org) و بله (tapi.bale.ai):
 *      - دارای متد رسمی getChatMember(chat_id, user_id) هستند (ربات باید در کانال ادمین باشد).
 *      - بررسی مستقیم: عضویت کاربران همان پیام‌رسان به‌صورت آنی از API رسمی استعلام می‌شود.
 *      - بررسی میان‌پیام‌رسانی واقعی (مثلاً الزام عضویت کاربر روبیکا/ایتا/بله در کانال تلگرام):
 *        چون شناسه‌های کاربری پیام‌رسان‌ها از یکدیگر مستقل‌اند و هیچ API رسمی استعلام با شماره تلفن ارائه نمی‌دهد،
 *        کاربر ابتدا یک‌بار حساب پیام‌رسان مقصد را از طریق لینک/کد یک‌بارمصرف امن (LNK) به حساب خود متصل می‌کند
 *        و سپس عضویت واقعی او در کانال با متد رسمی getChatMember استعلام و تأیید می‌شود. تا پیش از تأیید واقعی
 *        در همه کانال‌های الزامی، ادامه فرایند و آزادسازی پاداش رفرال متوقف می‌ماند.
 *   ۲) روبیکا (Bot API v3) و ایتا (EitaaYar / برنامک):
 *      - فاقد متد رسمی استعلام عضویت کانال (getChatMember) در API عمومی هستند.
 *      - هیچ بررسی صوری یا غیرواقعی میان‌پیام‌رسانی روی کانال‌های روبیکا و ایتا مجاز نیست؛
 *        این کانال‌ها صرفاً برای کاربران همان پیام‌رسان به‌صورت «نمایش لینک + تأیید کاربری» (self_declared) عمل می‌کنند
 *        و در گزارش‌ها به‌صورت شفاف از عضویت‌های تأییدشده API تفکیک می‌شوند.
 *   ۳) خطای فنی شبکه/عدم دسترسی ادمین ربات هرگز به عنوان «تخلف کاربر» ثبت نمی‌شود و در لاگ خطای پیام‌رسان ثبت می‌گردد.
 */
final class MembershipService
{
    public function __construct(private ?Database $db = null)
    {
        $this->db ??= Database::instance();
    }

    /**
     * استخراج لیست استاندارد پیام‌رسان‌های ورودی مشمول یک کانال
     * @return string[]
     */
    public static function resolveTargetPlatforms(array $channel): array
    {
        $host = strtolower(trim((string) ($channel['platform'] ?? MessengerCapabilities::TELEGRAM)));
        if ($host === 'all') {
            $host = MessengerCapabilities::TELEGRAM;
        }
        if (!MessengerCapabilities::isSupported($host)) {
            $host = MessengerCapabilities::TELEGRAM;
        }

        // پیام‌رسان‌های فاقد getChatMember رسمی (روبیکا و ایتا) هرگز نمی‌توانند شرط میان‌پیام‌رسانی داشته باشند
        if (!MessengerCapabilities::canVerifyCrossMessengerChannel($host)) {
            return [$host];
        }

        $raw = strtolower(trim((string) ($channel['target_platforms'] ?? 'same')));
        if ($raw === '' || $raw === 'same') {
            return [$host];
        }
        if ($raw === 'all') {
            return MessengerCapabilities::platforms();
        }

        $parts = preg_split('~[\s,|;]+~', $raw) ?: [];
        $valid = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && MessengerCapabilities::isSupported($p) && !in_array($p, $valid, true)) {
                $valid[] = $p;
            }
        }
        return $valid !== [] ? $valid : [$host];
    }

    /**
     * نرمال‌سازی مقدار ذخیره‌سازی target_platforms هنگام ثبت/ویرایش کانال در پنل مدیریت
     * @param string[] $selectedPlatforms
     */
    public static function encodeTargetPlatforms(string $hostPlatform, array $selectedPlatforms): string
    {
        $host = strtolower(trim($hostPlatform));
        if (!MessengerCapabilities::canVerifyCrossMessengerChannel($host)) {
            return 'same';
        }
        $clean = [];
        foreach ($selectedPlatforms as $p) {
            $k = strtolower(trim((string) $p));
            if ($k !== '' && MessengerCapabilities::isSupported($k) && !in_array($k, $clean, true)) {
                $clean[] = $k;
            }
        }
        if ($clean === [] || $clean === [$host]) {
            return 'same';
        }
        return implode(',', $clean);
    }

    /** آیا این کانال دارای قاعده میان‌پیام‌رسانی (الزام برای کاربران سایر پیام‌رسان‌ها) است؟ */
    public static function hasCrossPlatformTargets(array $channel): bool
    {
        $host = strtolower(trim((string) ($channel['platform'] ?? MessengerCapabilities::TELEGRAM)));
        foreach (self::resolveTargetPlatforms($channel) as $tp) {
            if ($tp !== $host) {
                return true;
            }
        }
        return false;
    }

    /**
     * کانال‌های فعال قابل اعمال برای کاربران ورودی از پیام‌رسان مشخص
     * (شامل کانال‌های بومی همان پیام‌رسان + کانال‌های میان‌پیام‌رسانی قابل راستی‌آزمایی که این پیام‌رسان در target_platforms آن‌هاست)
     *
     * @return array<int,array<string,mixed>>
     */
    public function activeChannels(?string $entryPlatform = null): array
    {
        if (!$this->db->tableExists('telegram_channels')) {
            return [];
        }
        $all = $this->db->all('SELECT * FROM telegram_channels WHERE is_active = 1 ORDER BY sort_order, id');
        if ($entryPlatform === null || $entryPlatform === '' || !$this->db->columnExists('telegram_channels', 'platform')) {
            return $all;
        }

        $entry = strtolower(trim($entryPlatform));
        $out = [];
        foreach ($all as $ch) {
            $rawPlat = strtolower(trim((string) ($ch['platform'] ?? MessengerCapabilities::TELEGRAM)));
            if ($rawPlat === 'all') {
                $out[] = $ch;
                continue;
            }
            $targets = self::resolveTargetPlatforms($ch);
            if (in_array($entry, $targets, true)) {
                $out[] = $ch;
            }
        }
        return $out;
    }

    /**
     * کانال‌های تعریف‌شده روی یک پیام‌رسان میزبان خاص (برای نمایش تفکیک‌شده در پنل مدیریت)
     * @return array<int,array<string,mixed>>
     */
    public function channelsHostedOn(string $hostPlatform, bool $onlyActive = false): array
    {
        if (!$this->db->tableExists('telegram_channels')) {
            return [];
        }
        $p = strtolower(trim($hostPlatform));
        if (!$this->db->columnExists('telegram_channels', 'platform')) {
            if ($p !== MessengerCapabilities::TELEGRAM) {
                return [];
            }
            $sql = 'SELECT * FROM telegram_channels' . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id';
            return $this->db->all($sql);
        }
        $sql = 'SELECT * FROM telegram_channels WHERE platform = :p'
            . ($onlyActive ? ' AND is_active = 1' : '')
            . ' ORDER BY sort_order, id';
        return $this->db->all($sql, ['p' => $p]);
    }

    /** آیا برای پیام‌رسان ورودی داده‌شده، حداقل یک کانال فعال با ربات پیکربندی‌شده وجود دارد؟ */
    public function hasEnforceableChannelsFor(string $entryPlatform): bool
    {
        $channels = $this->activeChannels($entryPlatform);
        if ($channels === []) {
            return false;
        }
        $entry = strtolower(trim($entryPlatform));
        foreach ($channels as $ch) {
            $host = strtolower(trim((string) ($ch['platform'] ?? MessengerCapabilities::TELEGRAM)));
            if ($host === 'all') {
                $host = $entry;
            }
            if ($this->isHostDriverAvailable($host, $host !== $entry)) {
                return true;
            }
        }
        return false;
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
     * بررسی کامل دروازه ورود و کانال‌های اجباری (مستقیم + میان‌پیام‌رسانی تأییدشده)
     *
     * @return array{
     *   passed:bool,
     *   reason:?string,
     *   channels:array<int,array<string,mixed>>,
     *   errors:array<int,string>,
     *   self_confirm?:bool,
     *   has_api_channels?:bool,
     *   has_self_channels?:bool,
     *   needs_link_platforms?:string[]
     * }
     */
    public function gate(array $user, ?string $platform = null, ?string $externalUserId = null): array
    {
        $entryPlat = strtolower(trim((string) ($platform ?: ($user['entry_platform'] ?? MessengerCapabilities::TELEGRAM))));
        if (!MessengerCapabilities::isSupported($entryPlat)) {
            $entryPlat = MessengerCapabilities::TELEGRAM;
        }

        // ۱) شماره تماس اجباری (در پیام‌رسان‌های دارای دکمه اشتراک شماره)
        if ($this->contactRequired() && empty($user['phone']) && MessengerCapabilities::supports($entryPlat, 'request_contact')) {
            return ['passed' => false, 'reason' => 'contact', 'channels' => [], 'errors' => []];
        }

        // ۲) عضویت در کانال‌های اجباری
        if (!$this->enforcementEnabled()) {
            return ['passed' => true, 'reason' => null, 'channels' => [], 'errors' => []];
        }

        $channels = $this->activeChannels($entryPlat);
        if ($channels === []) {
            return ['passed' => true, 'reason' => null, 'channels' => [], 'errors' => []];
        }

        $userId = (int) ($user['id'] ?? 0);
        $userService = new UserService($this->db);
        $registry = new MessengerRegistry($this->db);

        $notJoined = [];
        $apiErrors = [];
        $needsLinkPlatforms = [];
        $hasMissingApi = false;
        $hasMissingSelf = false;
        $evaluatedAny = false;

        foreach ($channels as $channel) {
            $channelId = (int) ($channel['id'] ?? 0);
            $hostPlat = strtolower(trim((string) ($channel['platform'] ?? MessengerCapabilities::TELEGRAM)));
            if ($hostPlat === 'all' || !MessengerCapabilities::isSupported($hostPlat)) {
                $hostPlat = $entryPlat;
            }
            $isCross = ($hostPlat !== $entryPlat);
            $supportsApi = MessengerCapabilities::supports($hostPlat, 'channel_check_api');
            $checkMode = (string) ($channel['check_mode'] ?? ($supportsApi ? 'api' : 'self_confirm'));

            // محافظ امنیتی: هرگز بررسی میان‌پیام‌رسانی بدون API رسمی اجرا نشود
            if ($isCross && (!$supportsApi || $checkMode !== 'api')) {
                continue;
            }

            // حالت الف: کانال بومی فاقد API بررسی عضویت (روبیکا / ایتا) یا حالت self_confirm بومی
            if (!$supportsApi || $checkMode === 'self_confirm') {
                $evaluatedAny = true;
                if ($this->isSelfChannelSatisfied($user, $channelId)) {
                    continue;
                }
                $hasMissingSelf = true;
                $notJoined[] = $this->enrichChannelForGate($channel, $hostPlat, $entryPlat, 'self_declared');
                continue;
            }

            // حالت ب: کانال دارای API رسمی getChatMember (تلگرام یا بله) — بومی یا میان‌پیام‌رسانی
            if (!$this->isHostDriverAvailable($hostPlat, $isCross)) {
                if ($isCross) {
                    MessengerRegistry::logError(
                        $hostPlat,
                        'channel_check',
                        'بررسی میان‌پیام‌رسانی کانال «' . ($channel['title'] ?? '') . '» به دلیل غیرفعال یا قطع بودن ربات ' . MessengerCapabilities::label($hostPlat) . ' موقتاً رد شد.',
                        'getChatMember',
                        null,
                        ['channel_id' => $channelId, 'entry_platform' => $entryPlat],
                        $this->db
                    );
                }
                continue;
            }

            // استخراج شناسه کاربری تأییدشده در پیام‌رسان میزبان کانال
            $targetExtId = $this->resolveUserExternalIdOnHost(
                $user,
                $userService,
                $hostPlat,
                $entryPlat,
                $externalUserId
            );

            if ($targetExtId === '') {
                if ($isCross) {
                    // کاربر از پیام‌رسان دیگری وارد شده و هنوز حساب تلگرام/بله خود را متصل نکرده است → الزام واقعی اتصال + عضویت
                    $evaluatedAny = true;
                    $hasMissingApi = true;
                    if (!in_array($hostPlat, $needsLinkPlatforms, true)) {
                        $needsLinkPlatforms[] = $hostPlat;
                    }
                    $conn = $registry->safeConnection($hostPlat);
                    $notJoined[] = $this->enrichChannelForGate(
                        $channel,
                        $hostPlat,
                        $entryPlat,
                        'cross_link_required',
                        (string) ($conn['bot_username'] ?? '')
                    );
                }
                continue;
            }

            $evaluatedAny = true;
            $check = $this->performApiMembershipCheck($hostPlat, $registry, $channel, $targetExtId);

            if (!($check['supported'] ?? true)) {
                if (!$isCross && !$this->isSelfChannelSatisfied($user, $channelId)) {
                    $hasMissingSelf = true;
                    $notJoined[] = $this->enrichChannelForGate($channel, $hostPlat, $entryPlat, 'self_declared');
                }
                continue;
            }

            if (empty($check['ok'])) {
                $errText = (string) ($check['error'] ?? 'خطای نامشخص در استعلام عضویت');
                $apiErrors[] = $channel['title'] . ': ' . $errText;
                MessengerRegistry::logError(
                    $hostPlat,
                    'channel_check',
                    'خطا در بررسی عضویت کانال «' . ($channel['title'] ?? '') . '»: ' . $errText,
                    'getChatMember',
                    null,
                    ['channel_id' => $channelId, 'entry_platform' => $entryPlat, 'cross' => $isCross],
                    $this->db
                );
                continue;
            }

            if (($check['member'] ?? false) === false) {
                $this->recordMembershipStatus(
                    $userId,
                    $channelId,
                    $hostPlat,
                    $entryPlat,
                    $isCross ? 'api_cross_linked' : 'api_direct',
                    'left',
                    $targetExtId
                );
                $hasMissingApi = true;
                $conn = $isCross ? $registry->safeConnection($hostPlat) : [];
                $notJoined[] = $this->enrichChannelForGate(
                    $channel,
                    $hostPlat,
                    $entryPlat,
                    $isCross ? 'cross_api_not_joined' : 'direct_api',
                    (string) ($conn['bot_username'] ?? '')
                );
                continue;
            }

            // عضویت به‌صورت واقعی از طریق API رسمی تأیید شد
            $this->recordMembershipStatus(
                $userId,
                $channelId,
                $hostPlat,
                $entryPlat,
                $isCross ? 'api_cross_linked' : 'api_direct',
                'verified',
                $targetExtId
            );
        }

        if ($apiErrors !== []) {
            SecurityService::log('membership_api_error', 'low', ['platform' => $entryPlat, 'errors' => $apiErrors], $userId ?: null);
            SettingsService::set('membership_last_error', mb_substr(implode(' | ', $apiErrors), 0, 900));
            SettingsService::set('membership_last_error_at', now());
        }

        if ($notJoined === []) {
            if ($evaluatedAny && $apiErrors === [] && $userId > 0 && empty($user['channels_verified'])) {
                $this->db->update('users', ['channels_verified' => 1, 'updated_at' => now()], 'id = :id', ['id' => $userId]);
            }
            return ['passed' => true, 'reason' => null, 'channels' => [], 'errors' => $apiErrors];
        }

        // اگر کاربر قبلاً channels_verified=1 بوده ولی از کانال خارج شده یا کانال جدید میان‌پیام‌رسانی اضافه شده، وضعیت را به‌روز نگه دار
        if ($userId > 0 && !empty($user['channels_verified'])) {
            $this->db->update('users', ['channels_verified' => 0, 'updated_at' => now()], 'id = :id', ['id' => $userId]);
        }

        return [
            'passed' => false,
            'reason' => 'channels',
            'channels' => $notJoined,
            'errors' => $apiErrors,
            'self_confirm' => ($hasMissingSelf && !$hasMissingApi),
            'has_api_channels' => $hasMissingApi,
            'has_self_channels' => $hasMissingSelf,
            'needs_link_platforms' => $needsLinkPlatforms,
        ];
    }

    /**
     * ثبت تأیید کاربری فقط برای کانال‌های بومی فاقد API رسمی (روبیکا / ایتا).
     * هرگز کانال‌های API یا میان‌پیام‌رسانی (تلگرام / بله) را بدون استعلام واقعی تأیید نمی‌کند!
     *
     * @return array{confirmed_count:int,all_passed:bool,gate:array}
     */
    public function confirmSelfDeclaredChannels(array $user, string $entryPlatform, ?string $externalUserId = null): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $entry = strtolower(trim($entryPlatform));
        $confirmed = 0;

        if ($userId > 0) {
            foreach ($this->activeChannels($entry) as $channel) {
                $host = strtolower(trim((string) ($channel['platform'] ?? $entry)));
                if ($host === 'all') {
                    $host = $entry;
                }
                // فقط کانال بومی فاقد API یا حالت self_confirm بومی
                if ($host !== $entry) {
                    continue;
                }
                $supportsApi = MessengerCapabilities::supports($host, 'channel_check_api');
                $mode = (string) ($channel['check_mode'] ?? ($supportsApi ? 'api' : 'self_confirm'));
                if ($supportsApi && $mode === 'api') {
                    continue;
                }
                $this->recordMembershipStatus(
                    $userId,
                    (int) ($channel['id'] ?? 0),
                    $host,
                    $entry,
                    'self_declared',
                    'verified',
                    $externalUserId
                );
                $confirmed++;
            }
        }

        $freshUser = $userId > 0 ? ((new UserService($this->db))->find($userId) ?? $user) : $user;
        $gate = $this->gate($freshUser, $entry, $externalUserId);

        // اگر هیچ کانال الزامی باقی نمانده (یا اصلاً کانالی تعریف نشده بود)، channels_verified = 1 ثبت شود
        if ($gate['passed'] && $userId > 0) {
            $this->db->update('users', ['channels_verified' => 1, 'updated_at' => now()], 'id = :id', ['id' => $userId]);
        }

        return [
            'confirmed_count' => $confirmed,
            'all_passed' => $gate['passed'],
            'gate' => $gate,
        ];
    }

    /** ساخت لینک یک‌کلیکی اتصال امن حساب پیام‌رسان مقصد (تلگرام یا بله) برای تأیید کانال میان‌پیام‌رسانی */
    public function buildCrossPlatformLinkPrompt(int $userId, string $hostPlatform): array
    {
        $host = strtolower(trim($hostPlatform));
        $registry = new MessengerRegistry($this->db);
        $conn = $registry->safeConnection($host);
        $botUsername = ltrim((string) ($conn['bot_username'] ?? ''), '@');

        $tokenData = (new UserService($this->db))->createLinkToken($userId);
        $code = (string) ($tokenData['code'] ?? '');
        $deepLink = '';
        if ($botUsername !== '' && $code !== '') {
            $deepLink = match ($host) {
                MessengerCapabilities::TELEGRAM => 'https://t.me/' . $botUsername . '?start=' . rawurlencode($code),
                MessengerCapabilities::BALE     => 'https://ble.ir/' . $botUsername . '?start=' . rawurlencode($code),
                default                         => '',
            };
        }

        return [
            'platform' => $host,
            'label' => MessengerCapabilities::label($host),
            'icon' => MessengerCapabilities::icon($host),
            'badge' => MessengerCapabilities::badge($host),
            'bot_username' => $botUsername,
            'code' => $code,
            'deep_link' => $deepLink,
            'expires_in_minutes' => (int) ($tokenData['expires_in_minutes'] ?? 15),
        ];
    }

    /** آمار دقیق عضویت به تفکیک شناسه کانال (مستقیم API / میان‌پیام‌رسانی API / تأیید کاربری) */
    public function membershipCountsByChannel(): array
    {
        if (!$this->db->tableExists('user_channel_memberships')) {
            return [];
        }
        $rows = $this->db->all(
            "SELECT channel_id,
                    COUNT(*) AS total_verified,
                    SUM(CASE WHEN verify_method = 'api_direct' THEN 1 ELSE 0 END) AS api_direct,
                    SUM(CASE WHEN verify_method = 'api_cross_linked' THEN 1 ELSE 0 END) AS api_cross,
                    SUM(CASE WHEN verify_method = 'self_declared' THEN 1 ELSE 0 END) AS self_declared
             FROM user_channel_memberships
             WHERE status = 'verified'
             GROUP BY channel_id"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['channel_id']] = [
                'total' => (int) $r['total_verified'],
                'api_direct' => (int) $r['api_direct'],
                'api_cross' => (int) $r['api_cross'],
                'self_declared' => (int) $r['self_declared'],
            ];
        }
        return $out;
    }

    /** بررسی سلامت دسترسی ربات‌ها به کانال‌ها (فقط پیام‌رسان‌های فعال و متصل در صورت درخواست) */
    public function health(?array $onlyPlatforms = null): array
    {
        $registry = new MessengerRegistry($this->db);
        $tg = new TelegramService(null, $this->db);
        $out = [];

        foreach ($this->activeChannels() as $channel) {
            $plat = strtolower((string) ($channel['platform'] ?? MessengerCapabilities::TELEGRAM));
            if ($onlyPlatforms !== null && !in_array($plat, $onlyPlatforms, true)) {
                continue;
            }
            $pLabel = MessengerCapabilities::label($plat);

            if (!MessengerCapabilities::supports($plat, 'channel_check_api')) {
                $hasUrl = !empty($channel['invite_link']) || str_starts_with((string) ($channel['chat_id'] ?? ''), '@');
                $out[] = [
                    'id' => (int) $channel['id'],
                    'platform' => $plat,
                    'title' => (string) $channel['title'],
                    'ok' => $hasUrl,
                    'message' => $hasUrl
                        ? 'نمایش لینک عضویت + تأیید کاربری در ' . $pLabel . ' فعال است (API رسمی ' . $pLabel . ' فاقد getChatMember است).'
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
                    ? 'بازوی ' . $pLabel . ' عضو/مدیر کانال است و بررسی خودکار API (بومی و میان‌پیام‌رسانی) فعال می‌باشد.'
                    : (string) ($check['error'] ?? 'بازوی ' . $pLabel . ' در این کانال مدیر نیست.'),
            ];
        }
        return $out;
    }

    /** ساخت لینک استاندارد عضویت در کانال */
    public static function channelUrl(array $channel): ?string
    {
        $invite = trim((string) ($channel['invite_link'] ?? ''));
        if ($invite !== '' && preg_match('~^https?://~i', $invite)) {
            return $invite;
        }
        $chatId = trim((string) ($channel['chat_id'] ?? ''));
        if (str_starts_with($chatId, '@')) {
            $plat = strtolower(trim((string) ($channel['platform'] ?? MessengerCapabilities::TELEGRAM)));
            $domain = match ($plat) {
                MessengerCapabilities::BALE   => 'https://ble.ir/',
                MessengerCapabilities::RUBIKA => 'https://rubika.ir/',
                MessengerCapabilities::EITAA  => 'https://eitaa.com/',
                default                       => 'https://t.me/',
            };
            return $domain . ltrim($chatId, '@');
        }
        return null;
    }

    private function isHostDriverAvailable(string $hostPlat, bool $requireEnabledAndConnected): bool
    {
        $registry = new MessengerRegistry($this->db);
        if ($requireEnabledAndConnected) {
            // برای الزام میان‌پیام‌رسانی، پیام‌رسان میزبان باید هم فعال باشد و هم اتصال معتبر داشته باشد
            return $registry->isActiveAndConnected($hostPlat);
        }
        if ($hostPlat === MessengerCapabilities::TELEGRAM) {
            $tg = new TelegramService(null, $this->db);
            if ($tg->isConfigured()) {
                return true;
            }
        }
        return $registry->driver($hostPlat)->isConfigured();
    }

    private function resolveUserExternalIdOnHost(
        array $user,
        UserService $userService,
        string $hostPlat,
        string $entryPlat,
        ?string $externalUserId
    ): string {
        if ($hostPlat === $entryPlat && $externalUserId !== null && $externalUserId !== '') {
            return $externalUserId;
        }
        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0) {
            $acc = $userService->messengerAccountForPlatform($userId, $hostPlat);
            if ($acc !== null && !empty($acc['external_user_id'])) {
                return (string) $acc['external_user_id'];
            }
        }
        if ($hostPlat === MessengerCapabilities::TELEGRAM && (int) ($user['telegram_id'] ?? 0) > 0) {
            return (string) $user['telegram_id'];
        }
        return '';
    }

    private function performApiMembershipCheck(string $hostPlat, MessengerRegistry $registry, array $channel, string $targetExtId): array
    {
        return $registry->driver($hostPlat)->checkChannelMembership($channel, $targetExtId);
    }

    private function isSelfChannelSatisfied(array $user, int $channelId): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0 && $channelId > 0 && $this->db->tableExists('user_channel_memberships')) {
            $row = $this->db->first(
                "SELECT status FROM user_channel_memberships WHERE user_id = :u AND channel_id = :c LIMIT 1",
                ['u' => $userId, 'c' => $channelId]
            );
            if ($row !== null) {
                return (($row['status'] ?? '') === 'verified');
            }
            // سازگاری پسرو با کاربرانی که پیش از v1.5.0 فقط ستون channels_verified=1 داشته‌اند
            $anyRowForUser = $this->db->value(
                'SELECT id FROM user_channel_memberships WHERE user_id = :u LIMIT 1',
                ['u' => $userId]
            );
            if (!$anyRowForUser && !empty($user['channels_verified'])) {
                return true;
            }
            return false;
        }
        return !empty($user['channels_verified']);
    }

    private function recordMembershipStatus(
        int $userId,
        int $channelId,
        string $channelPlatform,
        string $entryPlatform,
        string $verifyMethod,
        string $status,
        ?string $verifiedExternalId = null
    ): void {
        if ($userId <= 0 || $channelId <= 0 || !$this->db->tableExists('user_channel_memberships')) {
            return;
        }
        try {
            $now = now();
            $existing = $this->db->first(
                'SELECT id, verified_at FROM user_channel_memberships WHERE user_id = :u AND channel_id = :c LIMIT 1',
                ['u' => $userId, 'c' => $channelId]
            );
            $data = [
                'channel_platform' => $channelPlatform,
                'entry_platform' => $entryPlatform,
                'verify_method' => $verifyMethod,
                'status' => $status,
                'verified_external_id' => $verifiedExternalId !== null && $verifiedExternalId !== '' ? mb_substr($verifiedExternalId, 0, 128) : null,
                'last_checked_at' => $now,
                'updated_at' => $now,
            ];
            if ($status === 'verified') {
                $data['verified_at'] = ($existing !== null && !empty($existing['verified_at'])) ? (string) $existing['verified_at'] : $now;
            }
            if ($existing !== null) {
                $this->db->update('user_channel_memberships', $data, 'id = :id', ['id' => (int) $existing['id']]);
            } else {
                $data['user_id'] = $userId;
                $data['channel_id'] = $channelId;
                $data['created_at'] = $now;
                if ($status !== 'verified') {
                    $data['verified_at'] = null;
                }
                $this->db->insert('user_channel_memberships', $data);
            }
        } catch (\Throwable) {
        }
    }

    private function enrichChannelForGate(
        array $channel,
        string $hostPlat,
        string $entryPlat,
        string $requirementType,
        string $hostBotUsername = ''
    ): array {
        $channel['host_platform'] = $hostPlat;
        $channel['host_label'] = MessengerCapabilities::label($hostPlat);
        $channel['host_icon'] = MessengerCapabilities::icon($hostPlat);
        $channel['host_badge'] = MessengerCapabilities::badge($hostPlat);
        $channel['host_bot_username'] = ltrim($hostBotUsername, '@');
        $channel['is_cross_platform'] = ($hostPlat !== $entryPlat);
        $channel['requirement_type'] = $requirementType;
        $channel['join_url'] = self::channelUrl($channel);
        return $channel;
    }
}
