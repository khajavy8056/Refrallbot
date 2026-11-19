<?php
declare(strict_types=1);

namespace RB\Bot;

use RB\Messengers\IncomingUpdate;
use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerDriverInterface;
use RB\Messengers\MessengerRegistry;
use RB\Messengers\RubikaDriver;
use RB\Messengers\TelegramDriver;
use RB\Services\CampaignService;
use RB\Services\CommissionSettings;
use RB\Services\InviteContentService;
use RB\Services\MembershipService;
use RB\Services\MenuService;
use RB\Services\Notifier;
use RB\Services\RankingService;
use RB\Services\ReferralMessageService;
use RB\Services\SecurityService;
use RB\Services\SettingsService;
use RB\Services\StatsService;
use RB\Services\SupportService;
use RB\Services\TelegramService;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Services\WithdrawalService;
use RB\Support\Config;
use RB\Support\Crypto;
use RB\Support\Database;
use RB\Support\Money;
use RB\Support\RateLimiter;
use RB\Support\Request;

/**
 * پردازنده یکپارچه ربات چندپیام‌رسانی (تلگرام، بله، روبیکا و پیام‌رسان‌های آینده)
 *
 * تمام منطق ثبت‌نام، ارجاع، درآمد لایه‌ای، کیف پول، استودیو دعوت و پشتیبانی در هسته مشترک اجرا می‌شود
 * و درایور هر پیام‌رسان صرفاً لایه نمایش و ارتباط با API رسمی آن پیام‌رسان را بر عهده دارد.
 */
final class BotHandler
{
    private Database $db;
    private MessengerDriverInterface $driver;
    private MessengerRegistry $registry;
    private string $platform;
    private UserService $users;
    private BotSession $session;
    private InviteContentService $inviteContent;
    private int $maxDepth;

    public function __construct(?Database $db = null, \RB\Services\TelegramService|MessengerDriverInterface|null $driver = null)
    {
        $this->db = $db ?? Database::instance();
        $this->registry = new MessengerRegistry($this->db);
        if ($driver instanceof \RB\Services\TelegramService) {
            $conn = $this->registry->rawConnection(MessengerCapabilities::TELEGRAM) ?? ['platform' => MessengerCapabilities::TELEGRAM, 'is_enabled' => 1];
            $this->driver = new \RB\Messengers\TelegramDriver($conn, $this->db, null, $driver);
        } else {
            $this->driver = $driver ?? $this->registry->driver(MessengerCapabilities::TELEGRAM);
        }
        $this->platform = $this->driver->platform();
        $this->users = new UserService($this->db);
        $this->session = new BotSession($this->db);
        $this->inviteContent = new InviteContentService($this->db);
        $this->maxDepth = (int) Config::get('app.max_referral_depth', 8);
    }

    /** پردازش آپدیت خام (۱۰۰٪ سازگار با TelegramWebhookController و تست‌های قبلی) */
    public function handle(array $update, string $endpointHint = ''): void
    {
        try {
            $this->db->transaction(function () use ($update, $endpointHint) {
                $this->executeUpdate($update, $endpointHint);
            });
        } catch (\Throwable $e) {
            Notifier::clearDeferred();
            throw $e;
        }
        Notifier::flushDeferred();
    }

    /** پردازش یک IncomingUpdate استاندارد (از وب‌هوک بله/روبیکا یا Polling) */
    public function handleNormalized(IncomingUpdate $upd): void
    {
        try {
            $this->db->transaction(function () use ($upd) {
                $this->processIncoming($upd);
            });
        } catch (\Throwable $e) {
            Notifier::clearDeferred();
            throw $e;
        }
        Notifier::flushDeferred();
    }

    public function executeUpdate(array $update, string $endpointHint = ''): void
    {
        if ($this->platform === MessengerCapabilities::TELEGRAM) {
            try {
                $this->db->run(
                    'UPDATE telegram_settings SET last_update_at = :now, updates_count = updates_count + 1, updated_at = :now2',
                    ['now' => now(), 'now2' => now()]
                );
            } catch (\Throwable) {
            }
        }

        $parsed = $this->driver->parseWebhookPayload($update, $endpointHint);
        foreach ($parsed as $upd) {
            $this->processIncoming($upd);
        }
    }

    private function processIncoming(IncomingUpdate $upd): void
    {
        if ($upd->isBot || $upd->externalUserId === '' || $upd->chatId === '') {
            return;
        }

        // رویداد توقف ربات توسط کاربر (مثلاً StoppedBot در روبیکا)
        if ($upd->kind === 'stopped') {
            $this->users->markMessengerBlocked($this->platform, $upd->externalUserId, true);
            return;
        }

        if ($upd->chatType !== 'private') {
            if ($upd->kind === 'contact') {
                $this->send($upd->chatId, 'ارسال شماره تماس فقط در چت خصوصی با ربات امکان‌پذیر است.');
            }
            return;
        }

        if ($upd->kind === 'callback') {
            $this->dispatchCallbackUpdate($upd);
            return;
        }

        // محدودیت نرخ ضد اسپم به تفکیک پیام‌رسان و کاربر
        $rateKey = 'bot:' . $this->platform . ':' . $upd->externalUserId;
        if (RateLimiter::tooMany($rateKey, 30, 60)) {
            if ($upd->messageId !== null && !RateLimiter::tooMany('bot:notice:' . $this->platform . ':' . $upd->externalUserId, 1, 60)) {
                $this->send($upd->chatId, '⏳ تعداد پیام‌های شما زیاد است. لطفاً یک دقیقه صبر کنید.');
            }
            return;
        }

        $existingUser = $this->users->findByMessenger($this->platform, $upd->externalUserId);
        $incomingSession = $existingUser ? $this->getUserSession($existingUser) : null;
        $loggedText = ($incomingSession['step'] ?? '') === 'withdraw_card' ? '[شماره کارت محرمانه]' : $upd->text;
        $this->driver->logMessage(
            $existingUser ? (int) $existingUser['id'] : null,
            $upd->chatId,
            'in',
            $upd->kind,
            ['text' => $loggedText, 'start' => $upd->startPayload],
            'ok',
            $upd->messageId
        );

        $text = trim($upd->text);

        // لغو عملیات چندمرحله‌ای
        if ($text === '/cancel' || $text === 'انصراف') {
            if ($existingUser !== null) {
                $this->clearUserSession($existingUser);
                $this->sendMainMenu($existingUser, $upd->chatId, '✅ عملیات لغو شد. گزینه مورد نظر را از منو انتخاب کنید:');
            }
            return;
        }

        // ۱) رویداد /start یا StartedBot همراه کد دعوت یا کد اتصال حساب
        if ($upd->kind === 'started' || str_starts_with($text, '/start')) {
            $payload = $upd->startPayload;
            if (($payload === null || $payload === '') && preg_match('~^/start(?:@[A-Za-z0-9_]+)?\s+(\S+)~iu', $text, $m)) {
                $payload = $m[1];
            }
            $this->cmdStart($upd, $payload);
            return;
        }

        // ۲) دستورات عمومی
        if ($text === '/help' || $text === '/راهنما') {
            $this->sendGuide($upd->chatId);
            return;
        }

        if ($text === '/admin' && $this->platform === MessengerCapabilities::TELEGRAM && ctype_digit($upd->externalUserId)) {
            $this->cmdAdmin((int) $upd->externalUserId, $upd->chatId);
            return;
        }

        // ۳) یافتن کاربر ثبت‌شده (در روبیکا اگر کاربر مستقیم پیام داد، به‌صورت امن ثبت‌نام اولیه می‌شود)
        $user = $existingUser;
        if ($user === null) {
            if ($this->platform === MessengerCapabilities::RUBIKA) {
                $this->cmdStart($upd, $upd->startPayload);
                return;
            }
            $this->send($upd->chatId, 'برای استفاده از ربات ابتدا دستور /start را ارسال کنید.');
            return;
        }

        $this->users->touchActivity((int) $user['id'], Request::ip());
        $this->users->upsertMessengerAccount((int) $user['id'], $this->platform, $upd->userPayload(), false, 'bot_auth', true);

        if (($user['status'] ?? 'active') === 'blocked') {
            $this->send($upd->chatId, '⛔️ حساب شما توسط مدیریت مسدود شده است. برای پیگیری با پشتیبانی تماس بگیرید.');
            return;
        }

        // ۴) دستور سریع اتصال حساب بین پیام‌رسان‌ها: /link یا /link LNK-XXXXXX
        if (str_starts_with(strtolower($text), '/link')) {
            $parts = preg_split('~\s+~', $text, 2);
            $arg = isset($parts[1]) ? trim($parts[1]) : '';
            if ($arg !== '') {
                $res = $this->users->redeemLinkToken($arg, $this->platform, $upd->userPayload());
                if ($res['ok'] && isset($res['user'])) {
                    $this->clearUserSession($user);
                    $this->completePostLinkVerification($res['user'], $upd->chatId, (string) $res['message']);
                } else {
                    $this->send($upd->chatId, '❌ ' . $res['message']);
                }
                return;
            }
            $this->actLinkAccount($user, $upd->chatId);
            return;
        }

        // ۵) دریافت شماره تماس (Request Contact / AskMyPhoneNumber)
        if ($upd->kind === 'contact' && $upd->contactPhone !== null) {
            $this->handleContact($user, $upd);
            return;
        }

        // ۶) مدیریت گفتگوهای چندمرحله‌ای (برداشت، تیکت، پروفایل، کد اتصال)
        $session = $this->getUserSession($user);
        if ($session !== null && $session['step'] !== null) {
            // اگر کاربر وسط مرحله یکی از دکمه‌های اصلی منو را زد، مرحله قبلی را ببند
            if (MenuService::actionByTitle($text) === null && $text !== '🏠 منوی اصلی' && $text !== '/menu') {
                if ($this->handleStep($user, $session, $text, $upd->chatId, $upd)) {
                    return;
                }
            } else {
                $this->clearUserSession($user);
            }
        }

        // ۷) بررسی دروازه‌های اجباری ورود
        if (!$this->passGates($user, $upd->chatId, $upd->externalUserId)) {
            return;
        }
        if (!empty($user['referred_by']) && empty($user['qualified_at'])) {
            $fresh = $this->users->find((int) $user['id']) ?? $user;
            if ($this->users->maybeQualify($fresh, false, $this->platform)) {
                Notifier::flushDeferred();
                $user = $this->users->find((int) $user['id']) ?? $fresh;
            }
        }

        // ۸) دستورات میانبر متنی
        $shortcutMap = [
            '/invite'    => 'invite',
            '/wallet'    => 'wallet',
            '/referrals' => 'referrals',
            '/income'    => 'income',
            '/withdraw'  => 'withdraw',
            '/support'   => 'support',
            '/account'   => 'account',
        ];
        if (isset($shortcutMap[strtolower($text)])) {
            $this->dispatchAction($user, $shortcutMap[strtolower($text)], $upd->chatId);
            return;
        }

        // ۹) دکمه‌های منوی اصلی
        $action = MenuService::actionByTitle($text);
        if ($action !== null) {
            $this->dispatchAction($user, $action, $upd->chatId);
            return;
        }

        if ($text === '🏠 منوی اصلی' || $text === '/menu') {
            $ui = SettingsService::resolveUiModeFor($this->platform);
            $prompt = $ui['effective'] === 'miniapp'
                ? '📱 برای مدیریت حساب، دعوت دوستان، مشاهده بنرها و برداشت وجه، دکمه «ورود به پنل کاربری» را لمس کنید:'
                : '🏠 منوی اصلی — گزینه مورد نظر خود را انتخاب کنید:';
            $this->sendMainMenu($user, $upd->chatId, $prompt);
            return;
        }

        if ($text === '/app') {
            $ui = SettingsService::resolveUiModeFor($this->platform);
            if ($ui['show_miniapp']) {
                $this->driver->sendMessage(
                    $upd->chatId,
                    "📱 <b>پنل کاربری (Mini App)</b>\n\nبرای باز کردن پنل گرافیکی، استودیو دعوت و کیف پول روی دکمه زیر بزنید:",
                    ['inline_rows' => $this->panelInlineRows()]
                );
            } else {
                $this->sendMainMenu($user, $upd->chatId, '⌨️ تمام امکانات از طریق دکمه‌های زیر در دسترس شماست:');
            }
            return;
        }

        $this->send($upd->chatId, 'متوجه نشدم. لطفاً از دکمه‌های منو استفاده کنید یا دستور /menu را ارسال نمایید.');
    }

