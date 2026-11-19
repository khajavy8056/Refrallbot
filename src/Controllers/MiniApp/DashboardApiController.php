<?php
declare(strict_types=1);

namespace RB\Controllers\MiniApp;

use RB\Bot\BotHandler;
use RB\Bot\TransactionLabels;
use RB\Http\Controller;
use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Services\CampaignService;
use RB\Services\CommissionSettings;
use RB\Services\InviteContentService;
use RB\Services\MembershipService;
use RB\Services\Notifier;
use RB\Services\RankingService;
use RB\Services\ReferralMessageService;
use RB\Services\SettingsService;
use RB\Services\SupportService;
use RB\Services\TelegramService;
use RB\Services\TelegramWebApp;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Services\WebAppAuthService;
use RB\Services\WithdrawalService;
use RB\Support\Csrf;
use RB\Support\Database;
use RB\Support\Money;
use RB\Support\Request;

/**
 * API پنل کاربری Mini App چندپیام‌رسانی (تلگرام، بله، ایتا)
 * همه پاسخ‌ها JSON؛ احراز هویت با سشن امن مینی‌اپ که با initData امضاشده ایجاد شده است.
 */
final class DashboardApiController extends Controller
{
    private function user(): ?array
    {
        $user = TelegramWebApp::currentUser();
        if ($user === null) {
            $this->json([
                'ok' => false,
                'error' => 'برای استفاده از پنل، ابتدا از داخل پیام‌رسان وارد شوید.',
                'need_auth' => true,
            ], 401);
        }
        return $user;
    }

    /** شامل csrf token و اطلاعات پیام‌رسان جاری */
    public function bootstrap(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        $platform = WebAppAuthService::currentPlatform();
        $this->json([
            'ok' => true,
            'csrf' => Csrf::token(),
            'user_id' => (int) $user['id'],
            'platform' => $platform,
            'platform_label' => MessengerCapabilities::label($platform),
        ]);
    }

