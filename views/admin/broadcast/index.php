<?php
$statusBadge = ['draft' => 'muted', 'queued' => 'info', 'sending' => 'warning',
    'sent' => 'success', 'failed' => 'danger', 'cancelled' => 'muted'];
$statusLabel = ['draft' => 'پیش‌نویس', 'queued' => 'در صف', 'sending' => 'در حال ارسال',
    'sent' => 'ارسال‌شده', 'failed' => 'ناموفق', 'cancelled' => 'لغو‌شده'];
$totalTargets = array_sum($counts);
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">ارسال پیام همگانی</h1>
        <p class="text-muted text-sm mb-0">ارسال مرحله‌ای با محدودیت نرخ توسط Cron — مناسب هاست اشتراکی بدون نیاز به Worker</p>
    </div>
</div>

<div class="alert alert-info">
    <span class="alert-icon">⏱</span>
    <div>
        ارسال به‌صورت دسته‌ای و با تأخیر انجام می‌شود تا تلگرام محدودیت نگذارد.
        برای اجرای واقعی، Cron را تنظیم کنید: <span class="ltr mono text-xs">*/2 * * * * php <?= e(RB_ROOT) ?>/bin/cron.php</span>
    </div>
</div>

<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header"><h2 class="card-title">ایجاد کمپین ارسال</h2></div>
        <form id="broadcast-form" method="post" action="<?= url('admin/broadcast/send') ?>" data-ajax>
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">عنوان داخلی کمپین</label>
                    <input type="text" name="title" class="form-control" maxlength="150" required placeholder="مثال: اطلاع‌رسانی جشنواره نوروز">
                </div>
                <div class="form-group">
                    <label class="form-label">گروه مخاطبان</label>
                    <select name="segment" class="form-select" id="segment">
                        <?php foreach ($segments as $key => $label): ?>
                            <option value="<?= e($key) ?>"><?= e($label) ?> (<?= fa_digits((int) ($counts[$key] ?? 0)) ?> نفر)</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">مجموع مخاطبان تمام گروه‌ها: <?= fa_digits(number_format($totalTargets)) ?> نفر</div>
                </div>
                <div class="form-group">
                    <label class="form-label">متن پیام</label>
                    <textarea name="message_text" id="message-text" class="form-control" rows="7" maxlength="4000" required
                              placeholder="متن پیام…&#10;می‌توانید از {name} برای نام کاربر استفاده کنید."></textarea>
                    <div class="form-hint">
                        متغیرهای مجاز: <span class="ltr mono">{name}</span>، <span class="ltr mono">{username}</span>،
                        <span class="ltr mono">{balance}</span>، <span class="ltr mono">{invite_link}</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">دکمه‌های شیشه‌ای (JSON — اختیاری)</label>
                    <textarea name="buttons" class="form-control ltr" rows="3" dir="ltr"
                              placeholder='[[{"text":"ورود به پنل","url":"https://..."}]]'></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">نوع رسانه (اختیاری)</label>
                        <select name="media_type" class="form-select">
                            <option value="">متن ساده</option>
                            <option value="photo">عکس</option>
                            <option value="video">ویدیو</option>
                            <option value="document">فایل</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">file_id رسانه</label>
                        <input type="text" name="media_file_id" class="form-control ltr" dir="ltr" placeholder="اختیاری">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">زمان‌بندی (اختیاری)</label>
                    <input type="text" name="scheduled_at" class="form-control ltr" dir="ltr" placeholder="2026-01-01 12:00 یا خالی برای ارسال فوری">
                </div>
            </div>
            <div class="card-footer flex gap-2 wrap">
                <button class="btn" type="submit" data-confirm="کمپین ارسال ایجاد شود؟ ارسال توسط Cron آغاز می‌شود.">🚀 ایجاد و شروع ارسال</button>
                <button class="btn btn-secondary" type="button" id="preview-btn">👁 پیش‌نمایش و تعداد مخاطب</button>
                <button class="btn btn-ghost" type="button" id="test-btn">📮 ارسال آزمایشی به تلگرام من</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">پیش‌نمایش</h2></div>
        <div class="card-body">
            <div id="preview-box" style="background:var(--surface-2,#f8fafc);border-radius:14px;padding:14px;min-height:120px">
                <div class="text-muted text-sm">متن پیام را وارد کنید و «پیش‌نمایش» را بزنید.</div>
            </div>
            <div id="preview-count" class="text-sm mt-2"></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title">کمپین‌های اخیر</h2>
        <span class="card-subtitle">۲۵ کمپین آخر</span></div>
    <?php if (empty($campaigns)): ?>
        <div class="empty"><div class="empty-icon">✉️</div><div class="empty-title">هنوز کمپینی ایجاد نشده</div>
            <div class="empty-text">اولین پیام همگانی خود را از فرم بالا ایجاد کنید.</div></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>عنوان</th><th>گروه</th><th>وضعیت</th><th>پیشرفت</th><th>موفق</th><th>ناموفق</th><th>بلاک</th><th>تاریخ</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($campaigns as $c): ?>
                    <?php
                    $total = max(1, (int) $c['total_targets']);
                    $done = (int) $c['sent_count'] + (int) $c['failed_count'] + (int) $c['blocked_count'];
                    $percent = min(100, (int) round($done / $total * 100));
                    ?>
                    <tr>
                        <td data-label="عنوان"><a href="<?= url('admin/broadcast/' . $c['id']) ?>"><?= e(mb_strimwidth((string) $c['title'], 0, 40, '…')) ?></a></td>
                        <td data-label="گروه"><?= e($segments[$c['segment']] ?? (string) $c['segment']) ?></td>
                        <td data-label="وضعیت"><span class="badge badge-<?= $statusBadge[$c['status']] ?? 'muted' ?>"><?= e($statusLabel[$c['status']] ?? (string) $c['status']) ?></span></td>
                        <td data-label="پیشرفت">
                            <div style="min-width:110px">
                                <div style="height:6px;background:#e2e8f0;border-radius:99px;overflow:hidden">
                                    <div style="height:100%;width:<?= $percent ?>%;background:linear-gradient(90deg,#6366f1,#10b981)"></div>
                                </div>
                                <span class="text-xs text-muted"><?= fa_digits($percent) ?>٪ از <?= fa_digits((int) $c['total_targets']) ?></span>
                            </div>
                        </td>
                        <td class="num" data-label="موفق"><?= fa_digits((int) $c['sent_count']) ?></td>
                        <td class="num" data-label="ناموفق"><?= fa_digits((int) $c['failed_count']) ?></td>
                        <td class="num" data-label="بلاک"><?= fa_digits((int) $c['blocked_count']) ?></td>
                        <td class="text-xs" data-label="تاریخ"><?= fa_date($c['created_at']) ?></td>
                        <td data-label="">
                            <div class="actions">
                                <a href="<?= url('admin/broadcast/' . $c['id']) ?>" class="btn btn-sm btn-secondary">گزارش</a>
                                <?php if (in_array($c['status'], ['queued', 'sending'], true)): ?>
                                    <form method="post" action="<?= url('admin/broadcast/' . $c['id'] . '/cancel') ?>" data-ajax>
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-danger" type="submit" data-confirm="ارسال لغو شود؟">لغو</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const previewBtn = document.getElementById('preview-btn');
    const testBtn = document.getElementById('test-btn');
    const box = document.getElementById('preview-box');
    const countEl = document.getElementById('preview-count');
    const form = document.getElementById('broadcast-form');

    function payload() {
        return {
            message_text: document.getElementById('message-text').value || '',
            segment: document.getElementById('segment').value,
            segment_params: '{}'
        };
    }

    previewBtn.addEventListener('click', async function () {
        const data = payload();
        if (!data.message_text.trim()) { RB.toast('متن پیام را وارد کنید.', 'warning'); return; }
        box.innerHTML = '<div class="loading"><span class="spinner"></span> در حال ساخت پیش‌نمایش…</div>';
        const result = await RB.post('<?= url('admin/broadcast/preview') ?>', data);
        if (!result.ok) { box.innerHTML = '<div class="text-muted text-sm">خطا در پیش‌نمایش</div>'; RB.toast(result.error || 'خطا', 'error'); return; }
        box.innerHTML = '<div style="white-space:pre-wrap;line-height:1.9;font-size:.86rem">' + RB.escape(result.preview || data.message_text) + '</div>';
        countEl.textContent = 'تعداد مخاطبان این گروه: ' + RB.faNum(Number(result.count || 0).toLocaleString('en-US')) + ' نفر';
    });

    testBtn.addEventListener('click', async function () {
        const data = payload();
        if (!data.message_text.trim()) { RB.toast('متن پیام را وارد کنید.', 'warning'); return; }
        const result = await RB.post('<?= url('admin/broadcast/test') ?>', data);
        RB.toast(result.ok ? (result.message || 'ارسال شد') : (result.error || 'ارسال نشد'), result.ok ? 'success' : 'error', 6000);
    });
})();
</script>