    // ------------------------------------------------------------------ /start و ثبت‌نام یکپارچه

    private function cmdStart(IncomingUpdate $upd, ?string $payload): void
    {
        $before = $this->users->findByMessenger($this->platform, $upd->externalUserId);
        $isNew = $before === null;

        if ($before !== null && ($before['status'] ?? 'active') === 'blocked') {
            $this->send($upd->chatId, '⛔️ حساب شما مسدود است.');
            return;
        }

        // غنی‌سازی پروفایل در روبیکا با متد رسمی getChat هنگام اولین ورود
        $userPayload = $upd->userPayload();
        if ($isNew && $this->driver instanceof RubikaDriver && empty($userPayload['first_name'])) {
            try {
                $chatInfo = $this->driver->fetchChatInfo($upd->chatId);
                if (!empty($chatInfo['first_name'])) {
                    $userPayload['first_name'] = (string) $chatInfo['first_name'];
                }
                if (!empty($chatInfo['last_name'])) {
                    $userPayload['last_name'] = (string) $chatInfo['last_name'];
                }
                if (!empty($chatInfo['username'])) {
                    $userPayload['username'] = (string) $chatInfo['username'];
                }
            } catch (\Throwable) {
            }
        }

        $result = $this->users->registerFromMessenger($this->platform, $userPayload, $payload, [
            'ip' => Request::ip(),
            'source_platform' => $this->platform,
        ]);
        $user = $result['user'] ?? null;
        if ($user === null) {
            $this->send($upd->chatId, 'خطا در ثبت‌نام. لطفاً دوباره تلاش کنید.');
            return;
        }

        // اگر ورود با توکن یک‌بارمصرف اتصال حساب انجام شد (از جمله تأیید کانال میان‌پیام‌رسانی)
        if (!empty($result['linked'])) {
            $this->clearUserSession($user);
            $this->completePostLinkVerification($user, $upd->chatId, (string) ($result['link_message'] ?? '✅ حساب شما با موفقیت متصل شد.'));
            return;
        }

        // اتصال پیام ورودی /start به شناسه کاربر
        try {
            $this->db->run(
                'UPDATE telegram_messages SET user_id = :u WHERE chat_id = :c AND user_id IS NULL',
                ['u' => (int) $user['id'], 'c' => $upd->chatId]
            );
        } catch (\Throwable) {
        }

        // ثبت تلاش خودمعرفی در گزارش امنیتی
        if ($payload !== null && trim($payload) !== '' && !str_starts_with(strtoupper(trim($payload)), 'LNK')) {
            $code = $this->users->normalizeCode($payload);
            $referrer = $this->users->findByCode($code);
            if ($referrer !== null && (int) $referrer['id'] === (int) $user['id']) {
                SecurityService::log('self_referral_attempt', 'medium', [
                    'platform' => $this->platform,
                    'payload' => $payload,
                ], (int) $user['id']);
            }
        }

        $this->clearUserSession($user);

        $fresh = $this->users->find((int) $user['id']) ?? $user;
        $isReferredStart = $isNew && !empty($fresh['referred_by']);
        $settings = CommissionSettings::current($this->db, true);

        // بررسی دروازه‌های اجباری ورود متناسب با پیام‌رسان
        $membership = new MembershipService($this->db);
        $gate = $membership->gate($fresh, $this->platform, $upd->externalUserId);
        $fresh = $this->users->find((int) $fresh['id']) ?? $fresh;

        if (!$gate['passed']) {
            if ($isReferredStart) {
                $this->driver->sendMessage($upd->chatId, $this->referredStartText($fresh, $settings));
            }
            $this->sendMembershipGate($fresh, $upd->chatId, $gate);
            Notifier::flushDeferred();
            return;
        }

        $this->users->maybeQualify($fresh, false, $this->platform);
        Notifier::flushDeferred();
        $user = $this->users->find((int) $fresh['id']) ?? $fresh;

        $welcomeMessage = $isReferredStart
            ? $this->referredStartText($user, $settings)
            : $this->startText($isNew, $user);

        $this->sendMainMenu($user, $upd->chatId, $welcomeMessage);

        if ($isReferredStart && empty($user['qualified_at'])) {
            $this->sendPendingConditionActions($user, $upd->chatId, $settings);
        }
    }

    private function cmdAdmin(int $telegramId, int|string $chatId): void
    {
        $admin = $this->db->first(
            'SELECT id, username, name FROM admins WHERE telegram_user_id = :t AND is_active = 1 LIMIT 1',
            ['t' => $telegramId]
        );
        if ($admin === null) {
            $this->send($chatId, 'دستور نامعتبر است. برای دیدن منو /menu را بزنید.');
            return;
        }
        $rows = [
            [['text' => '📊 آمار سریع', 'callback_data' => 'admin:stats']],
            [['text' => '🛠 ورود به پنل مدیریت', 'callback_data' => 'admin:panel']],
        ];
        $this->driver->sendMessage($chatId, "🛠 <b>پنل مدیریت</b>\n\n"
            . 'خوش آمدید' . (!empty($admin['name']) ? ' ' . e((string) $admin['name']) : '') . ".\n"
            . 'گزینه مورد نظر را انتخاب کنید:', ['inline_rows' => $rows]);
    }

    private function startText(bool $isNew, array $user): string
    {
        $name = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?: 'دوست عزیز';
        $key = $isNew ? 'start_message_new' : 'start_message_returning';
        $default = $isNew
            ? "سلام {name} عزیز 👋\nبه <b>{app_name}</b> خوش آمدید!\n\nبا دعوت دوستان خود درآمد نقدی کسب کنید:\n• پاداش دعوت مستقیم (لایه ۰): <b>{inviter_reward}</b>\n• هدیه خوش‌آمدگویی دوست دعوت‌شده: <b>{invitee_reward}</b>\n• درآمد غیرمستقیم تا {max_levels} لایه\n\nاز منوی زیر شروع کنید 👇"
            : "سلام {name} عزیز 👋\nبه <b>{app_name}</b> خوش برگشتید.\n\nاز منوی زیر بخش مورد نظر را انتخاب کنید 👇";
        $template = (string) SettingsService::get($key, $default);

        $cs = CommissionSettings::current($this->db);
        $levelProfile = (new \RB\Services\UserLevelService($this->db))->profile((int) ($user['id'] ?? 0));
        $directReward = $cs->directEnabled ? (int) ($levelProfile['next_invite_reward'] ?? $cs->effectiveDirectReward()) : 0;
        $text = str_replace(
            [
                '{name}', '{app_name}', '{username}', '{max_levels}', '{min_withdraw}',
                '{direct_reward}', '{inviter_reward}', '{invitee_reward}', '{welcome_reward}',
                '{messenger}', '[پاداش دعوت‌کننده]', '[پاداش دعوت‌شده]',
            ],
            [
                e($name),
                e((string) SettingsService::get('app_name', 'رفرال‌بات')),
                !empty($user['username']) ? '@' . e((string) $user['username']) : e($name),
                fa_digits($cs->maxLevels),
                wtoman(SettingsService::int('min_withdraw_amount', 30000)),
                wtoman($directReward),
                wtoman($directReward),
                wtoman($cs->effectiveInviteeReward()),
                wtoman($cs->effectiveInviteeReward()),
                e(MessengerCapabilities::label($this->platform)),
                wtoman($directReward),
                wtoman($cs->effectiveInviteeReward()),
            ],
            $template
        );

        if (SettingsService::bool('show_rules_in_start', true)) {
            $rules = trim((string) SettingsService::get('start_rules_text', ''));
            if ($rules !== '') {
                $text .= "\n\n" . $rules;
            }
        }
        return $text;
    }

    private function referredStartText(array $user, ?CommissionSettings $settings = null): string
    {
        $settings ??= CommissionSettings::current($this->db);
        $text = ReferralMessageService::renderReferredWelcomeMessage($user, null, $settings, $this->db, $this->platform);
        if (SettingsService::bool('show_rules_in_start', true)) {
            $rules = trim((string) SettingsService::get('start_rules_text', ''));
            if ($rules !== '') {
                $text .= "\n\n" . $rules;
            }
        }
        return $text;
    }

