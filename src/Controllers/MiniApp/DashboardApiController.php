<?php
declare(strict_types=1);

namespace RB\Controllers\MiniApp;

use RB\Bot\TransactionLabels;
use RB\Http\Controller;
use RB\Services\CampaignService;
use RB\Services\Notifier;
use RB\Services\RankingService;
use RB\Services\SettingsService;
use RB\Services\SupportService;
use RB\Services\TelegramService;
use RB\Services\TelegramWebApp;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Services\WithdrawalService;
use RB\Support\Csrf;
use RB\Support\Database;
use RB\Support\Money;
use RB\Support\Request;

/**
 * API پنل کاربری Mini App
 * همه پاسخ‌ها JSON؛ احراز هویت با سشن تلگرام (که خودش با initData معتبر ساخته شده)
 */
final class DashboardApiController extends Controller
{
    private function user(): ?array
    {
        $user = TelegramWebApp::currentUser();
        if ($user === null) {
            $this->json([
                'ok' => false,
                'error' => 'برای استفاده از پنل، ابتدا از داخل ربات تلگرام وارد شوید.',
                'need_auth' => true,
            ], 401);
        }
        return $user;
    }

    /** شامل csrf token برای درخواست‌های بعدی */
    public function bootstrap(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        $this->json(['ok' => true, 'csrf' => Csrf::token(), 'user_id' => (int) $user['id']]);
    }

    public function dashboard(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        $db = Database::instance();
        $wallet = new WalletService($db);
        $summary = $wallet->summary((int) $user['id']);
        $reconcile = $wallet->reconcile((int) $user['id']);
        $tg = TelegramService::settings($db);
        $botUsername = (string) ($tg['bot_username'] ?? '');
        $link = $botUsername !== ''
            ? 'https://t.me/' . $botUsername . '?start=' . $user['referral_code']
            : (string) SettingsService::get('bot_link_base', '');

        $lastTx = $db->all(
            'SELECT * FROM wallet_transactions WHERE user_id = :u ORDER BY id DESC LIMIT 6',
            ['u' => (int) $user['id']]
        );
        $rank = RankingService::enabled() ? RankingService::rankOf((int) $user['id']) : null;
        $campaigns = (new CampaignService($db))->activeCampaigns();
        $activeCampaigns = [];
        foreach ($campaigns as $campaign) {
            $progress = (new CampaignService($db))->progressFor($campaign, (int) $user['id']);
            $activeCampaigns[] = [
                'title' => $campaign['title'],
                'description' => $campaign['description'],
                'progress' => $progress['progress'] ?? 0,
                'required' => (int) ($campaign['required_invites'] ?? 0),
                'reward' => (int) ($campaign['fixed_reward'] ?? 0),
                'ends_at' => $campaign['ends_at'],
            ];
        }

        $this->json([
            'ok' => true,
            'user' => [
                'id' => (int) $user['id'],
                'telegram_id' => (string) $user['telegram_id'],
                'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
                'username' => $user['username'],
                'phone' => $user['phone'],
                'profile_complete' => (bool) ($user['phone'] && $user['first_name']),
                'status' => $user['status'],
                'joined_at' => $user['joined_at'],
                'joined_at_fa' => fa_date($user['joined_at']),
                'referral_code' => $user['referral_code'],
                'invite_link' => $link,
                'verify_status' => $user['verify_status'],
                'rank' => $rank,
            ],
            'wallet' => $summary,
            'reconcile' => [
                'match' => (bool) ($reconcile['match'] ?? true),
                'ledger' => (int) ($reconcile['ledger'] ?? 0),
            ],
            'counts' => [
                'direct_refs' => (int) $user['direct_ref_count'],
                'total_refs' => (int) $user['total_ref_count'],
            ],
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

    public function referrals(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        $db = Database::instance();
        $users = new UserService($db);
        $direct = $db->all(
            'SELECT id, first_name, last_name, username, telegram_id, joined_at, qualified_at, status,
                    direct_ref_count, total_earned
             FROM users WHERE referred_by = :r ORDER BY joined_at DESC LIMIT 100',
            ['r' => (int) $user['id']]
        );
        $levels = $db->all(
            'SELECT rr.depth AS level, COUNT(*) AS c FROM referral_relations rr WHERE rr.ancestor_id = :a GROUP BY rr.depth ORDER BY rr.depth',
            ['a' => (int) $user['id']]
        );
        $tg = TelegramService::settings($db);
        $botUsername = (string) ($tg['bot_username'] ?? '');

        $this->json([
            'ok' => true,
            'invite_link' => $botUsername !== '' ? 'https://t.me/' . $botUsername . '?start=' . $user['referral_code'] : '',
            'referral_code' => $user['referral_code'],
            'direct' => array_map(static fn($r) => [
                'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'کاربر',
                'username' => $r['username'],
                'joined_at' => fa_date($r['joined_at'], false),
                'qualified' => !empty($r['qualified_at']),
                'status' => $r['status'],
                'refs' => (int) $r['direct_ref_count'],
            ], $direct),
            'levels' => array_map(static fn($l) => ['level' => (int) $l['level'], 'count' => (int) $l['c']], $levels),
            'totals' => [
                'direct' => (int) $user['direct_ref_count'],
                'network' => (int) $user['total_ref_count'],
            ],
        ]);
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
        $card = trim((string) ($_POST['card_number'] ?? ''));
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
        $id = SupportService::create((int) $user['id'], mb_substr($message, 0, 3000), $subject !== '' ? $subject : null);
        // اطلاع به مدیران دارای شناسه تلگرام
        try {
            $admins = Database::instance()->all('SELECT telegram_user_id FROM admins WHERE telegram_user_id IS NOT NULL AND is_active = 1 LIMIT 3');
            $tg = new TelegramService();
            foreach ($admins as $admin) {
                if ($tg->isConfigured()) {
                    $tg->sendMessage((int) $admin['telegram_user_id'],
                        "📩 پیام پشتیبانی جدید\nاز: " . trim($user['first_name'] . ' ' . $user['last_name'])
                        . "\n\n" . mb_substr($message, 0, 600));
                }
            }
        } catch (\Throwable) {
        }
        $this->ok('پیام شما ثبت شد. پاسخ از طریق ربات و پنل ارسال می‌شود.', ['ticket_id' => $id]);
    }

    public function network(array $params): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        $depth = min(8, max(1, (int) ($_GET['depth'] ?? 3)));
        $rows = (new UserService())->descendants((int) $user['id'], $depth, 500);
        $this->json([
            'ok' => true,
            'depth' => $depth,
            'nodes' => array_map(static fn($r) => [
                'id' => (int) $r['descendant_id'],
                'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'کاربر',
                'level' => (int) $r['depth'],
                'joined_at' => fa_date($r['joined_at'], false),
                'refs' => (int) $r['direct_ref_count'],
            ], $rows),
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
        Database::instance()->update('users', [
            'first_name' => mb_substr($first, 0, 120),
            'last_name' => mb_substr($last, 0, 120),
            'updated_at' => now(),
        ], 'id = :id', ['id' => (int) $user['id']]);
        $fresh = (new UserService())->find((int) $user['id']);
        if ($fresh !== null) {
            (new UserService())->maybeQualify($fresh, true);
        }
        $this->ok('پروفایل ذخیره شد.');
    }

    /** @param array $tx */
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
