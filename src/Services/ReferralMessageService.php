<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;

/**
 * موتور تولید پیام‌های پویای دعوت دوستان و خوش‌آمدگویی به کاربر دعوت‌شده
 *
 * تمام مبالغ (پاداش دعوت‌کننده و پاداش دعوت‌شده)، لینک اختصاصی دعوت و فهرست شرایط
 * به‌صورت زنده از تنظیمات پنل مدیریت خوانده می‌شوند و هیچ مقدار ثابتی در کد وجود ندارد.
 */
final class ReferralMessageService
{
    /** قالب پیش‌فرض قابل ویرایش پیام دعوت دوستان (برای نمایش در پنل مدیریت) */
    public static function defaultInviteTemplate(): string
    {
        return "دوستت رو به <b>{app_name}</b> دعوت کن و جایزه بگیر! 🎁\n\n"
            . "با دعوت از دوستانت، پس از تکمیل مراحل ثبت‌نام و شرایط عضویت:\n"
            . "• تو <b>{inviter_reward}</b> پاداش دعوت می‌گیری.\n"
            . "• دوستت هم <b>{invitee_reward}</b> هدیه خوش‌آمدگویی دریافت می‌کنه.\n\n"
            . "برای شروع از لینک اختصاصی زیر استفاده کن:\n"
            . "<code>{invite_link}</code>\n\n"
            . "🔑 کد دعوت: <code>{referral_code}</code>";
    }

    /** قالب پیش‌فرض متن اشتراک‌گذاری سریع تلگرام */
    public static function defaultShareTemplate(): string
    {
        return "دوستت رو دعوت کن و جایزه بگیر! 🎁\n"
            . "با عضویت از طریق این لینک و تکمیل ثبت‌نام، {invitee_reward} هدیه خوش‌آمدگویی به کیف پولت واریز می‌شه و من هم {inviter_reward} پاداش دعوت می‌گیرم 👇";
    }

    /** قالب پیش‌فرض قابل ویرایش پیام خوش‌آمدگویی و راهنمای تکمیل ثبت‌نام برای کاربر دعوت‌شده */
    public static function defaultReferredWelcomeTemplate(): string
    {
        return "🎉 سلام <b>{name}</b> عزیز، به <b>{app_name}</b> خوش آمدید!\n"
            . "شما از طریق لینک دعوت <b>{referrer_name}</b> وارد ربات شدید.\n\n"
            . "🎁 با تکمیل ثبت‌نام و شرایط زیر، مبلغ <b>{invitee_reward}</b> به‌عنوان <b>پاداش خوش‌آمدگویی</b> به کیف پول شما واریز خواهد شد:\n\n"
            . "{requirements}\n\n"
            . "✨ لطفاً مراحل باقی‌مانده را تکمیل کنید تا پاداش شما به‌صورت خودکار فعال و واریز شود.";
    }