    private function channelJoinUrl(array $channel): ?string
    {
        if (!empty($channel['invite_link'])) {
            return (string) $channel['invite_link'];
        }
        $chatId = trim((string) ($channel['chat_id'] ?? ''));
        $slug = ltrim((string) ($channel['username'] ?? ($chatId !== '' && str_starts_with($chatId, '@') ? $chatId : '')), '@');
        if ($slug === '') {
            return null;
        }
        $p = strtolower((string) ($channel['platform'] ?? $this->platform));
        return match ($p) {
            MessengerCapabilities::BALE   => 'https://ble.ir/' . $slug,
            MessengerCapabilities::RUBIKA => 'https://rubika.ir/' . $slug,
            MessengerCapabilities::EITAA  => 'https://eitaa.com/' . $slug,
            default                       => 'https://t.me/' . $slug,
        };
    }

    private function sendPendingConditionActions(array $user, int|string $chatId, ?CommissionSettings $settings = null): void
    {
        $settings ??= CommissionSettings::current($this->db);
        $pending = $this->users->pendingRequirements($user, $settings);
        if ($pending === []) {
            return;
        }
        $rows = [];
        $membership = new MembershipService($this->db);
        $supportsCheck = MessengerCapabilities::supports($this->platform, 'channel_check_api');

        foreach ($pending as $item) {
            $key = (string) ($item['key'] ?? '');
            if ($key === 'channels') {
                foreach ($membership->activeChannels($this->platform) as $channel) {
                    $url = $this->channelJoinUrl($channel);
                    if ($url !== null) {
                        $rows[] = [['text' => '📢 عضویت در ' . $channel['title'], 'url' => $url]];
                    }
                }
                $cb = $supportsCheck ? 'gate:check' : 'gate:self_confirm';
                $lbl = $supportsCheck ? '✅ بررسی عضویت کانال و فعال‌سازی پاداش' : '✅ عضو شدم — ادامه و فعال‌سازی';
                $rows[] = [['text' => $lbl, 'callback_data' => $cb]];
            } elseif ($key === 'phone' && MessengerCapabilities::supports($this->platform, 'request_contact')) {
                $rows[] = [['text' => '📱 ثبت و تأیید شماره تماس', 'callback_data' => 'qualify:phone']];
            } elseif ($key === 'profile') {
                $rows[] = [['text' => '✏️ تکمیل نام و نام خانوادگی پروفایل', 'callback_data' => 'qualify:profile']];
            }
        }
        if ($rows === []) {
            return;
        }
        $inviteeReward = $settings->effectiveInviteeReward();
        $prompt = $inviteeReward > 0
            ? '👇 برای تکمیل مراحل و دریافت <b>' . wtoman($inviteeReward) . '</b> پاداش خوش‌آمدگویی، از گزینه‌های زیر استفاده کنید:'
            : '👇 برای تکمیل مراحل ثبت‌نام از گزینه‌های زیر استفاده کنید:';
        $this->driver->sendMessage($chatId, $prompt, ['inline_rows' => $rows]);
    }

    // ------------------------------------------------------------------ گیت‌های ورود و شماره تماس

    private function passGates(array $user, int|string $chatId, ?string $externalUserId = null): bool
    {
        $wasVerified = !empty($user['channels_verified']);
        $membership = new MembershipService($this->db);
        $gate = $membership->gate($user, $this->platform, $externalUserId);
        if (!$gate['passed']) {
            $this->sendMembershipGate($user, $chatId, $gate);
            return false;
        }
        if (!$wasVerified) {
            $fresh = $this->users->find((int) $user['id']);
            if ($fresh !== null && !empty($fresh['channels_verified'])) {
                $this->users->maybeQualify($fresh, false, $this->platform);
                Notifier::flushDeferred();
            }
        }
        return true;
    }

    private function sendMembershipGate(array $user, int|string $chatId, array $gate): void
    {
        if ($gate['reason'] === 'contact') {
            $text = (string) SettingsService::get('contact_request_message', 'برای ادامه، لطفاً شماره تماس خود را با دکمه زیر ارسال کنید.');
            $this->driver->sendMessage($chatId, $text, [
                'reply_rows' => [[['text' => '📱 ارسال شماره تماس', 'request_contact' => true]]],
            ]);
            return;
        }

        if ($gate['reason'] === 'channels') {
            $rows = [];
            $crossNotes = [];
            $membership = new MembershipService($this->db);

            foreach ($gate['channels'] as $channel) {
                $url = $this->channelJoinUrl($channel) ?? MembershipService::channelUrl($channel);
                $isCross = !empty($channel['is_cross_platform']);
                $hostBadge = (string) ($channel['host_badge'] ?? '');
                $btnLabel = $isCross && $hostBadge !== ''
                    ? ('📢 ' . $channel['title'] . ' (' . $hostBadge . ')')
                    : ('📢 ' . $channel['title']);
                if ($url !== null) {
                    $rows[] = [['text' => $btnLabel, 'url' => $url]];
                }
                if ($isCross) {
                    $customNote = trim((string) ($channel['cross_rule_note'] ?? ''));
                    if ($customNote !== '') {
                        $crossNotes[] = '💡 ' . e($customNote);
                    }
                }
            }

            // اگر کاربر برای تأیید واقعی عضویت در کانال میان‌پیام‌رسانی نیاز به اتصال یک‌کلیکی حساب پیام‌رسان مقصد دارد
            $linkInstructions = [];
            $needsLink = (array) ($gate['needs_link_platforms'] ?? []);
            if ($needsLink !== [] && !empty($user['id'])) {
                foreach ($needsLink as $targetPlat) {
                    $prompt = $membership->buildCrossPlatformLinkPrompt((int) $user['id'], (string) $targetPlat);
                    if ($prompt['deep_link'] !== '') {
                        $rows[] = [[
                            'text' => '🔗 اتصال یک‌کلیکی حساب ' . $prompt['badge'],
                            'url' => $prompt['deep_link'],
                        ]];
                        $linkInstructions[] = '🔐 برای تأیید واقعی عضویت در کانال ' . e($prompt['badge'])
                            . '، ابتدا دکمه «اتصال یک‌کلیکی حساب ' . e($prompt['badge']) . '» را بزنید'
                            . ' (یا کد <code>' . e($prompt['code']) . '</code> را در ربات @' . e($prompt['bot_username']) . ' ارسال کنید)،'
                            . ' در کانال عضو شوید و سپس «✅ بررسی عضویت» را لمس کنید.';
                    } elseif ($prompt['code'] !== '') {
                        $linkInstructions[] = '🔐 برای تأیید واقعی عضویت در کانال ' . e($prompt['badge'])
                            . '، ابتدا دستور <code>/link ' . e($prompt['code']) . '</code> را در ربات ' . e($prompt['label'])
                            . ' ارسال کنید، در کانال عضو شوید و سپس «✅ بررسی عضویت» را بزنید.';
                    }
                }
            }

            $hasApi = !empty($gate['has_api_channels']);
            $hasSelf = !empty($gate['has_self_channels']);
            $onlySelf = !empty($gate['self_confirm']) || ($hasSelf && !$hasApi);

            if ($onlySelf) {
                $rows[] = [['text' => '✅ عضو کانال شدم — ادامه', 'callback_data' => 'gate:self_confirm']];
            } elseif ($hasSelf && $hasApi) {
                $rows[] = [['text' => '☑️ عضو کانال ' . MessengerCapabilities::label($this->platform) . ' شدم', 'callback_data' => 'gate:self_confirm']];
                $rows[] = [['text' => '✅ بررسی عضویت در همه کانال‌ها', 'callback_data' => 'gate:check']];
            } else {
                $rows[] = [['text' => '✅ بررسی عضویت', 'callback_data' => 'gate:check']];
            }

            $message = (string) SettingsService::get('join_channels_message', 'برای استفاده از ربات ابتدا در کانال‌های زیر عضو شوید:');
            if ($crossNotes !== []) {
                $message .= "\n\n" . implode("\n", array_values(array_unique($crossNotes)));
            }
            if ($linkInstructions !== []) {
                $message .= "\n\n" . implode("\n\n", $linkInstructions);
            }
            if (!empty($gate['errors'])) {
                $message .= "\n\n⚠️ " . implode("\n⚠️ ", $gate['errors']);
            }
            $this->driver->sendMessage($chatId, $message, ['inline_rows' => $rows]);
        }
    }

    /** بررسی خودکار کانال‌های میان‌پیام‌رسانی بلافاصله پس از اتصال موفق حساب کاربر و آزادسازی پاداش در صورت تکمیل شرایط */
    private function completePostLinkVerification(array $user, int|string $chatId, string $baseMessage): void
    {
        $entryPlat = strtolower((string) ($user['entry_platform'] ?? $this->platform));
        $membership = new MembershipService($this->db);

        if ($membership->enforcementEnabled() && empty($user['channels_verified'])) {
            $gate = $membership->gate($user, $entryPlat);
            if ($gate['passed']) {
                $fresh = $this->users->find((int) $user['id']) ?? $user;
                $qualified = $this->users->maybeQualify($fresh, false, $entryPlat);
                Notifier::flushDeferred();
                $fresh = $this->users->find((int) $user['id']) ?? $fresh;
                $extra = "\n\n✅ عضویت شما در تمام کانال‌های الزامی نیز به‌صورت خودکار تأیید شد"
                    . ($qualified ? ' و پاداش دعوت فعال گردید! 🎁' : '.');
                $this->sendMainMenu($fresh, $chatId, $baseMessage . $extra);
                return;
            }
            if (($gate['reason'] ?? '') === 'channels' && !empty($gate['channels'])) {
                $this->send($chatId, $baseMessage . "\n\n📢 برای تکمیل نهایی، لطفاً در کانال الزامی زیر عضو شوید و دکمه «✅ بررسی عضویت» را بزنید:");
                $this->sendMembershipGate($user, $chatId, $gate);
                return;
            }
        }

        $this->sendMainMenu($user, $chatId, $baseMessage);
    }

