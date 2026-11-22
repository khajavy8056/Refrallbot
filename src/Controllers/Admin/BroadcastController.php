<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\AuditService;
use RB\Services\BroadcastService;
use RB\Support\Database;
use RB\Support\Request;

final class BroadcastController extends AdminController
{
    public function index(): void
    {
        $db = Database::instance();
        $msgSummary = \RB\Services\StatsService::segmentedMessengerSummary($db);
        $this->render('admin/broadcast/index', [
            'title' => 'ارسال پیام همگانی',
            'campaigns' => $db->all('SELECT * FROM broadcast_campaigns ORDER BY id DESC LIMIT 25'),
            'segments' => BroadcastService::SEGMENTS,
            'counts' => (new BroadcastService($db))->segmentCounts(),
            'activeMessengers' => $msgSummary['active'],
            'messengerTotals' => $msgSummary['totals'],
        ]);
    }

    public function preview(): void
    {
        $text = (string) ($_POST['message_text'] ?? '');
        if (trim($text) === '') {
            $this->fail('متن پیام را وارد کنید.');
            return;
        }
        $params = json_decode((string) ($_POST['segment_params'] ?? '{}'), true) ?: [];
        $targetPlatform = strtolower(trim((string) ($_POST['target_platform'] ?? ($params['target_platform'] ?? 'all'))));
        if ($targetPlatform !== 'all' && !(new \RB\Messengers\MessengerRegistry())->isActiveAndConnected($targetPlatform)) {
            $this->fail('پیام‌رسان انتخاب‌شده فعال و متصل نیست.');
            return;
        }
        $params['target_platform'] = $targetPlatform;
        $this->json([
            'ok' => true,
            'preview' => BroadcastService::renderPreview($text),
            'count' => (new BroadcastService())->countTargets((string) ($_POST['segment'] ?? 'all'), $params),
        ]);
    }

    public function send(): void
    {
        $text = trim((string) ($_POST['message_text'] ?? ''));
        if ($text === '') {
            $this->fail('متن پیام الزامی است.');
            return;
        }
        $segment = (string) ($_POST['segment'] ?? 'all');
        $params = json_decode((string) ($_POST['segment_params'] ?? '{}'), true) ?: [];
        $targetPlatform = strtolower(trim((string) ($_POST['target_platform'] ?? 'all')));
        if ($targetPlatform !== 'all' && !(new \RB\Messengers\MessengerRegistry())->isActiveAndConnected($targetPlatform)) {
            $this->fail('پیام‌رسان انتخاب‌شده فعال و متصل نیست.');
            return;
        }
        $params['target_platform'] = $targetPlatform;

        try {
            $service = new BroadcastService();
            $campaign = $service->createCampaign([
                'title' => mb_substr((string) ($_POST['title'] ?? 'پیام همگانی'), 0, 150),
                'message_text' => $text,
                'segment' => $segment,
                'target_platform' => $targetPlatform,
                'segment_params' => $params,
                'buttons' => trim((string) ($_POST['buttons'] ?? '')) !== '' ? json_decode((string) $_POST['buttons'], true) : null,
                'media_type' => (string) ($_POST['media_type'] ?? '') ?: null,
                'media_file_id' => trim((string) ($_POST['media_file_id'] ?? '')) ?: null,
                'scheduled_at' => trim((string) ($_POST['scheduled_at'] ?? '')) ?: null,
            ], current_admin_id());

            AuditService::log('broadcast.created', 'broadcast', (int) $campaign['id'], [
                'segment' => $segment,
                'target_platform' => $targetPlatform,
                'targets' => (int) $campaign['total_targets'],
            ], current_admin_id());

            $this->ok(
                'کمپین ارسال ایجاد شد با ' . fa_digits((int) $campaign['total_targets']) . ' مخاطب. '
                . 'ارسال به‌صورت مرحله‌ای توسط Cron انجام می‌شود.',
                ['campaign_id' => (int) $campaign['id'], 'redirect' => url('admin/broadcast/' . (int) $campaign['id'])]
            );
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    public function test(): void
    {
        $text = trim((string) ($_POST['message_text'] ?? ''));
        if ($text === '') {
            $this->fail('متن پیام الزامی است.');
            return;
        }
        try {
            $service = new BroadcastService();
            $sent = $service->sendTestToAdmin(current_admin_id(), $text);
            $this->ok($sent ? 'پیام آزمایشی به تلگرام شما ارسال شد.' : 'ارسال آزمایشی انجام نشد. اطمینان حاصل کنید شناسه تلگرام مدیر ثبت شده و ربات متصل است.');
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    public function show(array $params): void
    {
        $id = (int) $params['id'];
        $db = Database::instance();
        $campaign = $db->first('SELECT * FROM broadcast_campaigns WHERE id = :id', ['id' => $id]);
        if ($campaign === null) {
            $this->flash('error', 'کمپین یافت نشد.');
            Request::redirect(url('admin/broadcast'));
            return;
        }
        $this->render('admin/broadcast/show', [
            'title' => 'گزارش ارسال همگانی',
            'campaign' => $campaign,
            'targets' => $db->all('SELECT * FROM broadcast_targets WHERE campaign_id = :id ORDER BY id LIMIT 100', ['id' => $id]),
            'failures' => (int) $db->value("SELECT COUNT(*) FROM broadcast_targets WHERE campaign_id = :id AND status = 'failed'", ['id' => $id]),
        ]);
    }

    public function cancel(array $params): void
    {
        $id = (int) $params['id'];
        $db = Database::instance();
        $campaign = $db->first('SELECT id FROM broadcast_campaigns WHERE id = :id', ['id' => $id]);
        if ($campaign === null) {
            $this->fail('کمپین یافت نشد.');
            return;
        }
        $db->update('broadcast_campaigns', ['status' => 'cancelled', 'updated_at' => now()], 'id = :id', ['id' => $id]);
        AuditService::log('broadcast.cancelled', 'broadcast', $id, [], current_admin_id());
        $this->ok('ارسال کمپین لغو شد.', ['reload' => true]);
    }

    public function retryFailed(array $params): void
    {
        $id = (int) $params['id'];
        $db = Database::instance();
        $campaign = $db->first('SELECT id, status FROM broadcast_campaigns WHERE id = :id', ['id' => $id]);
        if ($campaign === null) {
            $this->fail('کمپین یافت نشد.');
            return;
        }
        if ((string) $campaign['status'] === 'cancelled') {
            $this->fail('این کمپین لغو شده است؛ ابتدا یک کمپین جدید ایجاد کنید.');
            return;
        }
        $result = $db->transaction(static function ($db) use ($id, $campaign) {
            $retried = (new BroadcastService($db))->retryFailed($id);
            if ($retried > 0 && in_array((string) $campaign['status'], ['sent', 'failed'], true)) {
                $db->update('broadcast_campaigns', [
                    'status' => 'queued',
                    'finished_at' => null,
                    'updated_at' => now(),
                ], 'id = :id', ['id' => $id]);
            } elseif ($retried > 0) {
                $db->update('broadcast_campaigns', ['updated_at' => now()], 'id = :id', ['id' => $id]);
            }
            return $retried;
        });
        AuditService::log('broadcast.retry_failed', 'broadcast', $id, ['targets' => (int) $result], current_admin_id());
        $this->ok(
            $result > 0
                ? fa_digits((int) $result) . ' مخاطب ناموفق برای تلاش مجدد به صف بازگردانده شد.'
                : 'مخاطب ناموفقی برای تلاش مجدد وجود ندارد.',
            ['reload' => true]
        );
    }
}
