<?php
$statusBadge = ['draft' => 'muted', 'queued' => 'info', 'sending' => 'warning',
    'sent' => 'success', 'failed' => 'danger', 'cancelled' => 'muted'];
$statusLabel = ['draft' => 'پیش‌نویس', 'queued' => 'در صف', 'sending' => 'در حال ارسال',
    'sent' => 'ارسال‌شده', 'failed' => 'ناموفق', 'cancelled' => 'لغو‌شده'];
$totalTargets = array_sum($counts);
$activeMsg = (isset($activeMessengers) && is_array($activeMessengers)) ? $activeMessengers : [];
$msgTotals = (isset($messengerTotals) && is_array($messengerTotals)) ? $messengerTotals : [];
?>
<div class="flex items-center justify-between wrap gap-3 mb-3 broadcast-header">
    <div class="broadcast-title-wrap">
        <h1 class="mb-0">ارسال پیام همگانی</h1>
        <p class="text-muted text-sm mb-0">ارسال مرحله‌ای با محدودیت نرخ توسط Cron و مدیریت هوشمند صف — مناسب هاست اشتراکی</p>
    </div>
    <div class="flex gap-2 wrap broadcast-actions">
        <form method="post" action="<?= url('admin/broadcast/process-now') ?>" data-ajax class="broadcast-action-item">
            <?= csrf_field() ?>
            <button class="btn btn-success w-full" type="submit" data-confirm="صف ارسال هم‌اکنون برای ۵۰ پیام بعدی اجرا شود؟">⚡ اجرای فوری صف ارسال</button>
        </form>
        <form method="post" action="<?= url('admin/broadcast/recover') ?>" data-ajax class="broadcast-action-item">
            <?= csrf_field() ?>
            <button class="btn btn-secondary w-full" type="submit" data-confirm="کمپین‌های معلق یا قدیمی بررسی و به صف بازگردانده شوند؟">🔄 بازیابی کمپین‌های گذشته</button>
        </form>
        <a href="<?= url('admin/broadcast/log/download') ?>" class="btn btn-ghost broadcast-action-item" title="دانلود فایل کامل لاگ">📥 دانلود لاگ ارسال</a>
    </div>
</div>

<div class="alert alert-info mb-3">
    <span class="alert-icon">⏱</span>
    <div class="alert-content-wrap">
        <div><strong>نحوهٔ اجرای منظم ارسال همگانی:</strong> پیام‌ها به‌صورت دسته‌ای و با تأخیر تصاعدی (Backoff) ارسال می‌شوند تا پلتفرم‌ها محدودیت نگذارند. برای هاست اشتراکی یا سرور لینوکس، کرون‌جاب زیر را فعال کنید:</div>
        <div class="cron-box mt-2">
            <span class="cron-code ltr mono">*/2 * * * * php <?= e(RB_ROOT) ?>/bin/cron.php broadcast >/dev/null 2>&1</span>
        </div>
        <?php if (!empty($webCronToken)): ?>
            <div class="cron-web-box mt-2 text-xs">
                <span>همچنین می‌توانید از <strong>کرون وب</strong> استفاده نمایید:</span>
                <div class="cron-box mt-1">
                    <span class="cron-code ltr mono"><?= e(url('cron/run?token=' . $webCronToken . '&task=broadcast')) ?></span>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="stats-grid mb-3">
    <div class="stat">
        <div class="stat-head"><div class="stat-icon">✉️</div><div class="stat-label">کل پیام‌های سیستم</div></div>
        <div class="stat-value"><?= fa_digits(number_format((int) ($msgTotals['messages_sent'] ?? 0))) ?></div>
        <div class="stat-foot">همگانی: <?= fa_digits(number_format((int) ($msgTotals['broadcasts_sent'] ?? 0))) ?> · ناموفق: <?= fa_digits((int) ($msgTotals['messages_failed'] ?? 0)) ?></div>
    </div>
    <?php foreach ($activeMsg as $mp => $mi): ?>
        <div class="stat" style="border-top:3px solid <?= e((string) ($mi['color'] ?? 'var(--brand-500)')) ?>">
            <div class="stat-head">
                <div class="stat-icon"><?= e((string) ($mi['icon'] ?? '🤖')) ?></div>
                <div class="stat-label">پیام‌های <?= e((string) ($mi['name'] ?? $mp)) ?></div>
            </div>
            <div class="stat-value"><?= fa_digits(number_format((int) ($mi['messages_sent'] ?? 0))) ?></div>
            <div class="stat-foot">دریافتی: <?= fa_digits((int) ($mi['updates_received'] ?? 0)) ?> · ناموفق: <?= fa_digits((int) ($mi['messages_failed'] ?? 0)) ?></div>
        </div>
    <?php endforeach; ?>
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
                <div class="form-row">
                    <?php if (count($activeMsg) > 1): ?>
                        <div class="form-group">
                            <label class="form-label">پیام‌رسان مقصد</label>
                            <select name="target_platform" class="form-select" id="target-platform">
                                <option value="all">🌐 همه پیام‌رسان‌های فعال و متصل (<?= fa_digits(count($activeMsg)) ?>)</option>
                                <?php foreach ($activeMsg as $mp => $mi): ?>
                                    <option value="<?= e($mp) ?>"><?= e(($mi['icon'] ?? '') . ' فقط ' . ($mi['users_label'] ?? ('کاربران ' . ($mi['name'] ?? $mp)))) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($activeMsg['eitaa'])): ?>
                                <div class="form-hint">برای کاربران ایتا اعلان در بخش «اعلان‌ها»ی مینی‌اپ نیز ثبت می‌شود.</div>
                            <?php endif; ?>
                        </div>
                    <?php elseif (count($activeMsg) === 1):
                        $singleKey = (string) array_key_first($activeMsg);
                        $singleInfo = $activeMsg[$singleKey];
                    ?>
                        <div class="form-group">
                            <label class="form-label">پیام‌رسان مقصد</label>
                            <input type="hidden" name="target_platform" id="target-platform" value="<?= e($singleKey) ?>">
                            <div class="form-control" style="background:var(--surface-2);font-weight:600">
                                <?= e(($singleInfo['icon'] ?? '') . ' ' . ($singleInfo['users_label'] ?? ('کاربران ' . ($singleInfo['name'] ?? $singleKey)))) ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="target_platform" id="target-platform" value="all">
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label">گروه مخاطبان</label>
                        <select name="segment" class="form-select" id="segment">
                            <?php foreach ($segments as $key => $label): ?>
                                <option value="<?= e($key) ?>"><?= e($label) ?> (<?= fa_digits((int) ($counts[$key] ?? 0)) ?> نفر)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
            <div class="card-footer flex gap-2 wrap broadcast-form-footer">
                <button class="btn" type="submit" data-confirm="کمپین ارسال ایجاد شود؟ ارسال به‌صورت خودکار توسط صف و Cron انجام خواهد شد.">🚀 ایجاد و شروع ارسال</button>
                <button class="btn btn-secondary" type="button" id="preview-btn">👁 پیش‌نمایش و مخاطبان</button>
                <button class="btn btn-ghost" type="button" id="test-btn">📮 ارسال آزمایشی به مدیر</button>
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