    private function handleContact(array $user, IncomingUpdate $upd): void
    {
        // در تلگرام و بله تطبیق دقیق contact.user_id با فرستنده الزامی است (ضد ارسال مخاطب دفترچه تلفن)
        if ($this->platform === MessengerCapabilities::TELEGRAM) {
            if ($upd->contactUserId === null || $upd->contactUserId === '') {
                SecurityService::log('contact_without_user_id', 'medium', ['platform' => $this->platform], (int) $user['id']);
                $this->send($upd->chatId, '❌ لطفاً با دکمهٔ «ارسال شماره تماس» شمارهٔ خودتان را ارسال کنید، نه از دفترچه تلفن.');
                return;
            }
            if ((string) $upd->contactUserId !== (string) $upd->externalUserId) {
                SecurityService::log('contact_mismatch', 'high', [
                    'platform' => $this->platform,
                    'contact_user_id' => $upd->contactUserId,
                ], (int) $user['id']);
                $this->send($upd->chatId, '❌ این شماره متعلق به حساب تلگرام شما نیست. لطفاً با دکمه «ارسال شماره تماس» شماره خودتان را ارسال کنید.');
                return;
            }
        } elseif ($this->platform === MessengerCapabilities::BALE && $upd->contactUserId !== null && $upd->contactUserId !== '') {
            if ((string) $upd->contactUserId !== (string) $upd->externalUserId) {
                SecurityService::log('contact_mismatch', 'high', ['platform' => 'bale'], (int) $user['id']);
                $this->send($upd->chatId, '❌ این شماره متعلق به حساب بله شما نیست.');
                return;
            }
        }

        $phone = preg_replace('~[^0-9+]~', '', (string) $upd->contactPhone);
        if ($phone === null || strlen($phone) < 8) {
            $this->send($upd->chatId, 'شماره تماس نامعتبر است.');
            return;
        }
        $this->users->setPhone((int) $user['id'], $phone);
        Notifier::flushDeferred();
        $user = $this->users->find((int) $user['id']) ?? $user;

        $gate = (new MembershipService($this->db))->gate($user, $this->platform, $upd->externalUserId);
        $user = $this->users->find((int) $user['id']) ?? $user;
        if (!$gate['passed']) {
            $this->send($upd->chatId, '✅ شماره تماس شما با موفقیت تأیید و ثبت شد. لطفاً مرحله بعدی را تکمیل کنید:');
            $this->sendMembershipGate($user, $upd->chatId, $gate);
            return;
        }

        $this->users->maybeQualify($user, false, $this->platform);
        Notifier::flushDeferred();
        $user = $this->users->find((int) $user['id']) ?? $user;

        $this->sendMainMenu($user, $upd->chatId, "✅ شماره تماس شما با موفقیت ثبت شد.\n\nاز منوی زیر گزینه مورد نظر را انتخاب کنید:");
        if (!empty($user['referred_by']) && empty($user['qualified_at'])) {
            $this->sendPendingConditionActions($user, $upd->chatId);
        }
    }

    // ------------------------------------------------------------------ مدیریت نشست و مراحل گفتگو

    private function sessionKey(array $user): int
    {
        return (int) $user['telegram_id'];
    }

    private function getUserSession(array $user): ?array
    {
        $key = $this->sessionKey($user);
        $session = $this->session->get($key);
        if ($session !== null && (int) ($session['user_id'] ?? 0) !== (int) $user['id']) {
            $this->session->clear($key);
            return null;
        }
        return $session;
    }

    private function setUserStep(array $user, string $step, array $payload = [], int $ttl = 900): void
    {
        $this->session->setStep($this->sessionKey($user), (int) $user['id'], $step, $payload, $ttl);
    }

    private function clearUserSession(array $user): void
    {
        $this->session->clear($this->sessionKey($user));
    }

    private function handleStep(array $user, array $session, string $text, int|string $chatId, IncomingUpdate $upd): bool
    {
        $step = (string) $session['step'];
        $payload = (array) ($session['payload'] ?? []);

        switch ($step) {
            case 'withdraw_amount':
                $amount = Money::parseToman($text);
                $min = (int) ($payload['min'] ?? SettingsService::int('min_withdraw_amount', 30000));
                if ($amount === null || $amount <= 0) {
                    $this->send($chatId, '❌ مبلغ نامعتبر است. لطفاً فقط عدد وارد کنید (یا /cancel برای انصراف).');
                    return true;
                }
                if ($amount < $min) {
                    $this->send($chatId, '❌ حداقل مبلغ برداشت ' . wtoman($min) . ' است.');
                    return true;
                }
                if ($amount > (int) $user['wallet_balance']) {
                    $this->send($chatId, '❌ مبلغ درخواستی بیشتر از موجودی قابل برداشت شما (' . wtoman((int) $user['wallet_balance']) . ') است.');
                    return true;
                }
                $this->setUserStep($user, 'withdraw_card', ['amount' => $amount, 'min' => $min]);
                $this->send(
                    $chatId,
                    "💳 لطفاً <b>شماره کارت بانکی</b> خود را ارسال کنید:\n"
                    . "<i>شماره همان‌طور که ارسال می‌کنید برای بررسی مدیر ثبت می‌شود. برای انصراف /cancel را بفرستید.</i>"
                );
                return true;

            case 'withdraw_card':
                $validation = WithdrawalService::validateCard($upd->text);
                if (!$validation['valid']) {
                    $this->send(
                        $chatId,
                        '❌ ' . $validation['error'] . "\n<i>(برای انصراف /cancel را ارسال کنید)</i>"
                    );
                    return true;
                }
                $this->setUserStep($user, 'withdraw_holder', [
                    'amount' => (int) ($payload['amount'] ?? 0),
                    'card_enc' => Crypto::encrypt($validation['normalized']),
                    'min' => $payload['min'] ?? 0,
                ]);
                $this->send(
                    $chatId,
                    "✅ شماره کارت دریافت شد و مدیر هنگام پرداخت آن را بررسی می‌کند."
                    . "\n\n👤 لطفاً <b>نام و نام خانوادگی صاحب کارت</b> را ارسال کنید:"
                );
                return true;

            case 'withdraw_holder':
                $holder = trim((string) (preg_replace('~\s+~u', ' ', $text) ?? $text));
                if (mb_strlen($holder) < 3) {
                    $this->send($chatId, '❌ نام و نام خانوادگی صاحب کارت باید حداقل ۳ حرف باشد.');
                    return true;
                }
                $service = new WithdrawalService($this->db);
                try {
                    $request = $service->create((int) $user['id'], (int) $payload['amount'], (string) (isset($payload['card_enc']) ? Crypto::decrypt((string) $payload['card_enc']) : ($payload['card'] ?? '')), $holder, Request::ip());
                    $this->clearUserSession($user);
                    $info = $service->statusLabel((string) $request['status']);
                    $this->sendMainMenu($user, $chatId, "✅ درخواست برداشت شما ثبت شد.\n\n"
                        . 'مبلغ: <b>' . wtoman((int) $request['amount']) . "</b>\n"
                        . 'کد پیگیری: <code>' . $request['request_code'] . "</code>\n"
                        . 'وضعیت: ' . $info . "\n\n"
                        . 'مبلغ تا زمان پردازش در حساب شما رزرو شده است و پس از تأیید مدیریت پرداخت می‌شود.');
                } catch (\Throwable $e) {
                    $this->clearUserSession($user);
                    $this->sendMainMenu($user, $chatId, '❌ ' . $e->getMessage());
                }
                return true;

            case 'support_message':
                if (mb_strlen(trim($text)) < 5) {
                    $this->send($chatId, 'متن پیام باید حداقل ۵ کاراکتر باشد.');
                    return true;
                }
                SupportService::create((int) $user['id'], trim($text));
                $this->clearUserSession($user);
                $this->send($chatId, "✅ پیام شما ثبت شد.\nتیم پشتیبانی در اسرع وقت پاسخ می‌دهد.");
                return true;

            case 'complete_profile_name':
                $cleaned = trim((string) preg_replace('~\s+~u', ' ', $text));
                $parts = explode(' ', $cleaned, 2);
                if (count($parts) < 2 || mb_strlen($parts[0]) < 2 || mb_strlen(trim($parts[1])) < 2) {
                    $this->send($chatId, '❌ لطفاً <b>نام و نام خانوادگی</b> خود را کامل و با یک فاصله وارد کنید (مثال: <code>علی رضایی</code>):');
                    return true;
                }
                $this->users->updateProfile((int) $user['id'], $parts[0], trim($parts[1]));
                Notifier::flushDeferred();
                $this->clearUserSession($user);
                $fresh = $this->users->find((int) $user['id']) ?? $user;
                $this->sendMainMenu($fresh, $chatId, '✅ پروفایل شما (' . e($parts[0] . ' ' . trim($parts[1])) . ') با موفقیت تکمیل و ذخیره شد.');
                if (!empty($fresh['referred_by']) && empty($fresh['qualified_at'])) {
                    $this->sendPendingConditionActions($fresh, $chatId);
                }
                return true;

            case 'enter_link_code':
                $res = $this->users->redeemLinkToken($text, $this->platform, $upd->userPayload());
                $this->clearUserSession($user);
                if ($res['ok'] && isset($res['user'])) {
                    $this->completePostLinkVerification($res['user'], $chatId, (string) $res['message']);
                } else {
                    $this->send($chatId, '❌ ' . $res['message']);
                }
                return true;
        }
        return false;
    }

    // ------------------------------------------------------------------ اکشن‌های منو

    private function dispatchAction(array $user, string $action, int|string $chatId): void
    {
        switch ($action) {
            case 'account':       $this->actAccount($user, $chatId); break;
            case 'invite':        $this->actInvite($user, $chatId); break;
            case 'referrals':     $this->actReferrals($user, $chatId); break;
            case 'income':        $this->actIncome($user, $chatId); break;
            case 'wallet':        $this->actWallet($user, $chatId); break;
            case 'withdraw':      $this->actWithdraw($user, $chatId); break;
            case 'history':       $this->actHistory($user, $chatId); break;
            case 'link_account':  $this->actLinkAccount($user, $chatId); break;
            case 'campaigns':     $this->actCampaigns($user, $chatId); break;
            case 'ranking':       $this->actRanking($user, $chatId); break;
            case 'notifications': $this->actNotifications($user, $chatId); break;
            case 'support':       $this->actSupport($user, $chatId); break;
            case 'guide':         $this->sendGuide($chatId); break;
            default:
                $this->send($chatId, 'این بخش در حال حاضر فعال نیست.');
        }
    }

