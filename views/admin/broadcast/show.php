<?php
$statusBadge = ['draft' => 'muted', 'queued' => 'info', 'sending' => 'warning',
    'sent' => 'success', 'failed' => 'danger', 'cancelled' => 'muted'];
$statusLabel = ['draft' => 'پیش‌نویس', 'queued' => 'در صف', 'sending' => 'در حال ارسال',
    'sent' => 'ارسال‌شده', 'failed' => 'ناموفق', 'cancelled' => 'لغو‌شده'];
$targetBadge = ['pending' => 'muted', 'sent' => 'success', 'failed' => 'danger', 'blocked' => 'warning'];
$total = max(1, (int) $campaign['total_targets']);
$done = (int) $campaign['sent_count'] + (int) $campaign['failed_count'] + (int) $campaign['blocked_count'];
$percent = min(100, (int) round($done / $total * 100));
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0"><?= e((string) $campaign['title']) ?></h1>
        <p class="text-muted text-sm mb-0">ایجاد: <?= fa_date($campaign['created_at']) ?>
            · وضعیت: <span class="badge badge-<?= $statusBadge[$campaign['status']] ?? 'muted' ?>"><?= e($statusLabel[$campaign['status']] ?? (string) $campaign['status']) ?></span></p>
    </div>
    <div class="flex gap-1">
        <a href="<?= url('admin/broadcast') ?>" class="btn btn-secondary btn-sm">بازگشت</a>
        <?php if (in_array($campaign['status'], ['queued', 'sending'], true)): ?>
            <form method="post" action="<?= url('admin/broadcast/' . $campaign['id'] . '/cancel') ?>" data-ajax>
                <?= csrf_field() ?>
                <button class="btn btn-danger btn-sm" type="submit" data-confirm="ارسال لغو شود؟">لغو ارسال</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))">
    <div class="stat"><div class="stat-head"><div class="stat-icon">👥</div><div class="stat-label">کل مخاطبان</div></div>
        <div class="stat-value"><?= fa_digits(number_format((int) $campaign['total_targets'])) ?></div></div>
    <div class="stat success"><div class="stat-head"><div class="stat-icon">✅</div><div class="stat-label">ارسال موفق</div></div>
        <div class="stat-value"><?= fa_digits(number_format((int) $campaign['sent_count'])) ?></div></div>
    <div class="stat danger"><div class="stat-head"><div class="stat-icon">⚠️</div><div class="stat-label">ناموفق</div></div>
        <div class="stat-value"><?= fa_digits(number_format($failures)) ?></div></div>
    <div class="stat warning"><div class="stat-head"><div class="stat-icon">🚫</div><div class="stat-label">ربات را بلاک کرده‌اند</div></div>
        <div class="stat-value"><?= fa_digits(number_format((int) $campaign['blocked_count'])) ?></div></div>
</div>

<div class="card mb-3">
    <div class="card-header"><h2 class="card-title">پیشرفت ارسال</h2>
        <span class="card-subtitle"><?= fa_digits($percent) ?>٪</span></div>
    <div class="card-body">
        <div style="height:12px;background:#e2e8f0;border-radius:99px;overflow:hidden">
            <div style="height:100%;width:<?= $percent ?>%;background:linear-gradient(90deg,#6366f1,#10b981);transition:width .4s"></div>
        </div>
        <div class="flex justify-between text-xs text-muted mt-2">
            <span>شروع: <?= fa_date($campaign['started_at']) ?></span>
            <span>پایان: <?= fa_date($campaign['finished_at']) ?></span>
        </div>
        <?php if (!empty($campaign['last_error'])): ?>
            <div class="alert alert-error mt-2 mb-0"><span class="alert-icon">⚠️</span>
                <div><?= e((string) $campaign['last_error']) ?></div></div>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2 class="card-title">متن پیام</h2></div>
        <div class="card-body">
            <div style="white-space:pre-wrap;line-height:1.9;font-size:.86rem;background:#f8fafc;border-radius:12px;padding:14px"><?= e((string) $campaign['message_text']) ?></div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h2 class="card-title">فهرست مخاطبان (۱۰۰ ردیف اول)</h2></div>
        <?php if (empty($targets)): ?>
            <div class="empty" style="padding:26px"><div class="empty-icon">📭</div><div class="empty-title">مخاطبی ثبت نشده</div></div>
        <?php else: ?>
            <div class="table-wrap" style="max-height:420px;overflow:auto">
                <table class="table">
                    <thead><tr><th>شناسه تلگرام</th><th>وضعیت</th><th>خطا</th><th>زمان</th></tr></thead>
                    <tbody>
                    <?php foreach ($targets as $t): ?>
                        <tr>
                            <td class="num ltr" data-label="شناسه"><?= e((string) $t['telegram_id']) ?></td>
                            <td data-label="وضعیت"><span class="badge badge-<?= $targetBadge[$t['status']] ?? 'muted' ?>"><?= e((string) $t['status']) ?></span></td>
                            <td class="text-xs" data-label="خطا"><?= e(mb_strimwidth((string) ($t['error'] ?? ''), 0, 40, '…')) ?></td>
                            <td class="text-xs" data-label="زمان"><?= fa_date($t['sent_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