    /**
     * تولید پیام کامل «دعوت دوستان» با جایگذاری پویای لینک دعوت و هر دو مبلغ پاداش
     */
    public static function renderInviteMessage(
        array $user,
        string $inviteLink,
        ?CommissionSettings $settings = null,
        ?Database $db = null
    ): string {
        $db ??= Database::instance();
        $settings ??= CommissionSettings::current($db);
        $custom = trim((string) (SettingsService::get('invite_message_text', '') ?? ''));

        $inviterReward = $settings->effectiveDirectReward();
        $inviteeReward = $settings->effectiveInviteeReward();

        if ($custom !== '') {
            $hasLinkPlaceholder = self::containsLinkPlaceholder($custom);
            $rendered = self::replaceVariables($custom, $user, $inviteLink, null, $settings, $db, true);
            if (!$hasLinkPlaceholder && $inviteLink !== '') {
                $rendered .= "\n\n🔗 لینک اختصاصی دعوت:\n<code>" . e($inviteLink) . '</code>';
            }
            return $rendered;
        }

        // قالب پیش‌فرض هوشمند متناسب با وضعیت فعلی پاداش‌های فعال در پنل مدیریت
        $appName = e((string) SettingsService::get('app_name', 'رفرال‌بات'));
        $code = e((string) ($user['referral_code'] ?? ''));
        $safeLink = $inviteLink !== ''
            ? '<code>' . e($inviteLink) . '</code>'
            : '<i>(پس از اتصال ربات در پنل مدیریت فعال می‌شود)</i>';

        $lines = [];
        $lines[] = "دوستت رو به <b>{$appName}</b> دعوت کن و جایزه بگیر! 🎁";
        $lines[] = '';

        if ($inviterReward > 0 && $inviteeReward > 0) {
            $lines[] = 'با دعوت از دوستانت، پس از تکمیل مراحل ثبت‌نام و شرایط عضویت:';
            $lines[] = '• تو <b>' . wtoman($inviterReward) . '</b> پاداش دعوت می‌گیری.';
            $lines[] = '• دوستت هم <b>' . wtoman($inviteeReward) . '</b> هدیه خوش‌آمدگویی دریافت می‌کنه.';
        } elseif ($inviterReward > 0) {
            $lines[] = 'با دعوت از دوستانت، پس از تکمیل مراحل ثبت‌نام و شرایط عضویت، بابت هر دعوت موفق <b>' . wtoman($inviterReward) . '</b> پاداش به کیف پولت واریز می‌شه.';
        } elseif ($inviteeReward > 0) {
            $lines[] = 'دوستانت با عضویت از طریق لینک تو و تکمیل شرایط ثبت‌نام، <b>' . wtoman($inviteeReward) . '</b> هدیه خوش‌آمدگویی دریافت می‌کنن.';
        } else {
            $lines[] = 'دوستانت رو با لینک اختصاصی خودت به ربات دعوت کن و شبکه‌ات رو گسترش بده.';
        }

        if ($settings->multiLevelEnabled) {
            $lines[] = '🌐 پورسانت چندلایه تا <b>' . fa_digits($settings->maxLevels) . ' سطح</b> فعال است.';
        }

        $lines[] = '';
        $lines[] = 'برای شروع از لینک اختصاصی زیر استفاده کن:';
        $lines[] = $safeLink;
        if ($code !== '') {
            $lines[] = '';
            $lines[] = '🔑 کد دعوت: <code>' . $code . '</code>';
        }
        $lines[] = '👥 دعوت‌های مستقیم شما تا الان: <b>' . fa_digits((int) ($user['direct_ref_count'] ?? 0)) . ' نفر</b>';

        return implode("\n", $lines);
    }