    private function actAccount(array $user, int|string $chatId): void
    {
        $wallet = (new WalletService($this->db))->summary((int) $user['id']);
        $accounts = $this->users->messengerAccounts((int) $user['id']);
        $linkedBadges = array_map(static fn(array $a): string => MessengerCapabilities::badge((string) $a['platform']), $accounts);
        $currentAcc = $this->users->messengerAccountForPlatform((int) $user['id'], $this->platform);
        $extIdDisplay = $currentAcc['external_user_id'] ?? ((int) $user['telegram_id'] > 0 ? (string) $user['telegram_id'] : '—');
        $direct = (int) $user['direct_ref_count'];
        $indirect = max(0, (int) $user['total_ref_count'] - $direct);

        $text = "👤 <b>حساب کاربری مرکزی من</b>\n\n"
            . 'نام: ' . e(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: '—') . "\n"
            . 'پیام‌رسان فعلی: ' . e(MessengerCapabilities::badge($this->platform)) . ' (<code>' . e((string) $extIdDisplay) . "</code>)\n"
            . 'پیام‌رسان‌های متصل: ' . e($linkedBadges ? implode('، ', $linkedBadges) : MessengerCapabilities::badge($this->platform)) . "\n"
            . 'نام کاربری: ' . (!empty($user['username']) ? '@' . e((string) $user['username']) : '—') . "\n"
            . 'کد دعوت یکتا: <code>' . e((string) $user['referral_code']) . "</code>\n"
            . 'وضعیت حساب: ' . ($user['status'] === 'active' ? '✅ فعال' : ($user['status'] === 'blocked' ? '⛔️ مسدود' : '⚠️ در بررسی')) . "\n"
            . 'تاریخ عضویت: ' . fa_date($user['joined_at']) . "\n"
            . 'شماره تماس: ' . (!empty($user['phone']) ? e((string) $user['phone']) : 'ثبت نشده') . "\n\n"
            . '👥 دعوت مستقیم (لایه ۰): <b>' . fa_digits($direct) . " نفر</b>\n"
            . '🌐 زیرمجموعه غیرمستقیم (لایه‌های ۱+): <b>' . fa_digits($indirect) . " نفر</b>\n"
            . '💰 مجموع درآمد: <b>' . wtoman($wallet['total_earned']) . "</b>\n"
            . '👛 موجودی قابل برداشت: <b>' . wtoman($wallet['balance']) . '</b>';

        $userLevel = (new \RB\Services\UserLevelService($this->db))->profile((int) $user['id']);
        if ($userLevel !== null) {
            $current = $userLevel['current'];
            $next = $userLevel['next'];
            $text .= "\n\n🏅 سطح: " . e(($current['icon'] ?? '🌱') . ' ' . ($current['name'] ?? 'شروع‌کننده'));
            if (!empty($current['badge'])) $text .= ' · ' . e($current['badge']);
            $text .= "\nدعوت معتبر: " . fa_digits($userLevel['count']);
            if ($next) $text .= "\nتا سطح " . e($next['name']) . ': ' . fa_digits(max(0, (int) $next['min_invites'] - $userLevel['count'])) . ' دعوت';
            if (!empty($current['benefits'])) $text .= "\nمزایا: " . e($current['benefits']);
        }

        $this->driver->sendMessage($chatId, $text, [
            'inline_rows' => [
                [['text' => '🔄 اتصال سایر پیام‌رسان‌ها به همین کیف پول', 'callback_data' => 'menu:link_account']],
            ],
        ]);
    }

    // ------------------------------------------------------------------ استودیو ۴ حالته دعوت دوستان و بنر تبلیغاتی

    private function actInvite(array $user, int|string $chatId, int $msgId = 0): void
    {
        $link = $this->referralLink($user, $this->platform);
        $settings = CommissionSettings::current($this->db);
        $messages = $this->inviteContent->listMessages(true);
        $banners = $this->inviteContent->listBanners(true);

        $selected = null;
        if ($msgId > 0) {
            foreach ($messages as $m) {
                if ((int) $m['id'] === $msgId) {
                    $selected = $m;
                    break;
                }
            }
        }
        $selected ??= ($messages[0] ?? null);

        // اگر مدیر در تنظیمات عمومی پیام سفارشی ثبت کرده و کاربر قالب خاصی نخواسته، آن را رعایت کن
        $customLegacy = trim((string) (SettingsService::get('invite_message_text', '') ?? ''));
        if ($msgId === 0 && $customLegacy !== '') {
            $text = ReferralMessageService::renderInviteMessage($user, $link, $settings, $this->db);
        } elseif ($selected !== null) {
            $text = $this->inviteContent->renderMessage($selected, $user, $link, $this->platform, true, $settings);
        } else {
            $text = ReferralMessageService::renderInviteMessage($user, $link, $settings, $this->db);
        }

        $rows = [];

        // دکمه اشتراک‌گذاری بومی تلگرام و دکمه کپی لینک (تلگرام و بله)
        if ($this->platform === MessengerCapabilities::TELEGRAM && str_starts_with($link, 'https://')) {
            $sharePlain = $selected !== null
                ? trim(str_replace($link, '', $this->inviteContent->renderMessage($selected, $user, $link, $this->platform, false, $settings)))
                : ReferralMessageService::renderShareText($user, $link, $settings, $this->db);
            $shareUrl = 'https://t.me/share/url?url=' . rawurlencode($link) . '&text=' . rawurlencode(mb_substr($sharePlain, 0, 850));
            $rows[] = [['text' => '📤 ارسال سریع متن دعوت به دوستان', 'url' => $shareUrl]];
        }
        if (MessengerCapabilities::supports($this->platform, 'copy_text_button') && str_starts_with($link, 'https://')) {
            $rows[] = [['text' => '📋 کپی لینک اختصاصی دعوت', 'copy_text' => ['text' => $link]]];
        }

        // ۴ حالت اشتراک‌گذاری: بنر + کپشن، بنر تکی، تعویض متن آماده، و لینک مستقیم همه پیام‌رسان‌ها
        if ($banners !== []) {
            $defBannerId = (int) $banners[0]['id'];
            $rows[] = [
                ['text' => '🖼 بنر + کپشن دعوت', 'callback_data' => 'invite:bcap:' . $defBannerId],
                ['text' => '🎨 بنر تبلیغاتی', 'callback_data' => 'invite:bimg:' . $defBannerId],
            ];
        }
        if (count($messages) > 1) {
            $nextMsg = $this->nextItem($messages, (int) ($selected['id'] ?? 0));
            $rows[] = [
                ['text' => '📝 پیام آماده بعدی (' . fa_digits(count($messages)) . ' قالب)', 'callback_data' => 'invite:msg:' . (int) $nextMsg['id']],
                ['text' => '🔗 لینک مستقیم پیام‌رسان‌ها', 'callback_data' => 'invite:links'],
            ];
        } else {
            $rows[] = [['text' => '🔗 لینک مستقیم دعوت در پیام‌رسان‌ها', 'callback_data' => 'invite:links']];
        }

        $this->driver->sendMessage($chatId, $text, ['inline_rows' => $rows]);
    }

    /** ارسال بنر تبلیغاتی دعوت (با کپشن کامل یا با لینک کوتاه یکتا) + کش هوشمند file_id */
    public function sendInviteBannerToUser(array $user, int|string $chatId, int $bannerId = 0, bool $withFullCaption = true): array
    {
        $banners = array_values(array_filter(
            $this->inviteContent->listBanners(true),
            static fn(array $b): bool => !empty($b['file_exists'])
        ));
        if ($banners === []) {
            $this->send($chatId, 'در حال حاضر بنر تبلیغاتی فعالی تعریف نشده است؛ می‌توانید از پیام‌های آماده دعوت استفاده کنید.');
            return ['ok' => false, 'error' => 'بنری فعال نیست'];
        }

        $banner = null;
        foreach ($banners as $b) {
            if ((int) $b['id'] === $bannerId) {
                $banner = $b;
                break;
            }
        }
        $banner ??= $banners[0];

        $filePath = $this->inviteContent->bannerFilePath($banner);
        if ($filePath === null) {
            return ['ok' => false, 'error' => 'فایل بنر یافت نشد'];
        }

        $link = $this->referralLink($user, $this->platform);
        $caption = $this->inviteContent->renderBannerCaption($banner, $user, $link, $this->platform, $withFullCaption, true);
        $cachedFileId = $this->inviteContent->cachedFileId($banner, $this->platform);

        $rows = [];
        if (MessengerCapabilities::supports($this->platform, 'copy_text_button') && str_starts_with($link, 'https://')) {
            $rows[] = [['text' => '📋 کپی لینک دعوت', 'copy_text' => ['text' => $link]]];
        }
        if (count($banners) > 1) {
            $next = $this->nextItem($banners, (int) $banner['id']);
            $modeKey = $withFullCaption ? 'bcap' : 'bimg';
            $rows[] = [['text' => '🎨 طرح بنر بعدی (' . fa_digits(count($banners)) . ' طرح)', 'callback_data' => 'invite:' . $modeKey . ':' . (int) $next['id']]];
        }
        $toggleMode = $withFullCaption ? 'bimg' : 'bcap';
        $toggleLabel = $withFullCaption ? '🖼 دریافت بدون متن طولانی' : '📝 دریافت با کپشن کامل';
        $rows[] = [
            ['text' => $toggleLabel, 'callback_data' => 'invite:' . $toggleMode . ':' . (int) $banner['id']],
            ['text' => '↩️ پیام‌های دعوت', 'callback_data' => 'menu:invite'],
        ];

        $res = $this->driver->sendPhoto($chatId, $filePath, $caption, [
            'file_id' => $cachedFileId,
            'inline_rows' => $rows,
        ]);

        // اگر file_id کش‌شده قدیمی بود، بدون کش دوباره بفرست
        if (empty($res['ok']) && $cachedFileId !== null) {
            $res = $this->driver->sendPhoto($chatId, $filePath, $caption, ['inline_rows' => $rows]);
        }

        if (!empty($res['ok'])) {
            $this->inviteContent->recordShare('banner', (int) $banner['id']);
            if (!empty($res['file_id']) && $res['file_id'] !== $cachedFileId) {
                $this->inviteContent->rememberFileId((int) $banner['id'], $this->platform, (string) $res['file_id']);
            }
        }
        return $res;
    }

