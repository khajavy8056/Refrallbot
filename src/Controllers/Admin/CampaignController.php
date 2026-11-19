<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\AuditService;
use RB\Services\CampaignService;
use RB\Services\UserService;
use RB\Support\Database;
use RB\Support\Money;
use RB\Support\Request;

final class CampaignController extends AdminController
{
    public function index(): void
    {
        $db = Database::instance();
        $service = new CampaignService($db);
        $campaigns = $db->all('SELECT * FROM campaigns ORDER BY id DESC');
        $stats = [];
        foreach ($campaigns as $c) {
            $stats[(int) $c['id']] = $service->stats((int) $c['id']);
        }
        $this->render('admin/campaigns/index', [
            'title' => 'جشنواره‌ها و کمپین‌ها',
            'campaigns' => $campaigns,
            'stats' => $stats,
            'types' => CampaignService::TYPES,
        ]);
    }

    public function create(): void
    {
        $this->render('admin/campaigns/form', [
            'title' => 'ایجاد کمپین جدید',
            'campaign' => null,
            'types' => CampaignService::TYPES,
        ]);
    }

    public function store(): void
    {
        $db = Database::instance();
        $data = $this->validated();
        if ($data === null) {
            return;
        }
        $id = $db->insert('campaigns', array_merge($data, [
            'created_by' => current_admin_id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        AuditService::log('campaign.created', 'campaign', $id, ['title' => $data['title']], current_admin_id());
        $this->flash('success', 'کمپین با موفقیت ایجاد شد.');
        Request::redirect(url('admin/campaigns/' . $id));
    }

    public function show(array $params): void
    {
        $id = (int) $params['id'];
        $db = Database::instance();
        $campaign = $db->first('SELECT * FROM campaigns WHERE id = :id', ['id' => $id]);
        if ($campaign === null) {
            $this->flash('error', 'کمپین یافت نشد.');
            Request::redirect(url('admin/campaigns'));
            return;
        }
        $participants = $db->all(
            'SELECT cp.*, u.first_name, u.last_name, u.username, u.telegram_id
             FROM campaign_participants cp JOIN users u ON u.id = cp.user_id
             WHERE cp.campaign_id = :c ORDER BY cp.progress DESC LIMIT 100',
            ['c' => $id]
        );
        $rewards = $db->all(
            'SELECT cr.*, u.first_name, u.last_name, u.username
             FROM campaign_rewards cr JOIN users u ON u.id = cr.user_id
             WHERE cr.campaign_id = :c ORDER BY cr.id DESC LIMIT 100',
            ['c' => $id]
        );
        $this->render('admin/campaigns/show', [
            'title' => 'کمپین: ' . $campaign['title'],
            'campaign' => $campaign,
            'participants' => $participants,
            'rewards' => $rewards,
            'stats' => (new CampaignService($db))->stats($id),
            'types' => CampaignService::TYPES,
        ]);
    }

    public function edit(array $params): void
    {
        $id = (int) $params['id'];
        $campaign = Database::instance()->first('SELECT * FROM campaigns WHERE id = :id', ['id' => $id]);
        if ($campaign === null) {
            $this->flash('error', 'کمپین یافت نشد.');
            Request::redirect(url('admin/campaigns'));
            return;
        }
        $this->render('admin/campaigns/form', [
            'title' => 'ویرایش کمپین',
            'campaign' => $campaign,
            'types' => CampaignService::TYPES,
        ]);
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $data = $this->validated();
        if ($data === null) {
            return;
        }
        Database::instance()->update('campaigns', array_merge($data, ['updated_at' => now()]), 'id = :id', ['id' => $id]);
        AuditService::log('campaign.updated', 'campaign', $id, ['title' => $data['title']], current_admin_id());
        $this->flash('success', 'کمپین به‌روزرسانی شد.');
        Request::redirect(url('admin/campaigns/' . $id));
    }

    public function toggle(array $params): void
    {
        $id = (int) $params['id'];
        $db = Database::instance();
        $campaign = $db->first('SELECT is_active FROM campaigns WHERE id = :id', ['id' => $id]);
        if ($campaign === null) {
            $this->fail('کمپین یافت نشد.', 404);
            return;
        }
        $db->update('campaigns', ['is_active' => (int) $campaign['is_active'] ? 0 : 1, 'updated_at' => now()], 'id = :id', ['id' => $id]);
        AuditService::log('campaign.updated', 'campaign', $id, ['toggled' => true], current_admin_id());
        $this->ok('وضعیت کمپین تغییر کرد.', ['reload' => true]);
    }

    public function grantRewards(array $params): void
    {
        $id = (int) $params['id'];
        $db = Database::instance();
        $campaign = $db->first('SELECT * FROM campaigns WHERE id = :id', ['id' => $id]);
        if ($campaign === null) {
            $this->fail('کمپین یافت نشد.', 404);
            return;
        }
        try {
            $service = new CampaignService($db);
            if (!empty($_POST['auto']) || $campaign['type'] === 'invite_count') {
                $count = $service->processRewards();
                $this->ok(fa_digits($count) . ' جایزه جدید پرداخت شد.', ['reload' => true]);
                return;
            }
            // پرداخت دستی به یک کاربر
            $users = new UserService($db);
            $identifier = trim((string) ($_POST['user'] ?? ''));
            $amount = Money::parseToman($_POST['amount'] ?? null) ?? 0;
            $found = ctype_digit(en_digits($identifier))
                ? $users->findByTelegramId((int) en_digits($identifier))
                : $users->findByCode($identifier);
            if ($found === null) {
                $found = $db->first('SELECT * FROM users WHERE username = :u', ['u' => ltrim($identifier, '@')]);
            }
            if ($found === null) {
                $this->fail('کاربر یافت نشد.');
                return;
            }
            if ($amount <= 0) {
                $this->fail('مبلغ جایزه نامعتبر است.');
                return;
            }
            $service->grant($campaign, (int) $found['id'], $amount, 'manual_' . time(), (int) en_digits((string) ($_POST['progress'] ?? '0')), current_admin_id());
            AuditService::log('campaign.reward_granted', 'campaign', $id, ['user_id' => (int) $found['id'], 'amount' => $amount], current_admin_id());
            $this->ok('جایزه به کاربر پرداخت و در دفتر مالی ثبت شد.', ['reload' => true]);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    private function validated(): ?array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        if (mb_strlen($title) < 3) {
            $this->flash('error', 'عنوان کمپین باید حداقل ۳ کاراکتر باشد.');
            Request::redirect($_SERVER['HTTP_REFERER'] ?? url('admin/campaigns'));
            return null;
        }
        $start = trim((string) ($_POST['starts_at'] ?? ''));
        $end = trim((string) ($_POST['ends_at'] ?? ''));
        $parse = static function (string $value): ?string {
            if ($value === '') {
                return null;
            }
            $value = en_digits($value);
            if (preg_match('~^\d{4}/\d{1,2}/\d{1,2}~', $value)) {
                $greg = preg_replace('~/(\d)$~', '/0$1', $value);
                $date = \RB\Support\Jalali::jalaliStringToGregorian($greg);
                return $date ? $date . ' 00:00:00' : null;
            }
            $ts = strtotime($value);
            return $ts ? date('Y-m-d H:i:s', $ts) : null;
        };
        $startAt = $parse($start);
        $endAt = $parse($end);
        if ($startAt && $endAt && strtotime($endAt) <= strtotime($startAt)) {
            $this->flash('error', 'تاریخ پایان باید بعد از تاریخ شروع باشد.');
            Request::redirect($_SERVER['HTTP_REFERER'] ?? url('admin/campaigns'));
            return null;
        }

        $type = (string) ($_POST['type'] ?? 'invite_count');
        if (!array_key_exists($type, CampaignService::TYPES)) {
            $type = 'invite_count';
        }

        return [
            'title' => mb_substr($title, 0, 150),
            'description' => mb_substr((string) ($_POST['description'] ?? ''), 0, 3000),
            'type' => $type,
            'starts_at' => $startAt,
            'ends_at' => $endAt,
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'conditions' => json_encode(['manual_note' => (string) ($_POST['conditions'] ?? '')], JSON_UNESCAPED_UNICODE),
            'required_invites' => max(0, (int) en_digits((string) ($_POST['required_invites'] ?? 0))),
            'fixed_reward' => Money::parseToman($_POST['fixed_reward'] ?? null) ?? 0,
            'percent_bonus' => Money::fromMicro(Money::toMicro((string) ($_POST['percent_bonus'] ?? '0'))),
            'max_winners' => max(0, (int) en_digits((string) ($_POST['max_winners'] ?? '0'))),
            'min_activity' => Money::parseToman($_POST['min_activity'] ?? null) ?? 0,
            'audience' => in_array($_POST['audience'] ?? '', ['all', 'active', 'with_refs'], true) ? (string) $_POST['audience'] : 'all',
        ];
    }
}