    /**
     * متن ساده (بدون تگ HTML) برای دکمه «ارسال به دوستان» در تلگرام و مینی‌اپ
     */
    public static function renderShareText(
        array $user,
        string $inviteLink,
        ?CommissionSettings $settings = null,
        ?Database $db = null
    ): string {
        $db ??= Database::instance();
        $settings ??= CommissionSettings::current($db);
        $customShare = trim((string) (SettingsService::get('invite_share_text', '') ?? ''));

        if ($customShare !== '') {
            $text = self::replaceVariables($customShare, $user, $inviteLink, null, $settings, $db, false);
            return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // اگر مدیر متن پیام دعوت سفارشی تنظیم کرده است، نسخهٔ بدون HTML آن را نیز برای اشتراک‌گذاری به کار ببر
        $customInvite = trim((string) (SettingsService::get('invite_message_text', '') ?? ''));
        if ($customInvite !== '') {
            $text = self::replaceVariables($customInvite, $user, $inviteLink, null, $settings, $db, false);
            $plain = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            // حذف لینک تکراری چون خود تلگرام پارامتر url را ابتدای پیام می‌گذارد
            if ($inviteLink !== '') {
                $plain = trim(str_replace($inviteLink, '', $plain));
                $plain = (string) preg_replace("~\n{3,}~u", "\n\n", $plain);
            }
            if ($plain !== '') {
                return mb_substr($plain, 0, 900);
            }
        }

        $appName = (string) SettingsService::get('app_name', 'رفرال‌بات');
        $inviterReward = $settings->effectiveDirectReward();
        $inviteeReward = $settings->effectiveInviteeReward();

        if ($inviterReward > 0 && $inviteeReward > 0) {
            return "به {$appName} بپیوند و جایزه بگیر! 🎁\n"
                . 'با عضویت از طریق این لینک و تکمیل ثبت‌نام، ' . wtoman($inviteeReward)
                . ' هدیه خوش‌آمدگویی به کیف پولت واریز می‌شه (پاداش دعوت‌کننده: ' . wtoman($inviterReward) . ') 👇';
        }
        if ($inviteeReward > 0) {
            return "به {$appName} بپیوند و " . wtoman($inviteeReward) . " هدیه خوش‌آمدگویی دریافت کن! 🎁👇";
        }
        if ($inviterReward > 0) {
            return "به {$appName} بپیوند و با دعوت دوستانت بابت هر دعوت موفق " . wtoman($inviterReward) . " پاداش بگیر! 🎁👇";
        }
        return "به {$appName} بپیوند و با دعوت دوستانت درآمد کسب کن! 👇";
    }

    /**
     * تولید پیام خوش‌آمدگویی و راهنمای گام‌به‌گام برای کاربری که از طریق لینک دعوت وارد شده است
     */
    public static function renderReferredWelcomeMessage(
        array $user,
        ?array $referrer = null,
        ?CommissionSettings $settings = null,
        ?Database $db = null
    ): string {
        $db ??= Database::instance();
        $settings ??= CommissionSettings::current($db);
        $users = new UserService($db);

        if ($referrer === null && !empty($user['referred_by'])) {
            $referrer = $users->find((int) $user['referred_by']);
        }

        $inviteLink = '';
        $botUsername = TelegramService::botUsername($db);
        if ($botUsername !== '' && !empty($user['referral_code'])) {
            $inviteLink = 'https://t.me/' . $botUsername . '?start=ref_' . $user['referral_code'];
        }

        $custom = trim((string) (SettingsService::get('start_message_referred', '') ?? ''));
        $checklist = $users->qualificationChecklist($user, $settings);
        $pending = array_values(array_filter($checklist, static fn(array $i): bool => !$i['done']));
        $appendChecklist = SettingsService::bool('referred_welcome_append_checklist', true);

        if ($custom !== '') {
            $hasReqPlaceholder = self::containsRequirementsPlaceholder($custom);
            $rendered = self::replaceVariables($custom, $user, $inviteLink, $referrer, $settings, $db, true);
            if (!$hasReqPlaceholder && $appendChecklist && $pending !== []) {
                $rendered .= "\n\n📋 <b>مراحل تکمیل ثبت‌نام و دریافت پاداش:</b>\n"
                    . self::formatChecklist($checklist);
            }
            return $rendered;
        }

        $name = self::safeDisplayName($user, 'دوست');
        $appName = e((string) SettingsService::get('app_name', 'رفرال‌بات'));
        $refName = $referrer !== null ? self::safeDisplayName($referrer, 'یکی از دوستانتان') : 'لینک دعوت';
        $inviteeReward = $settings->effectiveInviteeReward();
        $inviterReward = $settings->effectiveDirectReward();

        $lines = [];
        $lines[] = "🎉 سلام <b>{$name}</b> عزیز، به <b>{$appName}</b> خوش آمدید!";
        $lines[] = "شما از طریق لینک دعوت <b>{$refName}</b> وارد ربات شدید.";
        $lines[] = '';

        if ($pending !== []) {
            if ($inviteeReward > 0) {
                $lines[] = '🎁 برای شما مبلغ <b>' . wtoman($inviteeReward) . '</b> <b>پاداش خوش‌آمدگویی</b> در نظر گرفته شده است!';
                $lines[] = 'برای فعال‌سازی و واریز این هدیه به کیف پولتان، کافی است مراحل زیر را تکمیل کنید:';
            } else {
                $lines[] = '📋 برای تکمیل ثبت‌نام و فعال‌سازی کامل حساب کاربری و ثبت دعوت، لطفاً مراحل زیر را انجام دهید:';
            }
            $lines[] = '';
            $lines[] = self::formatChecklist($checklist);
            $lines[] = '';
            if ($inviteeReward > 0) {
                $lines[] = '✨ به‌محض تکمیل و تأیید همهٔ مراحل بالا، مبلغ <b>' . wtoman($inviteeReward) . '</b> به‌صورت خودکار به کیف پول شما اضافه می‌شود.';
            } else {
                $lines[] = '✨ پس از تکمیل مراحل بالا، دسترسی کامل شما به تمامی امکانات ربات فعال خواهد شد.';
            }
        } else {
            if ($inviteeReward > 0 && !empty($user['qualified_at'])) {
                $lines[] = '✅ ثبت‌نام شما با موفقیت تکمیل شد و مبلغ <b>' . wtoman($inviteeReward) . '</b> هدیه خوش‌آمدگویی برای حساب شما ثبت گردید!';
            } elseif ($inviteeReward > 0) {
                $lines[] = '🎁 با تکمیل ثبت‌نام شما، مبلغ <b>' . wtoman($inviteeReward) . '</b> هدیه خوش‌آمدگویی به کیف پولتان تعلق می‌گیرد.';
            } else {
                $lines[] = '✅ ثبت‌نام شما از طریق لینک دعوت با موفقیت انجام شد.';
            }
            $lines[] = '';
            if ($inviterReward > 0 && $inviteeReward > 0) {
                $lines[] = '💡 شما هم می‌توانید دوستانتان را دعوت کنید: با هر دعوت موفق <b>' . wtoman($inviterReward) . '</b> پاداش می‌گیرید و دوستتان هم <b>' . wtoman($inviteeReward) . '</b> هدیه خوش‌آمدگویی دریافت می‌کند!';
            } elseif ($inviterReward > 0) {
                $lines[] = '💡 شما هم می‌توانید با دعوت دوستانتان بابت هر دعوت موفق <b>' . wtoman($inviterReward) . '</b> پاداش دریافت کنید!';
            }
            $lines[] = 'از منوی پایین برای مشاهده کیف پول و دریافت لینک دعوت خود استفاده کنید 👇';
        }

        return implode("\n", $lines);
    }

    /** قالب‌بندی خوانای چک‌لیست شرایط */
    public static function formatChecklist(array $checklist): string
    {
        if ($checklist === []) {
            return '✅ شروع ربات و عضویت اولیه';
        }
        $out = [];
        foreach ($checklist as $idx => $item) {
            $icon = !empty($item['done']) ? '✅' : '▫️';
            $status = !empty($item['done']) ? ' <i>(انجام شد)</i>' : '';
            $out[] = $icon . ' ' . fa_digits($idx + 1) . '. ' . e((string) $item['label']) . $status;
        }
        return implode("\n", $out);
    }

    /** فهرست متنی شرایط کلی فعال در سامانه (برای پیام دعوت عمومی) */
    public static function formatGeneralRequirements(CommissionSettings $settings, Database $db): string
    {
        $dummy = [
            'id' => 0,
            'first_name' => '',
            'last_name' => '',
            'phone' => '',
            'channels_verified' => 0,
            'verify_status' => 'pending',
        ];
        $items = (new UserService($db))->qualificationChecklist($dummy, $settings);
        if ($items === []) {
            return '• عضویت در ربات';
        }
        return implode("\n", array_map(
            static fn(array $i): string => '• ' . e((string) $i['label']),
            $items
        ));
    }

    /** جایگذاری همهٔ متغیرهای پویا در یک قالب پیام */
    public static function replaceVariables(
        string $template,
        array $user,
        string $inviteLink,
        ?array $referrer,
        CommissionSettings $settings,
        Database $db,
        bool $escapeHtml = true
    ): string {
        $esc = static fn(string $v): string => $escapeHtml ? e($v) : $v;

        $rawName = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
        $name = $rawName !== '' ? $rawName : 'کاربر';
        $rawUsername = ltrim(trim((string) ($user['username'] ?? '')), '@');
        $username = $rawUsername !== '' ? '@' . $rawUsername : $name;

        $refRaw = $referrer !== null
            ? trim((string) (($referrer['first_name'] ?? '') . ' ' . ($referrer['last_name'] ?? '')))
            : '';
        $refName = $refRaw !== '' ? $refRaw : 'معرف شما';

        $appName = (string) SettingsService::get('app_name', 'رفرال‌بات');
        $code = (string) ($user['referral_code'] ?? '');
        $minWithdraw = SettingsService::int('min_withdraw_amount', 30000);

        $inviterReward = $settings->effectiveDirectReward();
        $inviteeReward = $settings->effectiveInviteeReward();
        $totalReward = $inviterReward + $inviteeReward;

        $users = new UserService($db);
        $hasUserChecklist = !empty($user['id']) && !empty($user['referred_by']);
        $checklist = $hasUserChecklist ? $users->qualificationChecklist($user, $settings) : [];
        $pending = array_values(array_filter($checklist, static fn(array $i): bool => !$i['done']));

        $requirementsText = $hasUserChecklist
            ? self::formatChecklist($checklist)
            : self::formatGeneralRequirements($settings, $db);
        $pendingText = $pending !== []
            ? self::formatChecklist($pending)
            : '✅ تمامی مراحل ثبت‌نام تکمیل شده است.';

        if (!$escapeHtml) {
            $requirementsText = html_entity_decode(strip_tags($requirementsText), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $pendingText = html_entity_decode(strip_tags($pendingText), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $replacements = [
            // لینک و کد دعوت
            '{invite_link}' => $esc($inviteLink),
            '{link}' => $esc($inviteLink),
            '[لینک دعوت]' => $esc($inviteLink),
            '{referral_code}' => $esc($code),
            '{code}' => $esc($code),
            '[کد دعوت]' => $esc($code),

            // پاداش دعوت‌کننده (مستقیم)
            '{inviter_reward}' => wtoman($inviterReward),
            '{direct_reward}' => wtoman($inviterReward),
            '[پاداش دعوت‌کننده]' => wtoman($inviterReward),
            '[مبلغ پاداش دعوت‌کننده]' => wtoman($inviterReward),
            '{inviter_reward_number}' => wtoman($inviterReward, false),

            // پاداش دعوت‌شده (خوش‌آمدگویی)
            '{invitee_reward}' => wtoman($inviteeReward),
            '{welcome_reward}' => wtoman($inviteeReward),
            '[پاداش دعوت‌شده]' => wtoman($inviteeReward),
            '[مبلغ پاداش دعوت‌شده]' => wtoman($inviteeReward),
            '{invitee_reward_number}' => wtoman($inviteeReward, false),

            // مجموع دو پاداش
            '{total_reward}' => wtoman($totalReward),

            // اطلاعات کاربر، معرف و سامانه
            '{name}' => $esc($name),
            '[نام]' => $esc($name),
            '{username}' => $esc($username),
            '{referrer_name}' => $esc($refName),
            '[نام معرف]' => $esc($refName),
            '{app_name}' => $esc($appName),
            '[نام ربات]' => $esc($appName),
            '{min_withdraw}' => wtoman($minWithdraw),
            '{max_levels}' => fa_digits($settings->maxLevels),
            '{direct_refs}' => fa_digits((int) ($user['direct_ref_count'] ?? 0)),

            // چک‌لیست پویای شرایط
            '{requirements}' => $requirementsText,
            '[شرایط]' => $requirementsText,
            '[مراحل تکمیل]' => $requirementsText,
            '{pending_requirements}' => $pendingText,
        ];

        return strtr($template, $replacements);
    }

    private static function containsLinkPlaceholder(string $template): bool
    {
        foreach (['{invite_link}', '{link}', '[لینک دعوت]'] as $token) {
            if (str_contains($template, $token)) {
                return true;
            }
        }
        return false;
    }

    private static function containsRequirementsPlaceholder(string $template): bool
    {
        foreach (['{requirements}', '{pending_requirements}', '[شرایط]', '[مراحل تکمیل]'] as $token) {
            if (str_contains($template, $token)) {
                return true;
            }
        }
        return false;
    }

    private static function safeDisplayName(array $user, string $fallback): string
    {
        $raw = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
        return $raw !== '' ? e($raw) : e($fallback);
    }
}