    /** نمایش لینک‌های مستقیم و یکتای دعوت کاربر در تمام پیام‌رسان‌های فعال */
    private function actInviteLinks(array $user, int|string $chatId): void
    {
        $code = (string) $user['referral_code'];
        $links = $this->registry->allReferralLinksForCode($code, true, $this->platform);
        $landing = $this->registry->buildLandingLink($code, $this->platform);

        $lines = [
            "🔗 <b>لینک‌های اختصاصی و یکتای دعوت شما</b>",
            '🔑 کد معرفی مشترک در همه پیام‌رسان‌ها: <code>' . e($code) . '</code>',
            '',
        ];
        foreach ($links as $item) {
            $lines[] = $item['icon'] . ' <b>' . e($item['name']) . ':</b>';
            $lines[] = '<code>' . e($item['link']) . '</code>';
            $lines[] = '';
        }
        $lines[] = '🌐 <b>لینک هوشمند انتخاب پیام‌رسان (مناسب بیو و استوری):</b>';
        $lines[] = '<code>' . e($landing) . '</code>';
        $lines[] = "\n💡 هر دوستی با هر کدام از لینک‌های بالا عضو شود، پاداش آن مستقیماً به کیف پول واحد شما واریز می‌گردد.";

        $rows = [];
        $primary = $links[$this->platform]['link'] ?? $landing;
        if (MessengerCapabilities::supports($this->platform, 'copy_text_button') && str_starts_with($primary, 'https://')) {
            $rows[] = [['text' => '📋 کپی لینک ' . MessengerCapabilities::label($this->platform), 'copy_text' => ['text' => $primary]]];
        }
        $rows[] = [['text' => '↩️ بازگشت به پیام و بنر دعوت', 'callback_data' => 'menu:invite']];

        $this->driver->sendMessage($chatId, implode("\n", $lines), ['inline_rows' => $rows]);
    }

    private function nextItem(array $items, int $currentId): array
    {
        $count = count($items);
        for ($i = 0; $i < $count; $i++) {
            if ((int) $items[$i]['id'] === $currentId) {
                return $items[($i + 1) % $count];
            }
        }
        return $items[0];
    }

    // ------------------------------------------------------------------ اتصال امن بین پیام‌رسان‌ها

    private function actLinkAccount(array $user, int|string $chatId, bool $generateNow = false): void
    {
        $accounts = $this->users->messengerAccounts((int) $user['id']);
        $lines = ["🔄 <b>اتصال امن حساب در پیام‌رسان‌ها</b>\n"];
        $lines[] = 'حساب‌های متصل به کیف پول شما:';
        foreach ($accounts as $acc) {
            $lines[] = '• ' . MessengerCapabilities::badge((string) $acc['platform']) . ' — <code>' . e((string) $acc['external_user_id']) . '</code> ✅';
        }
        $lines[] = '';

        $rows = [];
        if ($generateNow) {
            $tok = $this->users->createLinkToken((int) $user['id'], 900);
            $lines[] = '🔐 <b>کد اتصال یک‌بارمصرف شما (معتبر تا ۱۵ دقیقه):</b>';
            $lines[] = '<code>' . e($tok['code']) . '</code>';
            $lines[] = '';
            $lines[] = "برای اتصال حساب خود در پیام‌رسان دیگر (مثلاً تلگرام، بله، روبیکا یا ایتا)، ربات ما را در آن پیام‌رسان باز کرده و دستور زیر را ارسال کنید (یا در برنامک ایتا وارد نمایید):";
            $lines[] = '<code>/link ' . e($tok['code']) . '</code>';
            $lines[] = "\n⚠️ این کد محرمانه است و هرگز آن را در اختیار دیگران قرار ندهید.";

            if (MessengerCapabilities::supports($this->platform, 'copy_text_button')) {
                $rows[] = [['text' => '📋 کپی کد اتصال', 'copy_text' => ['text' => $tok['code']]]];
            }
        } else {
            $lines[] = 'اگر در پیام‌رسان‌های دیگر هم عضو ربات هستید، می‌توانید با کد تأیید یک‌بارمصرف آن‌ها را به همین کیف پول و کد دعوت یکتا متصل کنید.';
            $rows[] = [['text' => '🔑 دریافت کد اتصال یک‌بارمصرف', 'callback_data' => 'link:gen']];
        }
        $rows[] = [['text' => '📥 ثبت کد دریافت‌شده از پیام‌رسان دیگر', 'callback_data' => 'link:enter']];

        $this->driver->sendMessage($chatId, implode("\n", $lines), ['inline_rows' => $rows]);
    }

    // ------------------------------------------------------------------ زیرمجموعه‌ها و درآمد لایه‌ای شفاف

    private function actReferrals(array $user, int|string $chatId): void
    {
        $byLayer = $this->users->downlineByLayer((int) $user['id'], 8, 200);
        $directCount = (int) ($byLayer[0]['count'] ?? $user['direct_ref_count']);
        $indirectCount = 0;
        foreach ($byLayer as $lk => $info) {
            if ($lk >= 1) {
                $indirectCount += (int) $info['count'];
            }
        }

        $text = "👥 <b>زیرمجموعه‌های من (به تفکیک لایه)</b>\n\n"
            . '• لایه ۰ (دعوت مستقیم): <b>' . fa_digits($directCount) . " نفر</b>\n"
            . '• لایه‌های ۱+ (غیرمستقیم): <b>' . fa_digits($indirectCount) . " نفر</b>\n"
            . '• کل شبکه شما: <b>' . fa_digits($directCount + $indirectCount) . " نفر</b>\n";

        if ($byLayer !== []) {
            $text .= "\n📊 <b>آمار هر لایه:</b>\n";
            foreach ($byLayer as $lk => $info) {
                $text .= '▫️ ' . $info['label'] . ': <b>' . fa_digits($info['count']) . ' نفر</b>'
                    . ($info['earned'] > 0 ? ' — ' . wtoman($info['earned']) : '') . "\n";
            }
        }

        $direct = $this->users->directReferrals((int) $user['id'], 8);
        if (!$direct) {
            $text .= "\nهنوز دعوت مستقیمی ثبت نشده است؛ از بخش «دعوت و بنر تبلیغاتی» لینک خود را برای دوستانتان بفرستید.";
        } else {
            $text .= "\n<b>آخرین دعوت‌های مستقیم (لایه ۰):</b>\n";
            foreach ($direct as $ref) {
                $name = trim(($ref['first_name'] ?? '') . ' ' . ($ref['last_name'] ?? '')) ?: 'کاربر';
                $pIcon = MessengerCapabilities::icon((string) ($ref['entry_platform'] ?? 'telegram'));
                $qMark = !empty($ref['qualified_at']) ? '✅' : '⏳';
                $text .= $qMark . ' ' . $pIcon . ' ' . e($name) . ' — ' . fa_date($ref['joined_at'], false) . "\n";
            }
        }

        $this->driver->sendMessage($chatId, $text, ['inline_rows' => $this->panelInlineRows()]);
    }

    public function actIncome(array $user, int|string $chatId): void
    {
        $wallet = (new WalletService($this->db))->summary((int) $user['id']);
        $summary = $this->users->layerEarningsSummary((int) $user['id']);

        $lines = ["💰 <b>گزارش شفاف درآمد من</b>\n"];
        $lines[] = '• پاداش دعوت مستقیم (لایه ۰): <b>' . wtoman($wallet['direct_earned']) . '</b>';
        if (($wallet['welcome_earned'] ?? 0) > 0 || CommissionSettings::current($this->db)->inviteeEnabled) {
            $lines[] = '• پاداش خوش‌آمدگویی ورود: <b>' . wtoman((int) ($wallet['welcome_earned'] ?? 0)) . '</b>';
        }
        $lines[] = '• درآمد لایه‌های غیرمستقیم (لایه ۱+): <b>' . wtoman($wallet['layer_earned']) . '</b>';
        if ((int) ($wallet['campaign_earned'] ?? 0) > 0) {
            $lines[] = '• جوایز جشنواره‌ها: <b>' . wtoman((int) $wallet['campaign_earned']) . '</b>';
        }

        // تفکیک لایه‌های ۱ به بالا در صورت داشتن درآمد یا عضو
        $indirectLayers = array_filter($summary['layers'], static fn(array $l): bool => $l['layer'] >= 1 && ($l['total_amount'] > 0 || $l['users_count'] > 0));
        if ($indirectLayers !== []) {
            $lines[] = "\n📈 <b>تفکیک لایه‌های غیرمستقیم:</b>";
            foreach ($indirectLayers as $l) {
                $lines[] = '  ▫️ لایه ' . fa_digits($l['layer']) . ': ' . wtoman($l['paid_amount'])
                    . ' (' . fa_digits($l['users_count']) . ' نفر)'
                    . ($l['pending_amount'] > 0 ? ' + ' . wtoman($l['pending_amount']) . ' در انتظار' : '');
            }
        }

        // تفکیک پیام‌رسان‌ها در صورت فعالیت چندپیام‌رسانی
        if (count($summary['by_platform']) > 1) {
            $lines[] = "\n💬 <b>درآمد به تفکیک پیام‌رسان:</b>";
            foreach ($summary['by_platform'] as $bp) {
                $lines[] = '  ' . $bp['icon'] . ' ' . e($bp['name']) . ': ' . wtoman($bp['paid_amount']);
            }
        }

        $lines[] = '─────────────────';
        $lines[] = 'مجموع درآمد واریزشده: <b>' . wtoman($wallet['total_earned']) . '</b>';
        if ($summary['total_pending'] > 0) {
            $lines[] = 'پورسانت در انتظار تأیید: ' . wtoman($summary['total_pending']);
        }
        $lines[] = 'موجودی قابل برداشت: <b>' . wtoman($wallet['balance']) . '</b>';
        $lines[] = 'برداشت در انتظار پرداخت: ' . wtoman($wallet['pending']);

        $this->driver->sendMessage($chatId, implode("\n", $lines), ['inline_rows' => $this->panelInlineRows()]);
    }

