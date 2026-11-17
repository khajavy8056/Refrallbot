<?php
declare(strict_types=1);

namespace RB\Bot;

use RB\Services\CampaignService;
use RB\Services\MembershipService;
use RB\Services\MenuService;
use RB\Services\SettingsService;
use RB\Services\SupportService;
use RB\Services\TelegramService;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Services\WithdrawalService;
use RB\Support\Database;
use RB\Support\ErrorHandler;
use RB\Support\Request;

/**
 * پردازش آپدیت‌های تلگرام
 * تمام منطق در بک‌اند اجرا می‌شود؛ ربات فقط لایه‌ی رابط کاربری است.
 */
final class BotHandler
{
    private Database $db;
    private TelegramService $tg;
    private UserService $users;
    private BotSession $session;
    private int $maxDepth;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
        $this->tg = new TelegramService(null, $this->db);
        $this->users = new UserService($this->db);
        $this->session = new BotSession($this->db);
        $this->maxDepth = (int) \RB\Support\Config::get('app.max_referral_depth', 8);
    }

    public function handle(array $update): void
    {
        try {
            $this->db->run(
                'UPDATE telegram_settings SET last_update_at = :now, updates_count = updates_count + 1, updated_at = :now2',
                ['now' => now(), 'now2' => now()]
            );
        } catch (\Throwable) {
        }

        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        } elseif (isset($update['my_chat_member']) || isset($update['chat_member'])) {
            // بی‌اهمیت برای منطق فعلی
        }
    }

    // ------------------------------------------------------------------ پیام‌ها
    private function handleMessage(array $message): void
    {
        $from = $message['from'] ?? [];
        $chatId = $message['chat']['id'] ?? null;
        $telegramId = (int) ($from['id'] ?? 0);
        if ($chatId === null || $telegramId <= 0 || !empty($from['is_bot'])) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        $this->logMessage($telegramId, 'in', $text !== '' ? 'message' : 'media', $message);

        // ---- دستور /start
        if (str_starts_with($text, '/start')) {
            $payload = null;
            if (preg_match('~^/start\s+(\S+)~', $text, $m)) {
                $payload = $m[1];
            }
            $this->cmdStart($telegramId, $from, $payload, $chatId);
            return;
        }

        if ($text === '/help' || $text === '/راهنما') {
            $this->sendGuide($telegramId, $chatId);
            return;
        }

        if ($text === '/admin') {
            $this->cmdAdmin($telegramId, $chatId);
            return;
        }

        $user = $this->users->findByTelegramId($telegramId);
        if ($user === null) {
            $this->send($chatId, "برای استفاده از ربات ابتدا دستور /start را ارسال کنید.");
            return;
        }
        $this->users->touchActivity((int) $user['id'], Request::ip());

        // ---- بررسی وضعیت مسدودی
        if ($user['status'] === 'blocked') {
            $this->send($chatId, '⛔️ حساب شما توسط مدیریت مسدود شده است. برای پیگیری با پشتیبانی تماس بگیرید.');
            return;
        }

        // ---- وضعیت گفتگو (جریان چندمرحله‌ای)
        $session = $this->session->get($telegramId);
        if ($session !== null && $session['step'] !== null) {
            if ($this->handleStep($user, $session, $text, $message, $chatId)) {
                return;
            }
        }

        // ---- دریافت شماره تماس (Request Contact)
        if (isset($message['contact']['phone_number'])) {
            $this->handleContact($user, $message, $chatId);
            return;
        }

        // ---- گیت‌های ورود (کانال اجباری / شماره تماس)
        if (!$this->passGates($user, $chatId)) {
            return;
        }

        // ---- دکمه‌های منو
        $action = MenuService::actionByTitle($text);
        if ($action !== null) {
            $this->dispatchAction($user, $action, $chatId, $message['message_id'] ?? null);
            return;
        }

        if ($text === '🏠 منوی اصلی' || $text === '/menu') {
            $this->sendMainMenu($user, $chatId, 'منوی اصلی — یک گزینه را انتخاب کنید:');
            return;
        }

        // ---- متن پیش‌فرض
        $this->send($chatId, "متوجه نشدم. لطفاً از دکمه‌های منو استفاده کنید یا /menu را بزنید.");
    }

    // ------------------------------------------------------------------ /start
    private function cmdStart(int $telegramId, array $from, ?string $payload, int|string $chatId): void
    {
        $before = $this->users->findByTelegramId($telegramId);
        $isNew = $before === null;

        if ($before !== null && $before['status'] === 'blocked') {
            $this->send($chatId, '⛔️ حساب شما مسدود است.');
            return;
        }

        $result = $this->users->registerFromTelegram($from, $payload, ['ip' => Request::ip()]);
        $user = $result['user'] ?? null;
        if ($user === null) {
            $this->send($chatId, 'خطا در ثبت‌نام. لطفاً دوباره تلاش کنید.');
            return;
        }

        // ثبت رویداد مشکوک: تلاش برای استفاده از لینک خود
        if ($payload !== null) {
            $referrer = $this->users->findByCode(strtoupper($payload));
            if ($referrer && (int) $referrer['telegram_id'] === $telegramId) {
                \RB\Services\SecurityService::log('self_referral_attempt', 'medium', ['payload' => $payload], (int) $user['id']);
            }
        }

        $this->session->clear($telegramId);
        $this->sendMainMenu($user, $chatId, $this->startText($isNew, $user));
    }

    private function startText(bool $isNew, array $user): string
    {
        $name = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?: 'دوست عزیز';
        $key = $isNew ? 'start_message_new' : 'start_message_returning';
        $default = $isNew
            ? "سلام {name} عزیز 👋\nبه <b>{app_name}</b> خوش آمدید!\n\nبا دعوت دوستان خود درآمد کسب کنید:\n• هر معرفی معتبر = پاداش نقدی\n• پورسانت چندلایه تا {max_levels} سطح\n• برداشت وجه از طریق کیف پول\n\nاز منوی زیر شروع کنید 👇"
            : "سلام {name} عزیز 👋\nخوش برگشتید به <b>{app_name}</b>.\n\nاز منوی زیر بخش مورد نظر را انتخاب کنید 👇";
        $template = (string) SettingsService::get($key, $default);

        $text = str_replace(
            ['{name}', '{app_name}', '{username}', '{max_levels}', '{min_withdraw}', '{direct_reward}'],
            [
                $name,
                (string) SettingsService::get('app_name', 'رفرال‌بات'),
                $user['username'] ? '@' . $user['username'] : $name,
                fa_digits($this->maxDepth),
                wtoman(SettingsService::int('min_withdraw_amount', 30000)),
                wtoman(\RB\Services\CommissionSettings::current($this->db)->directReward),
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

    // ------------------------------------------------------------------ گیت‌ها
    private function passGates(array $user, int|string $chatId): bool
    {
        $membership = new MembershipService($this->db);
        $gate = $membership->gate($user);
        if (!$gate['passed']) {
            $this->sendMembershipGate($user, $chatId, $gate);
            return false;
        }
        return true;
    }

    private function sendMembershipGate(array $user, int|string $chatId, array $gate): void
    {
        if ($gate['reason'] === 'contact') {
            $text = (string) SettingsService::get('contact_request_message', 'برای ادامه، لطفاً شماره تماس خود را با دکمه زیر ارسال کنید.');
            $this->tg->sendMessage($chatId, $text, [
                'reply_markup' => [
                    'keyboard' => [[['text' => '📱 ارسال شماره تماس', 'request_contact' => true]]],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => true,
                ],
            ]);
            return;
        }

        if ($gate['reason'] === 'channels') {
            $rows = [];
            foreach ($gate['channels'] as $channel) {
                $url = $channel['invite_link'] ?: (str_starts_with((string) $channel['chat_id'], '@')
                    ? 'https://t.me/' . ltrim((string) $channel['chat_id'], '@')
                    : null);
                if ($url) {
                    $rows[] = [['text' => '📢 ' . $channel['title'], 'url' => $url]];
                }
            }
            $rows[] = [['text' => '✅ بررسی عضویت', 'callback_data' => 'gate:check']];
            $message = (string) SettingsService::get('join_channels_message', 'برای استفاده از ربات ابتدا در کانال‌های زیر عضو شوید:');
            if (!empty($gate['errors'])) {
                $message .= "\n\n⚠️ " . implode("\n⚠️ ", $gate['errors']);
            }
            $this->tg->sendMessage($chatId, $message, [
                'reply_markup' => TelegramService::inlineKeyboard($rows),
            ]);
            return;
        }
    }

    private function handleContact(array $user, array $message, int|string $chatId): void
    {
        $contact = $message['contact'];
        // تطبیق شماره با شناسه تلگرام فرستنده
        if ((int) ($contact['user_id'] ?? 0) !== (int) $user['telegram_id']) {
            \RB\Services\SecurityService::log('contact_mismatch', 'high', [
                'contact_user_id' => (int) ($contact['user_id'] ?? 0),
            ], (int) $user['id']);
            $this->send($chatId, '❌ این شماره متعلق به حساب تلگرام شما نیست. لطفاً با دکمه «ارسال شماره تماس» شماره خودتان را ارسال کنید.');
            return;
        }
        $phone = preg_replace('~[^0-9+]~', '', (string) $contact['phone_number']);
        if ($phone === null || strlen($phone) < 8) {
            $this->send($chatId, 'شماره تماس نامعتبر است.');
            return;
        }
        $this->users->setPhone((int) $user['id'], $phone);
        $user = $this->users->find((int) $user['id']) ?? $user;
        $this->sendMainMenu($user, $chatId, "✅ شماره تماس شما ثبت شد.\n\nاز منوی زیر گزینه مورد نظر را انتخاب کنید:");
    }

    // ------------------------------------------------------------------ وضعیت‌ها
    private function handleStep(array $user, array $session, string $text, array $message, int|string $chatId): bool
    {
        $step = (string) $session['step'];
        $payload = $session['payload'];

        switch ($step) {
            case 'withdraw_amount':
                $amount = \RB\Support\Money::parseToman($text);
                $min = (int) ($payload['min'] ?? SettingsService::int('min_withdraw_amount', 30000));
                if ($amount === null || $amount <= 0) {
                    $this->send($chatId, '❌ مبلغ نامعتبر است. لطفاً فقط عدد وارد کنید.');
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
                $this->session->setStep(
                    (int) $user['telegram_id'],
                    (int) $user['id'],
                    'withdraw_card',
                    ['amount' => $amount, 'min' => $min]
                );
                $this->send($chatId, '💳 لطفاً <b>شماره کارت</b> خود را (۱۶ رقم، بدون فاصله) ارسال کنید:');
                return true;

            case 'withdraw_card':
                $card = preg_replace('~\D~', '', en_digits(trim($text))) ?? '';
                $validation = WithdrawalService::validateCard($card);
                if (!$validation['valid']) {
                    $this->send($chatId, '❌ ' . $validation['error']);
                    return true;
                }
                $this->session->setStep((int) $user['telegram_id'], (int) $user['id'], 'withdraw_holder', [
                    'amount' => (int) ($payload['amount'] ?? 0),
                    'card' => $validation['normalized'],
                    'min' => $payload['min'] ?? 0,
                ]);
                $this->send($chatId, "شماره کارت ثبت شد ✅\n\nلطفاً <b>نام صاحب کارت</b> را ارسال کنید:");
                return true;

            case 'withdraw_holder':
                if (mb_strlen(trim($text)) < 3) {
                    $this->send($chatId, 'نام صاحب کارت باید حداقل ۳ حرف باشد.');
                    return true;
                }
                $service = new WithdrawalService($this->db);
                try {
                    $request = $service->create((int) $user['id'], (int) $payload['amount'], (string) $payload['card'], trim($text), Request::ip());
                    $this->session->clear((int) $user['telegram_id']);
                    $info = $service->statusLabel((string) $request['status']);
                    $this->tg->sendMessage($chatId, "✅ درخواست برداشت شما ثبت شد.\n\n"
                        . "مبلغ: <b>" . wtoman((int) $request['amount']) . "</b>\n"
                        . "کد پیگیری: <code>" . $request['request_code'] . "</code>\n"
                        . "وضعیت: " . $info . "\n\n"
                        . "مبلغ تا زمان پردازش در حساب شما رزرو شده است و پس از تأیید مدیریت پرداخت می‌شود.", [
                            'reply_markup' => TelegramService::replyKeyboard(MenuService::replyKeyboardRows($user)),
                        ]);
                } catch (\Throwable $e) {
                    $this->send($chatId, '❌ ' . $e->getMessage());
                }
                return true;

            case 'support_message':
                if (mb_strlen(trim($text)) < 5) {
                    $this->send($chatId, 'متن پیام باید حداقل ۵ کاراکتر باشد.');
                    return true;
                }
                SupportService::create((int) $user['id'], trim($text));
                $this->session->clear((int) $user['telegram_id']);
                $this->send($chatId, "✅ پیام شما ثبت شد.\nتیم پشتیبانی در اسرع وقت پاسخ می‌دهد.");
                return true;
        }
        return false;
    }

    // ------------------------------------------------------------------ اکشن‌ها
    private function dispatchAction(array $user, string $action, int|string $chatId, ?int $messageId = null): void
    {
        switch ($action) {
            case 'account':    $this->actAccount($user, $chatId); break;
            case 'invite':     $this->actInvite($user, $chatId); break;
            case 'referrals':  $this->actReferrals($user, $chatId); break;
            case 'income':     $this->actIncome($user, $chatId); break;
            case 'wallet':     $this->actWallet($user, $chatId); break;
            case 'withdraw':   $this->actWithdraw($user, $chatId); break;
            case 'history':    $this->actHistory($user, $chatId); break;
            case 'campaigns':  $this->actCampaigns($user, $chatId); break;
            case 'ranking':    $this->actRanking($user, $chatId); break;
            case 'notifications': $this->actNotifications($user, $chatId); break;
            case 'support':    $this->actSupport($user, $chatId); break;
            case 'guide':      $this->sendGuide((int) $user['telegram_id'], $chatId); break;
            default:
                $this->send($chatId, 'این بخش در حال حاضر فعال نیست.');
        }
    }

    private function actAccount(array $user, int|string $chatId): void
    {
        $wallet = (new WalletService($this->db))->summary((int) $user['id']);
        $text = "👤 <b>حساب کاربری من</b>\n\n"
            . "نام: " . e(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: '—') . "\n"
            . "شناسه تلگرام: <code>" . (int) $user['telegram_id'] . "</code>\n"
            . "نام کاربری: " . ($user['username'] ? '@' . e($user['username']) : '—') . "\n"
            . "کد دعوت: <code>" . e($user['referral_code']) . "</code>\n"
            . "وضعیت حساب: " . ($user['status'] === 'active' ? '✅ فعال' : ($user['status'] === 'blocked' ? '⛔️ مسدود' : '⚠️ در بررسی')) . "\n"
            . "تاریخ عضویت: " . fa_date($user['joined_at']) . "\n"
            . "شماره تماس: " . ($user['phone'] ? e($user['phone']) : 'ثبت نشده') . "\n\n"
            . "👥 زیرمجموعه مستقیم: " . fa_digits((int) $user['direct_ref_count']) . "\n"
            . "🌐 کل شبکه: " . fa_digits((int) $user['total_ref_count']) . "\n"
            . "💰 درآمد کل: " . wtoman($wallet['total_earned']) . "\n"
            . "👛 موجودی: " . wtoman($wallet['balance']);
        $this->tg->sendMessage($chatId, $text);
    }

    private function actInvite(array $user, int|string $chatId): void
    {
        $link = $this->referralLink($user);
        $text = "🔗 <b>لینک دعوت اختصاصی شما</b>\n\n"
            . "<code>" . e($link) . "</code>\n\n"
            . "کد دعوت: <code>" . e($user['referral_code']) . "</code>\n\n"
            . "این لینک را برای دوستان خود بفرستید. هر عضویت معتبر پاداش دارد!\n"
            . "پاداش هر معرفی: <b>" . wtoman(\RB\Services\CommissionSettings::current($this->db)->directReward) . "</b>";
        $share = 'https://t.me/share/url?url=' . rawurlencode($link) . '&text=' . rawurlencode('با لینک من عضو شو و درآمد کسب کن!');
        $this->tg->sendMessage($chatId, $text, [
            'reply_markup' => TelegramService::inlineKeyboard([
                [['text' => '📤 اشتراک‌گذاری لینک', 'url' => $share]],
                [['text' => '🔄 دریافت مجدد', 'callback_data' => 'menu:invite']],
            ]),
        ]);
    }

    private function actReferrals(array $user, int|string $chatId): void
    {
        $direct = $this->users->directReferrals((int) $user['id'], 10);
        $text = "👥 <b>زیرمجموعه‌های من</b>\n\n"
            . "زیرمجموعه مستقیم: <b>" . fa_digits((int) $user['direct_ref_count']) . "</b>\n"
            . "کل شبکه: <b>" . fa_digits((int) $user['total_ref_count']) . "</b>\n\n";
        if (!$direct) {
            $text .= "هنوز زیرمجموعه‌ای ندارید.\nلینک دعوت خود را برای دوستانتان بفرستید.";
        } else {
            $text .= "<b>۱۰ زیرمجموعه اخیر:</b>\n";
            foreach ($direct as $ref) {
                $name = trim(($ref['first_name'] ?? '') . ' ' . ($ref['last_name'] ?? '')) ?: 'کاربر';
                $text .= '• ' . e($name) . ($ref['username'] ? ' (@' . e($ref['username']) . ')' : '') . ' — ' . fa_date($ref['joined_at'], false) . "\n";
            }
            $text .= "\nبرای مشاهده جزئیات کامل، به پنل کاربری مراجعه کنید.";
        }
        $this->tg->sendMessage($chatId, $text, ['reply_markup' => $this->panelButton()]);
    }

    private function actIncome(array $user, int|string $chatId): void
    {
        $wallet = (new WalletService($this->db))->summary((int) $user['id']);
        $text = "💰 <b>درآمد من</b>\n\n"
            . "پاداش معرفی مستقیم: " . wtoman($wallet['direct_earned']) . "\n"
            . "پورسانت لایه‌ای: " . wtoman($wallet['layer_earned']) . "\n"
            . "جایزه کمپین‌ها: " . wtoman($wallet['campaign_earned']) . "\n"
            . "─────────────────\n"
            . "مجموع درآمد: <b>" . wtoman($wallet['total_earned']) . "</b>\n"
            . "موجودی قابل برداشت: <b>" . wtoman($wallet['balance']) . "</b>\n"
            . "در انتظار پرداخت: " . wtoman($wallet['pending']);
        $this->tg->sendMessage($chatId, $text, ['reply_markup' => $this->panelButton()]);
    }

    private function actWallet(array $user, int|string $chatId): void
    {
        $wallet = (new WalletService($this->db))->summary((int) $user['id']);
        $text = "👛 <b>کیف پول</b>\n\n"
            . "موجودی قابل برداشت: <b>" . wtoman($wallet['balance']) . "</b>\n"
            . "در انتظار پرداخت: " . wtoman($wallet['pending']) . "\n"
            . "مجموع دریافتی: " . wtoman($wallet['total_earned']) . "\n"
            . "مجموع برداشت‌ها: " . wtoman($wallet['total_withdrawn']) . "\n"
            . "حداقل مبلغ برداشت: " . wtoman(SettingsService::int('min_withdraw_amount', 30000));
        $this->tg->sendMessage($chatId, $text, [
            'reply_markup' => TelegramService::inlineKeyboard([
                [['text' => '🏦 درخواست برداشت', 'callback_data' => 'menu:withdraw']],
                [['text' => '🧾 تاریخچه تراکنش‌ها', 'callback_data' => 'menu:history']],
            ]),
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
                . "موجودی فعلی: " . wtoman($balance) . "\n"
                . "حداقل مبلغ برداشت: " . wtoman($min) . "\n\n"
                . "با دعوت دوستان بیشتر، موجودی خود را افزایش دهید.");
            return;
        }
        $this->session->setStep((int) $user['telegram_id'], (int) $user['id'], 'withdraw_amount', ['min' => $min]);
        $this->tg->sendMessage($chatId, "🏦 <b>درخواست برداشت</b>\n\n"
            . "موجودی قابل برداشت: " . wtoman($balance) . "\n"
            . "حداقل مبلغ: " . wtoman($min) . "\n\n"
            . "لطفاً مبلغ مورد نظر را <b>فقط به عدد (تومان)</b> ارسال کنید:", [
                'reply_markup' => ['remove_keyboard' => true],
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
        $this->tg->sendMessage($chatId, $text, ['reply_markup' => $this->panelButton()]);
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
        $this->tg->sendMessage($chatId, $text);
    }

    private function actRanking(array $user, int|string $chatId): void
    {
        if (!\RB\Services\RankingService::enabled()) {
            $this->send($chatId, 'بخش رتبه‌بندی در حال حاضر غیرفعال است.');
            return;
        }
        $top = \RB\Services\RankingService::top(10);
        $rank = \RB\Services\RankingService::rankOf((int) $user['id']);
        $text = "🏆 <b>رتبه‌بندی کاربران</b>\n\n";
        $i = 1;
        foreach ($top as $row) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'کاربر';
            $text .= fa_digits($i++) . '. ' . e(mb_substr($name, 0, 20)) . ' — ' . fa_digits((int) $row['direct_ref_count']) . " معرفی\n";
        }
        if ($rank !== null) {
            $text .= "\n📍 رتبه شما: <b>" . fa_digits($rank) . " از " . fa_digits(\RB\Services\RankingService::totalParticipants()) . "</b>";
        }
        $this->tg->sendMessage($chatId, $text);
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
        $this->tg->sendMessage($chatId, $text);
    }

    private function actSupport(array $user, int|string $chatId): void
    {
        $this->session->setStep((int) $user['telegram_id'], (int) $user['id'], 'support_message');
        $this->send($chatId, "☎️ <b>پشتیبانی</b>\n\nلطفاً پیام یا سؤال خود را در یک پیام ارسال کنید. تیم پشتیبانی پاسخ خواهد داد.");
    }

    private function sendGuide(int $telegramId, int|string $chatId): void
    {
        $text = (string) SettingsService::get('guide_text', "📖 <b>راهنمای استفاده</b>\n\n"
            . "۱) لینک دعوت خود را از بخش «لینک دعوت من» کپی کنید.\n"
            . "۲) آن را برای دوستانتان ارسال کنید.\n"
            . "۳) پس از عضویت و واجد شرایط شدن هر نفر، پاداش به کیف پول شما اضافه می‌شود.\n"
            . "۴) پورسانت لایه‌ای از فعالیت زیرمجموعه‌های سطوح بالاتر نیز به شما تعلق می‌گیرد.\n"
            . "۵) از بخش «برداشت وجه» می‌توانید درخواست برداشت ثبت کنید.\n\n"
            . "برای مشاهده پنل کامل، دکمه زیر را بزنید.");
        $this->tg->sendMessage($chatId, $text, ['reply_markup' => $this->panelButton()]);
    }

    private function sendMainMenu(array $user, int|string $chatId, string $text): void
    {
        $this->tg->sendMessage($chatId, $text, [
            'reply_markup' => TelegramService::replyKeyboard(MenuService::replyKeyboardRows($user)),
        ]);
    }

    private function panelButton(): array
    {
        $base = (string) (SettingsService::get('miniapp_url', '') ?: '');
        if ($base === '') {
            return TelegramService::inlineKeyboard([]);
        }
        return TelegramService::inlineKeyboard([
            [['text' => '📱 باز کردن پنل کاربری', 'web_app' => ['url' => $base]]],
        ]);
    }

    // ------------------------------------------------------------------ کال‌بک‌ها
    private function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $from = $callback['from'] ?? [];
        $telegramId = (int) ($from['id'] ?? 0);
        $message = $callback['message'] ?? [];
        $chatId = $message['chat']['id'] ?? null;

        $user = $this->users->findByTelegramId($telegramId);
        if ($user === null) {
            $this->tg->answerCallbackQuery($callbackId, 'ابتدا /start را بزنید.', true);
            return;
        }
        if ($user['status'] === 'blocked') {
            $this->tg->answerCallbackQuery($callbackId, 'حساب شما مسدود است.', true);
            return;
        }

        if ($data === 'gate:check') {
            $membership = new MembershipService($this->db);
            $gate = $membership->gate($user);
            if ($gate['passed']) {
                $this->tg->answerCallbackQuery($callbackId, '✅ عضویت تأیید شد.');
                if ($chatId !== null) {
                    $this->sendMainMenu($this->users->find((int) $user['id']) ?? $user, $chatId, '✅ عضویت شما تأیید شد. اکنون می‌توانید از ربات استفاده کنید.');
                }
            } else {
                $this->tg->answerCallbackQuery($callbackId, '❌ هنوز عضو کانال‌ها نیستید.', true);
            }
            return;
        }

        if (str_starts_with($data, 'menu:')) {
            $action = substr($data, 5);
            $this->tg->answerCallbackQuery($callbackId, '');
            if (!$this->passGates($user, $chatId ?? $telegramId)) {
                return;
            }
            $this->dispatchAction($user, $action, $chatId ?? $telegramId);
            return;
        }

        if ($data === 'admin:panel' || $data === 'admin:stats') {
            $this->handleAdminCallback($callbackId, $data, $user, $chatId ?? $telegramId);
            return;
        }

        $this->tg->answerCallbackQuery($callbackId, '');
    }

    private function handleAdminCallback(string $callbackId, string $data, array $user, int|string $chatId): void
    {
        $admin = $this->db->first(
            'SELECT a.* FROM admins a WHERE a.telegram_user_id = :t AND a.is_active = 1 LIMIT 1',
            ['t' => (int) $user['telegram_id']]
        );
        if ($admin === null) {
            $this->tg->answerCallbackQuery($callbackId, 'شما دسترسی مدیریتی ندارید.', true);
            return;
        }
        if ($data === 'admin:stats') {
            $stats = \RB\Services\StatsService::overview();
            $this->tg->answerCallbackQuery($callbackId, '');
            $this->tg->sendMessage($chatId, "📊 <b>آمار سریع</b>\n\n"
                . 'کاربران: ' . fa_digits($stats['total_users']) . "\n"
                . 'امروز: ' . fa_digits($stats['new_today']) . "\n"
                . 'پورسانت پرداختی: ' . wtoman($stats['total_commissions']) . "\n"
                . 'برداشت در انتظار: ' . wtoman($stats['pending_withdrawals_amount']));
            return;
        }
        $this->tg->answerCallbackQuery($callbackId, '');
        $token = $this->magicLoginToken((int) $admin['id']);
        $base = rtrim((string) (SettingsService::get('panel_url', '') ?: Request::origin()), '/');
        $url = $base . '/admin/login?t=' . $token;
        $this->tg->sendMessage($chatId, "🛠 <b>ورود به پنل مدیریت</b>\n\n"
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

    // ------------------------------------------------------------------ کمکی
    public function referralLink(array $user): string
    {
        $settings = TelegramService::settings($this->db);
        $username = (string) ($settings['bot_username'] ?? '');
        $base = $username !== '' ? 'https://t.me/' . $username : (string) SettingsService::get('bot_link_base', '');
        return rtrim($base, '/') . '?start=' . $user['referral_code'];
    }

    private function send(int|string $chatId, string $text): void
    {
        $this->tg->sendMessage($chatId, $text);
    }

    private function logMessage(int $telegramId, string $direction, string $kind, array $payload): void
    {
        try {
            $user = $this->users->findByTelegramId($telegramId);
            $this->db->insert('telegram_messages', [
                'user_id' => $user['id'] ?? null,
                'telegram_id' => $telegramId,
                'direction' => $direction,
                'kind' => $kind,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'chat_id' => (string) ($payload['chat']['id'] ?? ''),
                'message_id' => (string) ($payload['message_id'] ?? ''),
                'status' => 'ok',
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
        }
    }
}
