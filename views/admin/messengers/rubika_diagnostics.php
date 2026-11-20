<?php
// All fields are selected from an allowlist; never include webhook URLs, IDs, IPs, or message contents.
$sources = ['api_test'=>'تست getMe', 'endpoint_setup'=>'ثبت endpoint', 'webhook'=>'وب‌هوک', 'poll_check'=>'بررسی getUpdates', 'polling'=>'رویداد پولینگ'];
$results = ['received'=>'رسید؛ پایان پردازش ثبت نشده', 'processed'=>'پردازش پایان یافت', 'duplicate'=>'تکراری؛ پردازش نشد', 'ignored'=>'رویداد پشتیبانی‌نشده/نادیده', 'disabled'=>'پیام‌رسان غیرفعال', 'invalid_json'=>'JSON نامعتبر', 'empty'=>'بدنه خالی', 'too_large'=>'بدنه بیش از حد مجاز', 'handler_error'=>'خطای پردازش', 'ok'=>'موفق', 'error'=>'خطا'];
$sends = ['none'=>'ارسالی انجام نشد', 'ok'=>'API ارسال را با شناسه پیام پذیرفت', 'error'=>'خطای ارسال', 'mixed'=>'بخشی از ارسال‌ها ناموفق', 'unconfirmed'=>'پاسخ فاقد شناسه پیام؛ تأییدنشده'];
$reasons = ['user_unknown'=>'کاربر هنوز شروع نکرده', 'blocked'=>'حساب مسدود', 'group'=>'چت غیرخصوصی', 'rate_limit'=>'محدودیت درخواست', 'unknown_callback'=>'دکمه ناشناخته', 'missing_identity'=>'شناسه لازم موجود نیست', 'no_response'=>'هندلر بدون ارسال پایان یافت', 'identity_lookup_failed'=>'getChat شناسه کاربر را برنگرداند', 'database_error'=>'خطای پایگاه داده در پردازش', 'processing_error'=>'استثنای پردازش؛ جزئیات در لاگ سرور'];
$report = [];
$report[] = 'گزارش عیب‌یابی روبیکا — نسخه ' . (defined('RB_VERSION') ? RB_VERSION : 'نامشخص');
$report[] = 'وضعیت محلی وب‌هوک: ' . ((($conn['webhook_status'] ?? '') === 'active') ? 'ثبت گزارش شده؛ تحویل از روبیکا تأیید نشده' : 'ثبت فعال تأیید نشده');
$report[] = 'پولینگ: ' . (!empty($conn['polling_enabled']) ? 'فعال' : 'غیرفعال');
foreach ($rubikaDiagnostics as $d) {
    $line = (string)$d['created_at'] . ' | ' . ($sources[$d['source']] ?? 'نامشخص') . ' | ' . ($d['event_type'] ?: 'unknown') . ' | ' . ($results[$d['result']] ?? 'نامشخص');
    if (($d['source'] ?? '') === 'webhook' || ($d['source'] ?? '') === 'polling') $line .= ' | ' . ($sends[$d['send_result']] ?? 'نامشخص') . ' (' . (int)$d['send_count'] . ')';
    if (!empty($d['reason'])) $line .= ' | ' . ($reasons[$d['reason']] ?? 'نامشخص');
    if ((int)$d['http_code'] > 0) $line .= ' | HTTP ' . (int)$d['http_code'];
    if (!empty($d['api_status'])) $line .= ' | API ' . $d['api_status'];
    if (!empty($d['api_detail'])) $line .= ' | ' . $d['api_detail'];
    if ((int)($d['api_code'] ?? 0) > 0) $line .= ' | error_code ' . (int)$d['api_code'];
    $line .= ' | ' . (int)$d['duration_ms'] . 'ms';
    $report[] = $line;
}
?>
<div class="card mb-3" id="rubika-diagnostics">
    <div class="card-header justify-between"><div><h2 class="card-title">🩺 عیب‌یابی دریافت و پاسخ روبیکا</h2><span class="card-subtitle">گزارش محدود و بدون متن پیام، شناسه کاربر، توکن یا آدرس وب‌هوک</span></div>
        <a href="<?= url('admin/messengers?tab=rubika#rubika-diagnostics') ?>" class="btn btn-secondary btn-sm">🔄 تازه‌سازی گزارش</a>
    </div>
    <div class="card-body text-sm" style="line-height:2">
        <p><strong>تست اتصال فقط getMe را بررسی می‌کند.</strong> نتیجه موفق به معنی دریافت پیام یا تحویل پاسخ نیست. وضعیت «ثبت‌شده» وب‌هوک نیز صرفاً نتیجه آخرین درخواست ثبت است، نه تأیید دسترسی از شبکه روبیکا.</p>
        <p>برای بررسی: روی «تست اتصال» بزنید، در روبیکا <code>/start</code> و یک دکمه را بزنید، سپس این گزارش را تازه‌سازی کنید. اگر ردیف وب‌هوک تازه‌ای نیست، درخواست به مسیر معتبر برنامه نرسیده است؛ دامنه، HTTPS، فایروال، مسیر و ثبت وب‌هوک را بررسی کنید. نبود ردیف به‌تنهایی علت را مشخص نمی‌کند.</p>
        <p class="text-xs text-muted">رویداد ثبت‌شده با راز صحیح نشانه رسیدن درخواست به برنامه است، نه اثبات هویت فرستنده. «API ارسال را پذیرفت» هم تضمین نمایش پیام روی دستگاه کاربر نیست. در حالت پولینگ، اجرای منظم Cron لازم است.</p>
        <?php if (!$rubikaDiagnosticsAvailable): ?>
            <div class="alert alert-warning">جدول عیب‌یابی در دسترس نیست؛ ارتقا و اجرای migrationها را بررسی کنید.</div>
        <?php else: ?>
            <div class="form-group"><label class="form-label" for="rubika-safe-report">آخرین <?= fa_digits(count($rubikaDiagnostics)) ?> رویداد عملیاتی (زمان سرور)</label>
                <textarea id="rubika-safe-report" class="form-control ltr mono" readonly rows="<?= max(4, min(12, count($report))) ?>"><?= e(implode("\n", $report)) ?></textarea>
            </div>
            <div class="flex gap-1 wrap"><button type="button" class="btn btn-secondary btn-sm" onclick="RB.copy(document.getElementById('rubika-safe-report').value)">📋 کپی گزارش امن</button></div>
            <?php if (!$rubikaDiagnostics): ?><p class="text-muted text-xs">هنوز رویدادی پس از نصب این نسخه ثبت نشده است.</p><?php endif; ?>
        <?php endif; ?>
    </div>
</div>