    private function actWallet(array $user, int|string $chatId): void
    {
        $wallet = (new WalletService($this->db))->summary((int) $user['id']);
        $text = "👛 <b>کیف پول مرکزی</b>\n\n"
            . 'موجودی قابل برداشت: <b>' . wtoman($wallet['balance']) . "</b>\n"
            . 'در انتظار پرداخت: ' . wtoman($wallet['pending']) . "\n"
            . 'مجموع دریافتی: ' . wtoman($wallet['total_earned']) . "\n"
            . 'مجموع برداشت‌ها: ' . wtoman($wallet['total_withdrawn']) . "\n"
            . 'حداقل مبلغ برداشت: ' . wtoman(SettingsService::int('min_withdraw_amount', 30000));
        $this->driver->sendMessage($chatId, $text, [
            'inline_rows' => [
                [['text' => '🏦 درخواست برداشت', 'callback_data' => 'menu:withdraw']],
                [['text' => '🧾 تاریخچه تراکنش‌ها', 'callback_data' => 'menu:history']],
            ],
        ]);
    }

    private function actWithdraw(array $user, int|string $chatId): void
    {
        $min = SettingsService::int('min_withdraw_amount', 30000);
        $balance = (int) $user['wallet_balance'];
        if (!SettingsService::bool('withdrawal_enabled', true)) {
            $this->send($chatId, 'در حال حاضر امکان برداشت موقتاً غیرفعال است.');
            return;
        }
        if ($balance < $min) {
            $this->send($chatId, "❗️ موجودی شما برای برداشت کافی نیست.\n\n"
                . 'موجودی فعلی: ' . wtoman($balance) . "\n"
                . 'حداقل مبلغ برداشت: ' . wtoman($min) . "\n\n"
                . 'با دعوت دوستان بیشتر، موجودی خود را افزایش دهید.');
            return;
        }
        $this->setUserStep($user, 'withdraw_amount', ['min' => $min]);
        $this->driver->sendMessage($chatId, "🏦 <b>درخواست برداشت</b>\n\n"
            . 'موجودی قابل برداشت: ' . wtoman($balance) . "\n"
            . 'حداقل مبلغ: ' . wtoman($min) . "\n\n"
            . "لطفاً مبلغ مورد نظر را <b>فقط به عدد (تومان)</b> ارسال کنید:\n<i>(برای انصراف /cancel را ارسال کنید)</i>", [
                'remove_keyboard' => true,
            ]);
    }

    private function actHistory(array $user, int|string $chatId): void
    {
        [$rows] = (new WalletService($this->db))->transactions((int) $user['id'], [], 1, 10);
        $text = "🧾 <b>۱۰ تراکنش آخر</b>\n\n";
        if (!$rows) {
            $text .= 'تراکنشی ثبت نشده است.';
        } else {
            foreach ($rows as $row) {
                $sign = $row['direction'] === 'credit' ? '➕' : '➖';
                $text .= $sign . ' ' . wtoman((int) $row['amount']) . ' — ' . TransactionLabels::label((string) $row['type'])
                    . "\n   " . fa_date($row['created_at']) . ' | ' . TransactionLabels::status((string) $row['status']) . "\n";
            }
        }
        $this->driver->sendMessage($chatId, $text, ['inline_rows' => $this->panelInlineRows()]);
    }

    private function actCampaigns(array $user, int|string $chatId): void
    {
        $service = new CampaignService($this->db);
        $campaigns = $service->activeCampaigns();
        $text = "🎁 <b>جشنواره‌ها و جوایز</b>\n\n";
        if (!$campaigns) {
            $text .= 'در حال حاضر جشنواره فعالی وجود ندارد.';
        } else {
            foreach ($campaigns as $campaign) {
                $progress = $service->progressFor($campaign, (int) $user['id']);
                $text .= '🎯 <b>' . e($campaign['title']) . "</b>\n"
                    . mb_substr(strip_tags((string) $campaign['description']), 0, 200) . "\n"
                    . 'پیشرفت شما: ' . fa_digits($progress['progress']) . ' از ' . fa_digits($progress['target']) . "\n"
                    . 'پایان: ' . fa_date($campaign['ends_at'], false) . "\n\n";
            }
        }
        $this->driver->sendMessage($chatId, $text);
    }