    public function dashboard(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        $db = Database::instance();
        $uid = (int) $user['id'];
        $platform = WebAppAuthService::currentPlatform();
        $extId = WebAppAuthService::currentExternalId();

        $wallet = new WalletService($db);
        $summary = $wallet->summary($uid);
        $reconcile = $wallet->reconcile($uid);

        $inviteSvc = new InviteContentService($db);
        $studio = $inviteSvc->buildUserStudioPayload($user, $platform);
        $link = (string) ($studio['primary_link'] ?? '');
        if ($link === '') {
            $tg = TelegramService::settings($db);
            $botUsername = (string) ($tg['bot_username'] ?? '');
            $link = $botUsername !== ''
                ? 'https://t.me/' . $botUsername . '?start=' . $user['referral_code']
                : (string) ($studio['landing_link'] ?? SettingsService::get('bot_link_base', ''));
        }

        $lastTx = $db->all(
            'SELECT * FROM wallet_transactions WHERE user_id = :u ORDER BY id DESC LIMIT 6',
            ['u' => $uid]
        );
        $rank = RankingService::enabled() ? RankingService::rankOf($uid) : null;
        $campaignSvc = new CampaignService($db);
        $activeCampaigns = [];
        foreach ($campaignSvc->activeCampaigns() as $campaign) {
            $progress = $campaignSvc->progressFor($campaign, $uid);
            $activeCampaigns[] = [
                'title' => $campaign['title'],
                'description' => $campaign['description'],
                'progress' => $progress['progress'] ?? 0,
                'required' => (int) ($campaign['required_invites'] ?? 0),
                'reward' => (int) ($campaign['fixed_reward'] ?? 0),
                'ends_at' => $campaign['ends_at'],
            ];
        }

        $userSvc = new UserService($db);
        $analytics = self::buildUserAnalytics($db, $user, $summary, $userSvc);
        $userLevel = (new \RB\Services\UserLevelService($db))->profile($uid);
        if ($userLevel !== null) {
            $current = $userLevel['current'];
            $next = $userLevel['next'];
            $start = (int) ($current['min_invites'] ?? 0);
            $target = (int) ($next['min_invites'] ?? 0);
            $analytics['tier'] = [
                'name' => $current['name'] ?? 'شروع‌کننده',
                'icon' => $current['icon'] ?? '🌱',
                'badge' => $current['badge'] ?? '',
                'color' => $current['color'] ?? '#6366f1',
                'benefits' => $current['benefits'] ?? '',
                'qualified_count' => $userLevel['count'],
                'next_name' => $next['name'] ?? null,
                'remaining' => $next ? max(0, $target - $userLevel['count']) : 0,
                'progress_pct' => $next ? min(100, (int) round(100 * ($userLevel['count'] - $start) / max(1, $target - $start))) : 100,
            ];
        }
        $comSettings = CommissionSettings::current($db);
        $pendingReqs = (!empty($user['referred_by']) && empty($user['qualified_at']))
            ? $userSvc->pendingRequirements($user, $comSettings)
            : [];

        $inviteHtml = ReferralMessageService::renderInviteMessage($user, $link, $comSettings, $db);
        $invitePlain = trim(html_entity_decode(strip_tags($inviteHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $shareText = ReferralMessageService::renderShareText($user, $link, $comSettings, $db);
        if (!empty($studio['messages'][0])) {
            $invitePlain = (string) $studio['messages'][0]['text'];
            $shareText = (string) $studio['messages'][0]['share_text'];
        }

        // حساب‌های پیام‌رسان متصل کاربر
        $accounts = array_map(static function (array $acc): array {
            $p = (string) $acc['platform'];
            return [
                'platform' => $p,
                'label' => MessengerCapabilities::label($p),
                'external_user_id' => (string) $acc['external_user_id'],
                'username' => $acc['username'] ?? null,
                'is_primary' => !empty($acc['is_primary']),
                'can_send_message' => !empty($acc['can_send_message']),
                'linked_at_fa' => !empty($acc['linked_at']) ? fa_date($acc['linked_at'], false) : '',
            ];
        }, $userSvc->messengerAccounts($uid));

        $currAcc = $userSvc->messengerAccountForPlatform($uid, $platform);
        $canSendMsg = $currAcc ? !empty($currAcc['can_send_message']) : true;

        // وضعیت کانال‌های اجباری (بومی + میان‌پیام‌رسانی قابل راستی‌آزمایی) بدون تماس شبکه در بارگذاری اولیه
        $memSvc = new MembershipService($db);
        $reqChannels = [];
        if ($memSvc->enforcementEnabled() && empty($user['channels_verified'])) {
            foreach ($memSvc->activeChannels($platform) as $ch) {
                $hostPlat = strtolower(trim((string) ($ch['platform'] ?? $platform)));
                if ($hostPlat === 'all' || !MessengerCapabilities::isSupported($hostPlat)) {
                    $hostPlat = $platform;
                }
                $isCross = ($hostPlat !== $platform);
                $hostSupportsApi = MessengerCapabilities::supports($hostPlat, 'channel_check_api');
                if ($isCross && !$hostSupportsApi) {
                    continue;
                }
                $linkedOnHost = $isCross ? ($userSvc->messengerAccountForPlatform($uid, $hostPlat) !== null) : true;
                $reqChannels[] = [
                    'id' => (int) $ch['id'],
                    'title' => (string) $ch['title'],
                    'platform' => $hostPlat,
                    'platform_badge' => MessengerCapabilities::badge($hostPlat),
                    'is_cross_platform' => $isCross,
                    'needs_account_link' => $isCross && !$linkedOnHost,
                    'join_link' => (string) (MembershipService::channelUrl($ch) ?? ''),
                    'self_confirm' => !$isCross && ((($ch['check_mode'] ?? 'api') === 'self_confirm') || !$hostSupportsApi),
                ];
            }
        }

        $directRefs = (int) ($user['direct_ref_count'] ?? 0);
        $totalRefs = (int) ($user['total_ref_count'] ?? 0);
        $indirectRefs = max(0, $totalRefs - $directRefs);
        $displayExtId = $extId !== '' ? $extId : ((int) $user['telegram_id'] > 0 ? (string) $user['telegram_id'] : (string) $uid);

        $this->json([
            'ok' => true,
            'platform' => [
                'code' => $platform,
                'label' => MessengerCapabilities::label($platform),
                'can_request_contact' => MessengerCapabilities::supports($platform, 'request_contact'),
                'can_request_write' => ($platform === MessengerCapabilities::EITAA),
                'can_send_message' => $canSendMsg,
                'supports_bot_banner' => (bool) ($studio['supports_bot_send'] ?? false),
                'channel_check_api' => MessengerCapabilities::supports($platform, 'channel_check_api'),
            ],
            'user' => [
                'id' => $uid,
                'telegram_id' => $displayExtId,
                'external_id' => $displayExtId,
                'entry_platform' => (string) ($user['entry_platform'] ?? MessengerCapabilities::TELEGRAM),
                'entry_platform_label' => MessengerCapabilities::label((string) ($user['entry_platform'] ?? MessengerCapabilities::TELEGRAM)),
                'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
                'first_name' => (string) ($user['first_name'] ?? ''),
                'last_name' => (string) ($user['last_name'] ?? ''),
                'username' => $user['username'],
                'phone' => $user['phone'],
                'channels_verified' => !empty($user['channels_verified']),
                'profile_complete' => (bool) ($user['phone'] && $user['first_name']),
                'status' => $user['status'],
                'joined_at' => $user['joined_at'],
                'joined_at_fa' => fa_date($user['joined_at']),
                'joined_date_fa' => fa_date($user['joined_at'], false),
                'referral_code' => $user['referral_code'],
                'invite_link' => $link,
                'gateway_link' => (string) ($studio['landing_link'] ?? ''),
                'referred_by' => $user['referred_by'] ? (int) $user['referred_by'] : null,
                'qualified' => !empty($user['qualified_at']),
                'verify_status' => $user['verify_status'],
                'rank' => $rank,
            ],
            'wallet' => $summary,
            'user_level' => $userLevel,
            'referral_rewards' => [
                'inviter_enabled' => $comSettings->directEnabled,
                'inviter_reward' => $comSettings->directEnabled ? ($userLevel['next_invite_reward'] ?? $comSettings->effectiveDirectReward()) : 0,
                'inviter_reward_fa' => wtoman($comSettings->directEnabled ? ($userLevel['next_invite_reward'] ?? $comSettings->effectiveDirectReward()) : 0),
                'invitee_enabled' => $comSettings->inviteeEnabled,
                'invitee_reward' => $comSettings->effectiveInviteeReward(),
                'invitee_reward_fa' => wtoman($comSettings->effectiveInviteeReward()),
                'multilevel_enabled' => $comSettings->multiLevelEnabled,
                'max_levels' => $comSettings->maxLevels,
                'share_text' => $shareText,
                'invite_text' => $invitePlain,
                'pending_requirements' => array_map(static fn(array $i): string => (string) $i['label'], $pendingReqs),
                'need_phone' => $memSvc->contactRequired() && empty($user['phone']),
                'required_channels' => $reqChannels,
            ],
            'invite_studio' => $studio,
            'connected_messengers' => $accounts,
            'reconcile' => [
                'match' => (bool) ($reconcile['match'] ?? true),
                'ledger' => (int) ($reconcile['ledger'] ?? 0),
            ],
            'counts' => [
                'direct_refs' => $directRefs,
                'indirect_refs' => $indirectRefs,
                'total_refs' => $totalRefs,
            ],
            'analytics' => $analytics,
            'withdraw' => [
                'enabled' => SettingsService::bool('withdrawal_enabled', true),
                'min_amount' => SettingsService::int('min_withdraw_amount', 30000),
                'max_open' => SettingsService::int('max_open_withdrawals', 1),
            ],
            'recent_transactions' => array_map([self::class, 'txToArray'], $lastTx),
            'campaigns' => $activeCampaigns,
            'csrf' => Csrf::token(),
        ]);
    }

    /** آمار و سری‌های زمانی دعوت و تفکیک دقیق لایه ۰ (مستقیم) و لایه‌های ۱+ (غیرمستقیم) */
    private static function buildUserAnalytics(Database $db, array $user, array $walletSummary, UserService $userSvc): array
    {
        $uid = (int) $user['id'];
        $days30Start = date('Y-m-d', time() - 29 * 86400) . ' 00:00:00';
        $todayStr = date('Y-m-d');

        $baselineBefore30 = (int) $db->value(
            'SELECT COUNT(*) FROM users WHERE referred_by = :u AND joined_at < :s',
            ['u' => $uid, 's' => $days30Start]
        );
        $inviteRows = $db->all(
            'SELECT DATE(joined_at) AS d, COUNT(*) AS c FROM users WHERE referred_by = :u AND joined_at >= :s GROUP BY DATE(joined_at)',
            ['u' => $uid, 's' => $days30Start]
        );
        $earnRows = $db->all(
            "SELECT DATE(created_at) AS d, COALESCE(SUM(amount), 0) AS s FROM wallet_transactions
             WHERE user_id = :u AND status = 'completed' AND direction = 'credit'
               AND type IN ('commission_direct','commission_welcome','commission_level','campaign_reward')
               AND created_at >= :s
             GROUP BY DATE(created_at)",
            ['u' => $uid, 's' => $days30Start]
        );

        $invMap = [];
        foreach ($inviteRows as $r) {
            $invMap[(string) $r['d']] = (int) $r['c'];
        }
        $earnMap = [];
        foreach ($earnRows as $r) {
            $earnMap[(string) $r['d']] = (int) $r['s'];
        }

        $labels30 = [];
        $daily30 = [];
        $cumulative30 = [];
        $earnings30 = [];
        $running = $baselineBefore30;
        $invitesToday = 0;
        $invitesWeek = 0;
        $invitesMonth = 0;
        $bestDay = 0;
        $bestDayLabel = '—';

        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', time() - $i * 86400);
            $jalali = fa_date($d . ' 12:00:00', false);
            $parts = explode('/', $jalali);
            $lbl = count($parts) === 3 ? ($parts[1] . '/' . $parts[2]) : fa_digits(substr($d, 5));

            $cnt = $invMap[$d] ?? 0;
            $ern = $earnMap[$d] ?? 0;
            $running += $cnt;

            $labels30[] = $lbl;
            $daily30[] = $cnt;
            $cumulative30[] = $running;
            $earnings30[] = $ern;

            $invitesMonth += $cnt;
            if ($i < 7) {
                $invitesWeek += $cnt;
            }
            if ($d === $todayStr) {
                $invitesToday = $cnt;
            }
            if ($cnt >= $bestDay && $cnt > 0) {
                $bestDay = $cnt;
                $bestDayLabel = $lbl;
            }
        }

        $qualifiedDirect = (int) $db->value(
            'SELECT COUNT(*) FROM users WHERE referred_by = :u AND qualified_at IS NOT NULL',
            ['u' => $uid]
        );
        $totalDirect = max((int) ($user['direct_ref_count'] ?? 0), $running);
        $pendingDirect = max(0, $totalDirect - $qualifiedDirect);
        $qualRate = $totalDirect > 0 ? (int) round(($qualifiedDirect / $totalDirect) * 100) : 0;

        $layerEarn = $userSvc->layerEarningsSummary($uid);
        $levelRows = $db->all(
            'SELECT rr.depth, COUNT(*) AS c FROM referral_relations rr WHERE rr.ancestor_id = :a GROUP BY rr.depth ORDER BY rr.depth',
            ['a' => $uid]
        );
        $earnByLayer = [];
        if (!empty($layerEarn['layers']) && is_array($layerEarn['layers'])) {
            foreach ($layerEarn['layers'] as $le) {
                $earnByLayer[(int) ($le['layer'] ?? 0)] = (int) ($le['paid_amount'] ?? $le['credited_amount'] ?? ($le['amount'] ?? 0));
            }
        }
        $levels = array_map(static function (array $l) use ($earnByLayer): array {
            $depth = (int) $l['depth'];
            $layer = max(0, $depth - 1);
            $earned = $earnByLayer[$layer] ?? 0;
            return [
                'depth' => $depth,
                'layer' => $layer,
                'label' => $layer === 0 ? 'لایه ۰ (دعوت مستقیم)' : ('لایه ' . fa_digits($layer) . ' (غیرمستقیم)'),
                'count' => (int) $l['c'],
                'earned' => $earned,
                'earned_fa' => wtoman($earned),
            ];
        }, $levelRows);

        $milestones = [
            ['min' => 0,   'max' => 5,   'name' => 'شروع‌کننده',       'icon' => '🌱', 'next' => 'برنزی'],
            ['min' => 5,   'max' => 15,  'name' => 'دعوت‌کننده برنزی',  'icon' => '🥉', 'next' => 'نقره‌ای'],
            ['min' => 15,  'max' => 50,  'name' => 'دعوت‌کننده نقره‌ای', 'icon' => '🥈', 'next' => 'طلایی'],
            ['min' => 50,  'max' => 150, 'name' => 'دعوت‌کننده طلایی',  'icon' => '🥇', 'next' => 'سفیر الماسی'],
            ['min' => 150, 'max' => 0,   'name' => 'سفیر الماسی',      'icon' => '💎', 'next' => null],
        ];
        $tier = $milestones[0];
        foreach ($milestones as $ms) {
            if ($totalDirect >= $ms['min']) {
                $tier = $ms;
            }
        }
        $tierPct = $tier['max'] > 0
            ? min(100, (int) round((($totalDirect - $tier['min']) / max(1, $tier['max'] - $tier['min'])) * 100))
            : 100;

        return [
            'labels_14' => array_slice($labels30, -14),
            'daily_14' => array_slice($daily30, -14),
            'cumulative_14' => array_slice($cumulative30, -14),
            'earnings_14' => array_slice($earnings30, -14),
            'labels_30' => $labels30,
            'daily_30' => $daily30,
            'cumulative_30' => $cumulative30,
            'earnings_30' => $earnings30,
            'kpis' => [
                'today' => $invitesToday,
                'week' => $invitesWeek,
                'month' => $invitesMonth,
                'best_day' => $bestDay,
                'best_day_label' => $bestDayLabel,
                'qualified_direct' => $qualifiedDirect,
                'pending_direct' => $pendingDirect,
                'qualification_rate' => $qualRate,
            ],
            'earnings_breakdown' => [
                'direct' => (int) ($walletSummary['direct_earned'] ?? 0),
                'welcome' => (int) ($walletSummary['welcome_earned'] ?? 0),
                'layer' => (int) ($walletSummary['layer_earned'] ?? 0),
                'campaign' => (int) ($walletSummary['campaign_earned'] ?? 0),
            ],
            'levels' => $levels,
            'tier' => [
                'name' => $tier['name'],
                'icon' => $tier['icon'],
                'next_name' => $tier['next'],
                'next_target' => $tier['max'],
                'remaining' => $tier['max'] > 0 ? max(0, $tier['max'] - $totalDirect) : 0,
                'progress_pct' => $tierPct,
            ],
        ];
    }

    public function referrals(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        $db = Database::instance();
        $uid = (int) $user['id'];
        $platform = WebAppAuthService::currentPlatform();
        $userSvc = new UserService($db);
        $registry = new MessengerRegistry($db);

        $direct = $db->all(
            'SELECT id, first_name, last_name, username, telegram_id, entry_platform, joined_at, qualified_at, status,
                    direct_ref_count, total_earned
             FROM users WHERE referred_by = :r ORDER BY joined_at DESC LIMIT 100',
            ['r' => $uid]
        );

        $layerEarn = $userSvc->layerEarningsSummary($uid);
        $earnMap = [];
        if (!empty($layerEarn['layers']) && is_array($layerEarn['layers'])) {
            foreach ($layerEarn['layers'] as $le) {
                $earnMap[(int) ($le['layer'] ?? 0)] = [
                    'amount' => (int) ($le['paid_amount'] ?? $le['credited_amount'] ?? ($le['amount'] ?? 0)),
                    'pending_amount' => (int) ($le['pending_amount'] ?? 0),
                    'count' => (int) ($le['users_count'] ?? $le['credited_count'] ?? ($le['count'] ?? 0)),
                ];
            }
        }

        $levelRows = $db->all(
            'SELECT rr.depth,
                    COUNT(*) AS c,
                    SUM(CASE WHEN u.qualified_at IS NOT NULL THEN 1 ELSE 0 END) AS q
             FROM referral_relations rr
             JOIN users u ON u.id = rr.descendant_id
             WHERE rr.ancestor_id = :a
             GROUP BY rr.depth
             ORDER BY rr.depth',
            ['a' => $uid]
        );

        $levels = array_map(static function (array $l) use ($earnMap): array {
            $depth = (int) $l['depth'];
            $layer = max(0, $depth - 1);
            $earned = (int) ($earnMap[$layer]['amount'] ?? 0);
            return [
                'level' => $depth,
                'layer' => $layer,
                'is_direct' => ($layer === 0),
                'label' => $layer === 0 ? 'لایه ۰ — دعوت مستقیم' : ('لایه ' . fa_digits($layer) . ' — غیرمستقیم'),
                'count' => (int) $l['c'],
                'qualified' => (int) ($l['q'] ?? 0),
                'earned' => $earned,
                'earned_fa' => wtoman($earned),
            ];
        }, $levelRows);

        // نمونه زیرمجموعه‌های غیرمستقیم (لایه‌های ۱ تا ۸) با حفظ حریم خصوصی
        $indirectRows = $db->all(
            'SELECT rr.depth, u.first_name, u.last_name, u.entry_platform, u.joined_at, u.qualified_at
             FROM referral_relations rr
             JOIN users u ON u.id = rr.descendant_id
             WHERE rr.ancestor_id = :a AND rr.depth >= 2
             ORDER BY u.joined_at DESC
             LIMIT 30',
            ['a' => $uid]
        );

        $directCount = (int) ($user['direct_ref_count'] ?? 0);
        $totalCount = (int) ($user['total_ref_count'] ?? 0);

        $this->json([
            'ok' => true,
            'invite_link' => $registry->buildReferralLink($platform, (string) $user['referral_code']),
            'gateway_link' => $registry->buildLandingLink((string) $user['referral_code'], $platform),
            'referral_code' => $user['referral_code'],
            'direct' => array_map(static function (array $r): array {
                $ep = (string) ($r['entry_platform'] ?? MessengerCapabilities::TELEGRAM);
                return [
                    'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'کاربر',
                    'username' => $r['username'],
                    'platform' => $ep,
                    'platform_label' => MessengerCapabilities::label($ep),
                    'joined_at' => fa_date($r['joined_at'], false),
                    'qualified' => !empty($r['qualified_at']),
                    'status' => $r['status'],
                    'refs' => (int) $r['direct_ref_count'],
                ];
            }, $direct),
            'indirect' => array_map(static function (array $r): array {
                $ep = (string) ($r['entry_platform'] ?? MessengerCapabilities::TELEGRAM);
                $layer = max(1, (int) $r['depth'] - 1);
                $fn = trim((string) ($r['first_name'] ?? ''));
                $masked = $fn !== '' ? (mb_substr($fn, 0, 2) . '•••') : 'کاربر شبکه';
                return [
                    'name' => $masked,
                    'layer' => $layer,
                    'layer_label' => 'لایه ' . fa_digits($layer),
                    'platform_label' => MessengerCapabilities::label($ep),
                    'joined_at' => fa_date($r['joined_at'], false),
                    'qualified' => !empty($r['qualified_at']),
                ];
            }, $indirectRows),
            'levels' => $levels,
            'totals' => [
                'direct' => $directCount,
                'indirect' => max(0, $totalCount - $directCount),
                'network' => $totalCount,
                'direct_earned' => (int) ($earnMap[0]['amount'] ?? 0),
                'indirect_earned' => array_sum(array_map(
                    static fn(int $k) => $k >= 1 ? (int) ($earnMap[$k]['amount'] ?? 0) : 0,
                    array_keys($earnMap)
                )),
            ],
        ]);
    }

    /** ارسال بنر تبلیغاتی انتخابی کاربر + کپشن و لینک دعوت به گفتگوی خصوصی او در پیام‌رسان */
    public function sendInviteBanner(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        Csrf::verify();
        $platform = WebAppAuthService::currentPlatform();
        if (!MessengerCapabilities::supports($platform, 'send_photo') || $platform === MessengerCapabilities::EITAA) {
            $this->fail('ارسال مستقیم بنر به گفتگو در این پیام‌رسان پشتیبانی نمی‌شود؛ از دکمه اشتراک‌گذاری یا ذخیره تصویر همراه با کپی متن دعوت استفاده کنید.');
            return;
        }

        $bannerId = (int) ($_POST['banner_id'] ?? 0);
        $withFullCaption = !isset($_POST['full_caption']) || !empty($_POST['full_caption']);
        $db = Database::instance();
        $userSvc = new UserService($db);
        $acc = $userSvc->messengerAccountForPlatform((int) $user['id'], $platform);
        $chatId = $acc !== null && !empty($acc['external_chat_id'])
            ? (string) $acc['external_chat_id']
            : ($acc !== null && !empty($acc['external_user_id']) ? (string) $acc['external_user_id'] : WebAppAuthService::currentExternalId());

        if ($chatId === '' && $platform === MessengerCapabilities::TELEGRAM && (int) $user['telegram_id'] > 0) {
            $chatId = (string) $user['telegram_id'];
        }
        if ($chatId === '') {
            $this->fail('شناسه گفتگو یافت نشد. ابتدا ربات را در پیام‌رسان باز کنید.');
            return;
        }

        try {
            $driver = (new MessengerRegistry($db))->driver($platform);
            $handler = new BotHandler($db, $driver);
            $res = $handler->sendInviteBannerToUser($user, $chatId, $bannerId, $withFullCaption);
            if (empty($res['ok'])) {
                $this->fail((string) ($res['error'] ?? 'ارسال بنر به گفتگو ناموفق بود.'));
                return;
            }
            $this->ok('بنر تبلیغاتی همراه با لینک دعوت اختصاصی شما به گفتگوی ربات ارسال شد! کافی است آن را برای دوستان فوروارد کنید.');
        } catch (\Throwable $e) {
            $this->fail('ارسال بنر انجام نشد: ' . $e->getMessage());
        }
    }

    /** ثبت احصای آماری کپی/اشتراک قالب پیام یا بنر در استودیوی دعوت */
    public function trackInviteShare(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        Csrf::verify();
        $type = (string) ($_POST['type'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        if (in_array($type, ['message', 'banner'], true) && $id > 0) {
            try {
                (new InviteContentService())->recordShare($type, $id);
            } catch (\Throwable) {
            }
        }
        $this->ok('ثبت شد.');
    }

    /** ساخت کد یک‌بارمصرف ۱۵ دقیقه‌ای برای اتصال پیام‌رسان دیگر به همین حساب */
    public function createLinkToken(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        Csrf::verify();
        try {
            $tok = (new UserService())->createLinkToken((int) $user['id'], 900);
            $this->ok('کد اتصال یک‌بارمصرف ساخته شد. تا ۱۵ دقیقه در پیام‌رسان دیگر وارد کنید.', [
                'token' => $tok['code'],
                'code' => $tok['code'],
                'start_param' => $tok['start_param'],
                'expires_at' => fa_date($tok['expires_at']),
                'ttl_minutes' => (int) ($tok['ttl_minutes'] ?? 15),
            ]);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    /** مصرف کد اتصال ساخته‌شده در پیام‌رسان دیگر و اتصال امن پیام‌رسان فعلی به حساب اصلی */
    public function redeemLinkToken(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        Csrf::verify();
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($code === '') {
            $this->fail('کد اتصال (مانند LNK-...) را وارد کنید.');
            return;
        }
        $platform = WebAppAuthService::currentPlatform();
        $extId = WebAppAuthService::currentExternalId();
        if ($extId === '') {
            $this->fail('شناسه پیام‌رسان جاری قابل تشخیص نیست.');
            return;
        }

        $res = (new UserService())->redeemLinkToken($code, $platform, [
            'id' => $extId,
            'chat_id' => $extId,
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'username' => $user['username'] ?? null,
        ]);
        if (empty($res['ok'])) {
            $this->fail((string) ($res['error'] ?? 'اتصال حساب انجام نشد.'));
            return;
        }

        // به‌روزرسانی سشن به حساب اصلی متصل‌شده
        $mergedUser = $res['user'] ?? null;
        if (is_array($mergedUser) && !empty($mergedUser['id'])) {
            $_SESSION['miniapp_user_id'] = (int) $mergedUser['id'];
            $_SESSION['miniapp_telegram_id'] = (int) $mergedUser['telegram_id'];
        }
        $this->ok((string) ($res['message'] ?? 'پیام‌رسان شما با موفقیت به حساب اصلی متصل شد!'));
    }

    /** ذخیره شماره موبایل دریافت‌شده از SDK مینی‌اپ (ایتا، بله، تلگرام) */
    public function savePhone(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        Csrf::verify();
        $rawPhone = trim((string) ($_POST['phone'] ?? ''));
        $digits = preg_replace('~\D+~', '', $rawPhone) ?? '';
        if (strlen($digits) < 10 || strlen($digits) > 15) {
            $this->fail('شماره تماس معتبر نیست.');
            return;
        }
        $normalized = '+' . ltrim($digits, '0');
        if (str_starts_with($digits, '09') && strlen($digits) === 11) {
            $normalized = '+98' . substr($digits, 1);
        } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
            $normalized = '+98' . $digits;
        }

        $db = Database::instance();
        $platform = WebAppAuthService::currentPlatform();
        $userSvc = new UserService($db);
        $userSvc->setPhone((int) $user['id'], $normalized);
        $fresh = $userSvc->find((int) $user['id']) ?? $user;
        $qualified = $userSvc->maybeQualify($fresh, false, $platform);
        Notifier::flushDeferred();

        $this->ok(
            $qualified
                ? 'شماره تماس ثبت شد و پاداش شما فعال گردید! 🎁'
                : 'شماره تماس با موفقیت ثبت شد.',
            ['phone' => $normalized, 'qualified' => $qualified]
        );
    }

    /** ثبت مجوز ارسال پیام در ایتا پس از فراخوانی Eitaa.WebApp.requestWriteAccess */
    public function saveWriteAccess(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        Csrf::verify();
        $allowed = !empty($_POST['allowed']);
        $platform = WebAppAuthService::currentPlatform();
        $extId = WebAppAuthService::currentExternalId();
        if ($extId !== '') {
            (new UserService())->touchMessengerActivity($platform, $extId, (int) $user['id'], [], $allowed);
        }
        $this->ok($allowed ? 'دریافت اعلان در پیام‌رسان فعال شد.' : 'وضعیت ذخیره شد.');
    }

    /** بررسی یا خوداظهاری عضویت در کانال‌های اجباری داخل مینی‌اپ */
    public function verifyChannels(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        Csrf::verify();
        $db = Database::instance();
        $platform = WebAppAuthService::currentPlatform();
        $extId = WebAppAuthService::currentExternalId();
        $userSvc = new UserService($db);
        $memSvc = new MembershipService($db);
        $selfConfirm = !empty($_POST['self_confirm']);

        if ($selfConfirm) {
            $confirm = $memSvc->confirmSelfDeclaredChannels($user, $platform, $extId !== '' ? $extId : null);
            if ($confirm['all_passed']) {
                $fresh = $userSvc->find((int) $user['id']) ?? $user;
                $qualified = $userSvc->maybeQualify($fresh, false, $platform);
                Notifier::flushDeferred();
                $this->ok($qualified ? 'عضویت ثبت شد و پاداش شما فعال گردید! 🎁' : 'عضویت شما با موفقیت ثبت و تأیید شد.', ['qualified' => $qualified]);
                return;
            }
            $gate = $confirm['gate'];
        } else {
            $gate = $memSvc->gate($user, $platform, $extId !== '' ? $extId : null);
        }

        if (!$gate['passed'] && ($gate['reason'] ?? '') === 'channels') {
            if (!empty($gate['needs_link_platforms'])) {
                $firstHost = (string) $gate['needs_link_platforms'][0];
                $prompt = $memSvc->buildCrossPlatformLinkPrompt((int) $user['id'], $firstHost);
                $names = implode('، ', array_map(static fn(array $c): string => (string) $c['title'], $gate['channels'] ?? []));
                $this->json([
                    'ok' => false,
                    'needs_link' => true,
                    'link_prompt' => $prompt,
                    'error' => 'برای تأیید واقعی عضویت در «' . $names . '»، ابتدا حساب ' . $prompt['badge'] . ' خود را با کد ' . $prompt['code'] . ' متصل کرده، در کانال عضو شوید و دوباره بررسی عضویت را بزنید.',
                ], 422);
                return;
            }
            $names = implode('، ', array_map(static fn(array $c): string => (string) $c['title'], $gate['channels'] ?? []));
            $this->fail('ابتدا در کانال‌های زیر عضو شوید و دوباره بررسی عضویت را بزنید: ' . $names);
            return;
        }

        $userSvc->markChannelsVerified((int) $user['id']);
        $fresh = $userSvc->find((int) $user['id']) ?? $user;
        $qualified = $userSvc->maybeQualify($fresh, false, $platform);
        Notifier::flushDeferred();
        $this->ok($qualified ? 'عضویت تأیید شد و پاداش شما فعال گردید! 🎁' : 'عضویت در تمام کانال‌های الزامی با موفقیت تأیید شد.', ['qualified' => $qualified]);
    }

    public function transactions(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $type = trim((string) ($_GET['type'] ?? ''));
        [$rows, $total] = (new WalletService())->transactions((int) $user['id'], $type !== '' ? ['type' => $type] : [], $page, 20);
        $this->json([
            'ok' => true,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / 20)),
            'rows' => array_map([self::class, 'txToArray'], $rows),
        ]);
    }

    public function withdrawals(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        $service = new WithdrawalService();
        $rows = $service->forUser((int) $user['id'], 50);
        $this->json([
            'ok' => true,
            'min_amount' => SettingsService::int('min_withdraw_amount', 30000),
            'enabled' => SettingsService::bool('withdrawal_enabled', true),
            'rows' => array_map(static fn($r) => [
                'id' => (int) $r['id'],
                'code' => $r['request_code'],
                'amount' => (int) $r['amount'],
                'amount_fa' => wtoman((int) $r['amount']),
                'status' => $r['status'],
                'status_label' => WithdrawalService::statusLabel((string) $r['status']),
                'card_last4' => $r['card_last4'],
                'created_at' => fa_date($r['created_at']),
                'tracking_code' => $r['tracking_code'],
                'reject_reason' => $r['reject_reason'],
                'cancellable' => in_array($r['status'], ['new', 'reviewing'], true),
            ], $rows),
        ]);
    }

    public function withdraw(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        Csrf::verify();
        $amount = Money::parseToman($_POST['amount'] ?? null);
        $card = (string) ($_POST['card_number'] ?? '');
        $holder = trim((string) ($_POST['card_holder'] ?? ''));
        if ($amount === null) {
            $this->fail('مبلغ درخواست را وارد کنید.');
            return;
        }
        try {
            $request = (new WithdrawalService())->create((int) $user['id'], $amount, $card, $holder, Request::ip());
            $this->ok('درخواست برداشت ثبت و مبلغ رزرو شد. پس از بررسی، پرداخت انجام می‌شود.', [
                'request' => ['id' => (int) $request['id'], 'code' => $request['request_code']],
            ]);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    public function cancelWithdrawal(array $params): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        Csrf::verify();
        try {
            (new WithdrawalService())->cancelByUser((int) $params['id'], (int) $user['id']);
            $this->ok('درخواست لغو شد و مبلغ به موجودی شما بازگشت.');
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    public function campaigns(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        $service = new CampaignService();
        $out = [];
        foreach ($service->activeCampaigns() as $campaign) {
            $progress = $service->progressFor($campaign, (int) $user['id']);
            $out[] = [
                'id' => (int) $campaign['id'],
                'title' => $campaign['title'],
                'description' => $campaign['description'],
                'type' => $campaign['type'],
                'required_invites' => (int) $campaign['required_invites'],
                'fixed_reward' => (int) $campaign['fixed_reward'],
                'reward_fa' => wtoman((int) $campaign['fixed_reward']),
                'ends_at' => fa_date($campaign['ends_at'], false),
                'progress' => (int) ($progress['progress'] ?? 0),
                'status' => $progress['status'] ?? 'active',
            ];
        }
        $this->json(['ok' => true, 'campaigns' => $out]);
    }

    public function ranking(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        if (!RankingService::enabled()) {
            $this->json(['ok' => true, 'enabled' => false, 'rows' => []]);
            return;
        }
        $metric = RankingService::metric();
        $rows = RankingService::top(20, $metric);
        $this->json([
            'ok' => true,
            'enabled' => true,
            'metric' => $metric,
            'metric_label' => RankingService::METRICS[$metric] ?? $metric,
            'my_rank' => RankingService::rankOf((int) $user['id'], $metric),
            'rows' => array_map(static fn($r, $i) => [
                'rank' => $i + 1,
                'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'کاربر',
                'username' => $r['username'],
                'value' => (int) $r[$metric],
                'value_fa' => str_contains($metric, 'earned') ? wtoman((int) $r[$metric]) : fa_digits((int) $r[$metric]),
                'is_me' => (int) $r['id'] === (int) $user['id'],
            ], $rows, array_keys($rows)),
        ]);
    }

    public function notifications(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        $db = Database::instance();
        $rows = $db->all(
            "SELECT * FROM notifications WHERE (user_id = :u OR audience = 'all') ORDER BY id DESC LIMIT 50",
            ['u' => (int) $user['id']]
        );
        $this->json([
            'ok' => true,
            'unread' => count(array_filter($rows, static fn($r) => empty($r['read_at']))),
            'rows' => array_map(static fn($r) => [
                'id' => (int) $r['id'],
                'title' => $r['title'],
                'body' => $r['body'],
                'type' => $r['type'],
                'link' => $r['link'],
                'read' => !empty($r['read_at']),
                'created_at' => fa_date($r['created_at']),
            ], $rows),
        ]);
    }

    public function readNotifications(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        Csrf::verify();
        $ids = $_POST['ids'] ?? [];
        $db = Database::instance();
        if (is_array($ids) && $ids !== []) {
            foreach ($ids as $id) {
                $db->run(
                    'UPDATE notifications SET read_at = :now WHERE id = :id AND user_id = :u AND read_at IS NULL',
                    ['now' => now(), 'id' => (int) $id, 'u' => (int) $user['id']]
                );
            }
        } else {
            $db->run(
                'UPDATE notifications SET read_at = :now WHERE user_id = :u AND read_at IS NULL',
                ['now' => now(), 'u' => (int) $user['id']]
            );
        }
        $this->ok('اعلان‌ها خوانده‌شده علامت‌گذاری شدند.');
    }

    public function support(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        Csrf::verify();
        $message = trim((string) ($_POST['message'] ?? ''));
        if (mb_strlen($message) < 5) {
            $this->fail('متن پیام باید حداقل ۵ کاراکتر باشد.');
            return;
        }
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $platform = WebAppAuthService::currentPlatform();
        $id = SupportService::create((int) $user['id'], mb_substr($message, 0, 3000), $subject !== '' ? $subject : null);
        try {
            $admins = Database::instance()->all('SELECT telegram_user_id FROM admins WHERE telegram_user_id IS NOT NULL AND is_active = 1 LIMIT 3');
            $tg = new TelegramService();
            if ($tg->isConfigured()) {
                foreach ($admins as $admin) {
                    $tg->sendMessage(
                        (int) $admin['telegram_user_id'],
                        "📩 پیام پشتیبانی جدید (" . MessengerCapabilities::label($platform) . ")\nاز: "
                        . trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))
                        . "\n\n" . mb_substr($message, 0, 600)
                    );
                }
            }
        } catch (\Throwable) {
        }
        $this->ok('پیام شما ثبت شد. پاسخ از طریق پیام‌رسان و پنل ارسال می‌شود.', ['ticket_id' => $id]);
    }

    public function network(array $params): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        $depth = min(9, max(1, (int) ($_GET['depth'] ?? 4)));
        $rows = (new UserService())->descendants((int) $user['id'], $depth, 500);
        $this->json([
            'ok' => true,
            'depth' => $depth,
            'nodes' => array_map(static function (array $r): array {
                $d = (int) $r['depth'];
                $layer = max(0, $d - 1);
                return [
                    'id' => (int) $r['descendant_id'],
                    'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'کاربر',
                    'level' => $d,
                    'layer' => $layer,
                    'layer_label' => $layer === 0 ? 'لایه ۰ (مستقیم)' : ('لایه ' . fa_digits($layer)),
                    'joined_at' => fa_date($r['joined_at'], false),
                    'refs' => (int) $r['direct_ref_count'],
                ];
            }, $rows),
        ]);
    }

    public function updateProfile(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        Csrf::verify();
        $first = trim((string) ($_POST['first_name'] ?? ''));
        $last = trim((string) ($_POST['last_name'] ?? ''));
        if (mb_strlen($first) < 2) {
            $this->fail('نام باید حداقل ۲ کاراکتر باشد.');
            return;
        }
        $qualified = (new UserService())->updateProfile((int) $user['id'], $first, $last);
        Notifier::flushDeferred();
        $this->ok($qualified ? 'پروفایل ذخیره شد و پاداش خوش‌آمدگویی شما فعال گردید! 🎁' : 'پروفایل ذخیره شد.', [
            'qualified' => $qualified,
        ]);
    }

    public static function txToArray(array $tx): array
    {
        return [
            'id' => (int) $tx['id'],
            'code' => $tx['tx_code'],
            'type' => $tx['type'],
            'type_label' => TransactionLabels::label((string) $tx['type']),
            'direction' => $tx['direction'],
            'amount' => (int) $tx['amount'],
            'amount_fa' => wtoman((int) $tx['amount'], false),
            'balance_after' => (int) $tx['balance_after'],
            'status' => $tx['status'],
            'status_label' => TransactionLabels::status((string) $tx['status']),
            'description' => $tx['description'],
            'created_at' => fa_date($tx['created_at']),
        ];
    }
}