<div class="card mb-3">
    <div class="card-header flex items-center justify-between wrap gap-2">
        <div>
            <h2 class="card-title">📋 لاگ و گزارش عیب‌یابی ارسال همگانی</h2>
            <span class="card-subtitle">رویدادها و خطاهای لحظه‌ای ارسال پیام‌ها</span>
        </div>
        <div class="flex gap-2">
            <a href="<?= url('admin/broadcast/log/download') ?>" class="btn btn-sm btn-secondary">دانلود لاگ</a>
            <form method="post" action="<?= url('admin/broadcast/log/clear') ?>" data-ajax>
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-ghost" type="submit" data-confirm="فایل لاگ ارسال همگانی پاک‌سازی شود؟">پاک‌سازی</button>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <pre class="ltr mono text-xs mb-0" style="max-height:260px;overflow:auto;padding:14px;background:#0f172a;color:#f8fafc;direction:ltr;text-align:left;border-radius:0 0 12px 12px;white-space:pre-wrap;word-break:break-all"><?= e((string) ($recentLogs ?? 'هنوز لاگی ثبت نشده است.')) ?></pre>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title">کمپین‌های ارسال همگانی</h2>
        <span class="card-subtitle">۲۵ کمپین آخر به ترتیب اولویت و تاریخ</span></div>
    <?php if (empty($campaigns)): ?>
        <div class="empty"><div class="empty-icon">✉️</div><div class="empty-title">هنوز کمپینی ایجاد نشده</div>
            <div class="empty-text">اولین پیام همگانی خود را از فرم بالا ایجاد کنید.</div></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>شناسه</th><th>عنوان</th><th>پیام‌رسان</th><th>گروه</th><th>وضعیت</th><th>پیشرفت</th><th>موفق</th><th>ناموفق</th><th>بلاک</th><th>تاریخ</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($campaigns as $c): ?>
                    <?php
                    $total = max(1, (int) $c['total_targets']);
                    $done = (int) $c['sent_count'] + (int) $c['failed_count'] + (int) $c['blocked_count'];
                    $percent = min(100, (int) round($done / $total * 100));
                    $cPlat = strtolower((string) ($c['target_platform'] ?? 'all'));
                    $cPlatLabel = ($cPlat === '' || $cPlat === 'all') ? '🌐 همه فعال‌ها' : \RB\Messengers\MessengerCapabilities::badge($cPlat);
                    ?>
                    <tr>
                        <td class="num" data-label="شناسه">#<?= e((string) $c['id']) ?></td>
                        <td data-label="عنوان"><a href="<?= url('admin/broadcast/' . $c['id']) ?>"><?= e(mb_strimwidth((string) $c['title'], 0, 40, '…')) ?></a></td>
                        <td data-label="پیام‌رسان"><span class="badge badge-muted no-dot"><?= e($cPlatLabel) ?></span></td>
                        <td data-label="گروه"><?= e($segments[$c['segment']] ?? (string) $c['segment']) ?></td>
                        <td data-label="وضعیت"><span class="badge badge-<?= $statusBadge[$c['status']] ?? 'muted' ?>"><?= e($statusLabel[$c['status']] ?? (string) $c['status']) ?></span></td>
                        <td data-label="پیشرفت">
                            <div style="min-width:110px">
                                <div style="height:6px;background:var(--surface-3);border-radius:99px;overflow:hidden">
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
                                <?php if ((int) ($c['failed_count'] ?? 0) > 0): ?>
                                    <form method="post" action="<?= url('admin/broadcast/' . $c['id'] . '/retry-failed') ?>" data-ajax>
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-warning" type="submit" data-confirm="مخاطبان ناموفق دوباره در صف قرار بگیرند؟">تلاش مجدد</button>
                                    </form>
                                <?php endif; ?>
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
        const tp = document.getElementById('target-platform');
        return {
            message_text: document.getElementById('message-text').value || '',
            segment: document.getElementById('segment').value,
            target_platform: tp ? tp.value : 'all',
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