    private function actRanking(array $user, int|string $chatId): void
    {
        if (!RankingService::enabled()) {
            $this->send($chatId, 'بخش رتبه‌بندی در حال حاضر غیرفعال است.');
            return;
        }
        $top = RankingService::top(10);
        $rank = RankingService::rankOf((int) $user['id']);
        $text = "🏆 <b>رتبه‌بندی برترین دعوت‌کنندگان</b>\n\n";
        $i = 1;
        foreach ($top as $row) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'کاربر';
            $text .= fa_digits($i++) . '. ' . e(mb_substr($name, 0, 20)) . ' — ' . fa_digits((int) $row['direct_ref_count']) . " معرفی\n";
        }
        if ($rank !== null) {
            $text .= "\n📍 رتبه شما: <b>" . fa_digits($rank) . ' از ' . fa_digits(RankingService::totalParticipants()) . '</b>';
        }
        $this->driver->sendMessage($chatId, $text);
    }

    private function actNotifications(array $user, int|string $chatId): void
    {
        $rows = $this->db->all(
            'SELECT * FROM notifications WHERE user_id = :u ORDER BY id DESC LIMIT 10',
            ['u' => (int) $user['id']]
        );
        $text = "🔔 <b>اعلان‌های من</b>\n\n";
        if (!$rows) {
            $text .= 'اعلان جدیدی ندارید.';
        } else {
            foreach ($rows as $row) {
                $text .= '• <b>' . e($row['title']) . "</b>\n   " . fa_date($row['created_at']) . "\n" . mb_substr(strip_tags((string) $row['body']), 0, 160) . "\n\n";
            }
        }
        $this->driver->sendMessage($chatId, $text);
    }

    private function actSupport(array $user, int|string $chatId): void
    {
        $this->setUserStep($user, 'support_message');
        $this->send($chatId, "☎️ <b>پشتیبانی</b>\n\nلطفاً پیام یا سؤال خود را در یک پیام ارسال کنید. تیم پشتیبانی پاسخ خواهد داد.");
    }

    private function sendGuide(int|string $chatId): void
    {
        $text = (string) SettingsService::get('guide_text', "📖 <b>راهنمای کسب درآمد</b>\n\n"
            . "۱) از بخش «دعوت و بنر تبلیغاتی»، متن آماده، بنر گرافیکی یا لینک اختصاصی خود را دریافت کنید.\n"
            . "۲) آن را در تلگرام، بله، روبیکا یا ایتا برای دوستانتان بفرستید.\n"
            . "۳) به ازای هر دعوت مستقیم (لایه ۰) پاداش ثابت نقدی به کیف پول شما واریز می‌شود.\n"
            . "۴) وقتی دوستان شما دیگران را دعوت کنند، پورسانت لایه‌های غیرمستقیم (لایه ۱ به بعد) نیز دریافت می‌کنید.\n"
            . "۵) از بخش «درخواست برداشت» موجودی کیف پول خود را تسویه نمایید.");
        $this->driver->sendMessage($chatId, $text, ['inline_rows' => $this->panelInlineRows()]);
    }

    private function sendMainMenu(array $user, int|string $chatId, string $text): void
    {
        $rows = MenuService::replyKeyboardRows($user, $this->platform);
        $opts = $rows !== [] ? ['reply_rows' => $rows] : [];
        $this->driver->sendMessage($chatId, $text, $opts);
    }

    /** دکمه شیشه‌ای باز کردن Mini App با رعایت کامل قابلیت واقعی پیام‌رسان و حالت نمایش */
    private function panelInlineRows(): array
    {
        if (!MessengerCapabilities::supports($this->platform, 'miniapp')) {
            return [];
        }
        $mode = (string) (SettingsService::get('miniapp_mode', 'auto') ?? 'auto');
        $uiMode = SettingsService::normalizeUiMode((string) (SettingsService::get('bot_ui_mode', 'hybrid') ?? 'hybrid'));
        if ($mode === 'off' || $uiMode === 'buttons') {
            return [];
        }
        $base = trim((string) (SettingsService::get('miniapp_url', '') ?: ''));
        if ($base === '') {
            return [];
        }
        $isHttps = str_starts_with(strtolower($base), 'https://');
        if ($isHttps) {
            $appUrl = $this->driver->buildMiniAppUrl($base);
            return $appUrl !== '' ? [[['text' => '📱 باز کردن پنل کاربری', 'web_app' => ['url' => $appUrl]]]] : [];
        }
        // روی HTTP غیر امن برای جلوگیری از خطای 400 تلگرام، فقط دکمه url وب نمایش داده می‌شود
        if ($this->platform === MessengerCapabilities::TELEGRAM) {
            return [[['text' => '🌐 باز کردن پنل در مرورگر', 'url' => $base]]];
        }
        return [];
    }

    // ------------------------------------------------------------------ کال‌بک‌های شیشه‌ای

    private function dispatchCallbackUpdate(IncomingUpdate $upd): void
    {
        $callbackId = (string) ($upd->callbackId ?? '');
        $data = (string) ($upd->callbackData ?? '');
        $chatId = $upd->chatId;

        $user = $this->users->findByMessenger($this->platform, $upd->externalUserId);
        if ($user === null) {
            $this->driver->answerCallback($callbackId, 'ابتدا /start را بزنید.', true);
            return;
        }
        if (($user['status'] ?? 'active') === 'blocked') {
            $this->driver->answerCallback($callbackId, 'حساب شما مسدود است.', true);
            return;
        }

        // تأیید تعاملی عضویت فقط برای کانال‌های بومی فاقد getChatMember (مانند روبیکا) — هرگز کانال‌های میان‌پیام‌رسانی API را دور نمی‌زند
        if ($data === 'gate:self_confirm') {
            $membership = new MembershipService($this->db);
            $confirm = $membership->confirmSelfDeclaredChannels($user, $this->platform, $upd->externalUserId);
            if (!$confirm['all_passed']) {
                $this->driver->answerCallback($callbackId, '☑️ ثبت شد؛ اکنون عضویت در کانال دیگر را تکمیل و بررسی کنید.', true);
                $this->sendMembershipGate($user, $chatId, $confirm['gate']);
                return;
            }
            $fresh = $this->users->find((int) $user['id']) ?? $user;
            $this->users->maybeQualify($fresh, false, $this->platform);
            Notifier::flushDeferred();
            $updated = $this->users->find((int) $user['id']) ?? $fresh;
            $this->driver->answerCallback($callbackId, '✅ ثبت شد.');
            $this->sendMainMenu($updated, $chatId, '✅ عضویت شما ثبت شد. اکنون می‌توانید از تمام امکانات ربات استفاده کنید.');
            if (!empty($updated['referred_by']) && empty($updated['qualified_at'])) {
                $this->sendPendingConditionActions($updated, $chatId);
            }
            return;
        }

        // بررسی خودکار عضویت کانال (بومی و میان‌پیام‌رسانی تأییدشده با API رسمی)
        if ($data === 'gate:check') {
            $membership = new MembershipService($this->db);
            $gate = $membership->gate($user, $this->platform, $upd->externalUserId);

            // اگر کاربر در یک پیام‌رسان متصل‌شده (مثلاً تلگرام) روی بررسی عضویت زد، کانال‌های پیام‌رسان ورودی اصلی او را هم بررسی کن
            $entryPlat = strtolower((string) ($user['entry_platform'] ?? $this->platform));
            if ($gate['passed'] && $entryPlat !== $this->platform && $membership->enforcementEnabled()) {
                $entryGate = $membership->gate($user, $entryPlat);
                if (!$entryGate['passed']) {
                    $gate = $entryGate;
                }
            }

            if ($gate['passed'] && empty($user['channels_verified']) && !$membership->enforcementEnabled()) {
                $activeChannels = $membership->activeChannels($this->platform);
                if ($activeChannels !== [] && $this->driver->isConfigured() && $this->driver->supports('channel_check_api')) {
                    $notJoined = [];
                    $apiErrors = [];
                    foreach ($activeChannels as $ch) {
                        $chk = $this->driver->checkChannelMembership($ch, $upd->externalUserId);
                        if (!$chk['ok']) {
                            $apiErrors[] = $ch['title'];
                            continue;
                        }
                        if ($chk['member'] === false) {
                            $notJoined[] = $ch;
                        }
                    }
                    if ($notJoined !== []) {
                        $gate = ['passed' => false, 'reason' => 'channels', 'channels' => $notJoined, 'errors' => []];
                    } elseif ($apiErrors === []) {
                        $this->db->update('users', ['channels_verified' => 1, 'updated_at' => now()], 'id = :id', ['id' => (int) $user['id']]);
                    }
                } elseif ($activeChannels === [] || !$this->driver->supports('channel_check_api')) {
                    $this->db->update('users', ['channels_verified' => 1, 'updated_at' => now()], 'id = :id', ['id' => (int) $user['id']]);
                }
            }

            if ($gate['passed']) {
                $fresh = $this->users->find((int) $user['id']) ?? $user;
                $this->users->maybeQualify($fresh, false, $entryPlat);
                Notifier::flushDeferred();
                $updated = $this->users->find((int) $user['id']) ?? $fresh;
                $this->driver->answerCallback($callbackId, '✅ عضویت تأیید شد.');
                $this->sendMainMenu($updated, $chatId, '✅ عضویت شما در تمام کانال‌های الزامی تأیید شد. اکنون می‌توانید از تمامی امکانات ربات استفاده کنید.');
                if (!empty($updated['referred_by']) && empty($updated['qualified_at'])) {
                    $this->sendPendingConditionActions($updated, $chatId);
                }
            } elseif (($gate['reason'] ?? '') === 'contact') {
                $this->driver->answerCallback($callbackId, '📱 ابتدا شماره تماس خود را ارسال کنید.', true);
                $this->sendMembershipGate($user, $chatId, $gate);
            } elseif (!empty($gate['needs_link_platforms'])) {
                $needNames = implode(' و ', array_map(
                    static fn(string $p): string => MessengerCapabilities::label($p),
                    (array) $gate['needs_link_platforms']
                ));
                $this->driver->answerCallback($callbackId, '🔗 ابتدا حساب ' . $needNames . ' خود را متصل و در کانال عضو شوید.', true);
                $this->sendMembershipGate($user, $chatId, $gate);
            } else {
                $this->driver->answerCallback($callbackId, '❌ هنوز عضو همه کانال‌های اجباری نیستید.', true);
            }
            return;
        }

        if ($data === 'qualify:phone') {
            $this->driver->answerCallback($callbackId, '');
            $text = (string) SettingsService::get('contact_request_message', 'برای تکمیل ثبت‌نام و فعال‌سازی پاداش، لطفاً شماره تماس خود را با دکمه زیر ارسال کنید:');
            $this->driver->sendMessage($chatId, $text, [
                'reply_rows' => [[['text' => '📱 ارسال شماره تماس', 'request_contact' => true]]],
            ]);
            return;
        }

        if ($data === 'qualify:profile') {
            $this->driver->answerCallback($callbackId, '');
            $this->setUserStep($user, 'complete_profile_name', []);
            $this->send($chatId, "✏️ لطفاً <b>نام و نام خانوادگی</b> خود را با یک فاصله ارسال کنید (مثال: <code>علی رضایی</code>):\n\n<i>برای انصراف /cancel را بفرستید.</i>");
            return;
        }

        // کال‌بک‌های استودیو دعوت (متن، بنر، بنر+کپشن، لینک مستقیم)
        if (str_starts_with($data, 'invite:')) {
            $this->driver->answerCallback($callbackId, '');
            if (!$this->passGates($user, $chatId, $upd->externalUserId)) {
                return;
            }
            $parts = explode(':', $data, 3);
            $sub = $parts[1] ?? '';
            $idArg = isset($parts[2]) ? (int) $parts[2] : 0;
            if ($sub === 'msg') {
                $this->inviteContent->recordShare('message', $idArg);
                $this->actInvite($user, $chatId, $idArg);
            } elseif ($sub === 'bcap') {
                $this->sendInviteBannerToUser($user, $chatId, $idArg, true);
            } elseif ($sub === 'bimg') {
                $this->sendInviteBannerToUser($user, $chatId, $idArg, false);
            } elseif ($sub === 'links') {
                $this->actInviteLinks($user, $chatId);
            }
            return;
        }

        // کال‌بک‌های اتصال امن حساب بین پیام‌رسان‌ها
        if ($data === 'link:gen') {
            $this->driver->answerCallback($callbackId, '🔑 کد اتصال ساخته شد.');
            $this->actLinkAccount($user, $chatId, true);
            return;
        }
        if ($data === 'link:enter') {
            $this->driver->answerCallback($callbackId, '');
            $this->setUserStep($user, 'enter_link_code', []);
            $this->send($chatId, "📥 لطفاً کد اتصال ۱۲ کاراکتری دریافت‌شده از پیام‌رسان دیگر خود را ارسال کنید (مثال: <code>LNK-A1B2C3D4E5F6</code>):\n\n<i>برای انصراف /cancel را بفرستید.</i>");
            return;
        }

        if (str_starts_with($data, 'menu:')) {
            $action = substr($data, 5);
            $this->driver->answerCallback($callbackId, '');
            if (!$this->passGates($user, $chatId, $upd->externalUserId)) {
                return;
            }
            $this->dispatchAction($user, $action, $chatId);
            return;
        }

        if (($data === 'admin:panel' || $data === 'admin:stats') && $this->platform === MessengerCapabilities::TELEGRAM) {
            $this->handleAdminCallback($callbackId, $data, $user, $chatId);
            return;
        }

        $this->driver->answerCallback($callbackId, '');
    }

    private function handleAdminCallback(string $callbackId, string $data, array $user, int|string $chatId): void
    {
        $admin = $this->db->first(
            'SELECT a.* FROM admins a WHERE a.telegram_user_id = :t AND a.is_active = 1 LIMIT 1',
            ['t' => (int) $user['telegram_id']]
        );
        if ($admin === null) {
            $this->driver->answerCallback($callbackId, 'شما دسترسی مدیریتی ندارید.', true);
            return;
        }
        if ($data === 'admin:stats') {
            $stats = StatsService::overview();
            $this->driver->answerCallback($callbackId, '');
            $this->driver->sendMessage($chatId, "📊 <b>آمار سریع</b>\n\n"
                . 'کاربران: ' . fa_digits($stats['total_users']) . "\n"
                . 'امروز: ' . fa_digits($stats['new_today']) . "\n"
                . 'پورسانت پرداختی: ' . wtoman($stats['total_commissions']) . "\n"
                . 'برداشت در انتظار: ' . wtoman($stats['pending_withdrawals_amount']));
            return;
        }
        $this->driver->answerCallback($callbackId, '');
        $token = $this->magicLoginToken((int) $admin['id']);
        $base = rtrim((string) (SettingsService::get('panel_url', '') ?: Request::origin()), '/');
        $url = $base . '/admin/login?t=' . $token;
        $this->driver->sendMessage($chatId, "🛠 <b>ورود به پنل مدیریت</b>\n\n"
            . "این لینک یک‌بارمصرف است و تا ۲ دقیقه اعتبار دارد:\n"
            . '<a href="' . e($url) . '">ورود به پنل مدیریت</a>', ['disable_web_page_preview' => true]);
    }

    private function magicLoginToken(int $adminId): string
    {
        $token = bin2hex(random_bytes(24));
        $this->db->insert('system_settings', [
            'key' => 'magic_login:' . hash('sha256', $token),
            'value' => json_encode(['admin_id' => $adminId, 'expires' => time() + 120]),
            'group_name' => 'temp',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $token;
    }

    // ------------------------------------------------------------------ متدهای کمکی

    public function referralLink(array $user, ?string $platform = null): string
    {
        $plat = $platform ?? $this->platform;
        $code = (string) ($user['referral_code'] ?? '');
        if ($plat === MessengerCapabilities::TELEGRAM) {
            $settings = TelegramService::settings($this->db);
            $username = ltrim(trim((string) ($settings['bot_username'] ?? '')), '@');
            if ($username !== '') {
                return 'https://t.me/' . $username . '?start=' . $code;
            }
            $base = trim((string) SettingsService::get('bot_link_base', ''));
            if ($base !== '') {
                return rtrim($base, '/') . '?start=' . $code;
            }
        }
        return $this->registry->buildReferralLink($plat, $code);
    }

    private function send(int|string $chatId, string $text): void
    {
        $this->driver->sendMessage($chatId, $text);
    }
}
