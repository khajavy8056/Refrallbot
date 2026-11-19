<?php
/** @var string $currentVersion */
/** @var array|null $latest */
/** @var bool $hasUpdate */
/** @var string $repo */
/** @var string $channel */
/** @var bool $autoCheck */
/** @var string|null $maskedToken */
/** @var string $lastCheckAt */
/** @var string $lastError */
/** @var string $lastAppliedAt */
/** @var string $lastAppliedFrom */
/** @var string $lastAppliedTo */
/** @var array $checks */
/** @var array $backups */
/** @var array $history */
/** @var int $maxUploadMb */

$zipReady = !empty($checks['zip']['ok']);
$allChecksOk = true;
foreach ($checks as $c) {
    if (empty($c['ok'])) {
        $allChecksOk = false;
        break;
    }
}
$latestVersion = (string) ($latest['version'] ?? '');
$hasOfficialAsset = !empty($latest['has_official_asset']);
$sourceLabels = [
    'github' => 'گیت‌هاب',
    'upload' => 'آپلود ZIP',
    'rollback' => 'بازگردانی دستی',
];
$statusBadges = [
    'success' => ['موفق', 'success'],
    'restored' => ['بازگردانی شد', 'info'],
    'failed' => ['ناموفق', 'danger'],
];
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">به‌روزرسانی سامانه</h1>
        <p class="text-muted text-sm mb-0">ارتقای امن از مخزن رسمی گیت‌هاب یا فایل ZIP — همراه با پشتیبان خودکار و بازگردانی</p>
    </div>
    <form method="post" action="<?= url('admin/update/check') ?>" data-ajax>
        <?= csrf_field() ?>
        <button class="btn" type="submit">🔍 بررسی نسخه جدید</button>
    </form>
</div>

<div class="alert alert-info mb-3">
    <span class="alert-icon">🛡</span>
    <div>
        <strong>ارتقای تجمیعی یک‌مرحله‌ای و ایمنی کامل داده‌ها:</strong>
        نسخه‌های رسمی به‌صورت تجمیعی (مستقیماً قابل ارتقا از نسخهٔ <span class="ltr mono">1.2.0</span> و بالاتر در یک مرحله بدون نیاز به نصب نسخه‌های میانی) عرضه می‌شوند.
        فایل <span class="ltr mono">.env</span>، پوشهٔ <span class="ltr mono">storage/</span>، کاربران، حساب‌های متصل، کیف پول‌ها، تراکنش‌ها، زیرمجموعه‌ها و تنظیمات اتصال در هیچ حالتی بازنویسی نمی‌شوند؛ پیش از اعمال، پشتیبان کامل دیتابیس و فایل‌ها گرفته شده و تمام Migrationها به‌صورت زنجیره‌ای و ایمن اجرا (یا در صورت خطا خودکار بازگردانی) می‌گردند.
    </div>
</div>

<?php if ($lastError !== ''): ?>
    <div class="alert alert-error mb-3">
        <span class="alert-icon">⚠️</span>
        <div>
            <strong>آخرین خطای بررسی نسخه:</strong>
            <div class="text-sm mt-1"><?= e($lastError) ?></div>
            <?php if ($lastCheckAt !== ''): ?>
                <div class="text-xs text-muted mt-1">زمان: <?= fa_date($lastCheckAt) ?></div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(195px,1fr))">
    <div class="stat info">
        <div class="stat-head"><div class="stat-icon">🏷</div><div class="stat-label">نسخهٔ نصب‌شده</div></div>
        <div class="stat-value ltr" style="font-size:1.15rem">v<?= e($currentVersion) ?></div>
        <div class="stat-foot">
            <?= $lastAppliedAt !== '' ? 'آخرین ارتقا: ' . fa_date($lastAppliedAt, false) : 'نصب اولیه' ?>
        </div>
    </div>

    <div class="stat <?= $hasUpdate ? 'warning' : 'success' ?>">
        <div class="stat-head"><div class="stat-icon">🚀</div><div class="stat-label">وضعیت نسخه</div></div>
        <div class="stat-value" style="font-size:1.05rem">
            <?php if ($hasUpdate): ?>
                <span class="badge badge-warning">نسخه v<?= e($latestVersion) ?> آماده</span>
            <?php elseif ($latest !== null): ?>
                <span class="badge badge-success">به‌روز است</span>
            <?php else: ?>
                <span class="badge badge-muted">بررسی نشده</span>
            <?php endif; ?>
        </div>
        <div class="stat-foot">
            <?= $lastCheckAt !== '' ? 'آخرین بررسی: ' . fa_date($lastCheckAt) : 'دکمهٔ «بررسی نسخه جدید» را بزنید' ?>
        </div>
    </div>

    <div class="stat <?= $allChecksOk ? 'success' : 'danger' ?>">
        <div class="stat-head"><div class="stat-icon">⚙️</div><div class="stat-label">پیش‌نیازهای سرور</div></div>
        <div class="stat-value" style="font-size:1.05rem"><?= $allChecksOk ? 'آماده' : 'نیازمند بررسی' ?></div>
        <div class="stat-foot">PHP <?= e(PHP_VERSION) ?> · <?= $zipReady ? 'ZipArchive فعال' : 'ZipArchive غیرفعال' ?></div>
    </div>

    <div class="stat purple">
        <div class="stat-head"><div class="stat-icon">💾</div><div class="stat-label">پشتیبان فایل‌ها</div></div>
        <div class="stat-value"><?= fa_digits(count($backups)) ?></div>
        <div class="stat-foot">قابل بازگردانی از همین صفحه</div>
    </div>
</div>

<?php if (!$allChecksOk): ?>
    <div class="card mb-3">
        <div class="card-header"><h2 class="card-title">وضعیت پیش‌نیازهای سرور</h2></div>
        <div class="card-body">
            <?php foreach ($checks as $item): ?>
                <div class="flex items-center justify-between wrap gap-2" style="padding:6px 0;border-bottom:1px solid var(--border)">
                    <span><?= !empty($item['ok']) ? '✅' : '❌' ?> <strong><?= e($item['label']) ?></strong></span>
                    <span class="text-sm text-muted"><?= e($item['detail']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="grid-2 mb-3">
    <!-- کارت ۱: به‌روزرسانی خودکار از گیت‌هاب -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">به‌روزرسانی از گیت‌هاب</h2>
            <span class="card-subtitle ltr mono"><?= e($repo) ?></span>
        </div>
        <div class="card-body">
            <?php if ($latest === null): ?>
                <p class="text-sm text-muted">
                    هنوز اطلاعات نسخه‌های منتشرشده دریافت نشده است. روی دکمهٔ
                    <strong>«بررسی نسخه جدید»</strong> در بالای صفحه کلیک کنید.
                </p>
            <?php elseif ($hasUpdate): ?>
                <div class="alert alert-success mb-2">
                    <span class="alert-icon">🎉</span>
                    <div>
                        <strong>نسخهٔ جدید <span class="ltr mono">v<?= e($latestVersion) ?></span> منتشر شده است!</strong>
                        <?php if (!empty($latest['name']) && $latest['name'] !== ($latest['tag'] ?? '')): ?>
                            <div class="text-sm"><?= e((string) $latest['name']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($latest['published_at'])): ?>
                            <div class="text-xs text-muted mt-1">تاریخ انتشار: <?= fa_date((string) $latest['published_at']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($latest['body'])): ?>
                    <div class="form-group">
                        <label class="form-label">تغییرات این نسخه</label>
                        <pre class="text-xs" style="background:var(--bg-soft,#f8fafc);padding:10px 12px;border-radius:8px;border:1px solid var(--border);white-space:pre-wrap;max-height:180px;overflow:auto;margin:0"><?= e((string) $latest['body']) ?></pre>
                    </div>
                <?php endif; ?>

                <?php if ($hasOfficialAsset): ?>
                    <div class="text-xs text-muted mb-2">
                        بستهٔ رسمی: <span class="ltr mono"><?= e((string) ($latest['asset_name'] ?? '')) ?></span>
                        <?php if (!empty($latest['asset_size'])): ?>
                            (<?= fa_digits(number_format(((int) $latest['asset_size']) / 1024, 1)) ?> KB)
                        <?php endif; ?>
                        <?php if (!empty($latest['sha256_url'])): ?>
                            · همراه با فایل تأیید <span class="ltr mono">SHA-256</span>
                        <?php endif; ?>
                    </div>
                    <form method="post" action="<?= url('admin/update/github') ?>" data-ajax>
                        <?= csrf_field() ?>
                        <input type="hidden" name="tag" value="<?= e((string) ($latest['tag'] ?? '')) ?>">
                        <button class="btn btn-success btn-block" type="submit" <?= $zipReady ? '' : 'disabled' ?>
                                data-confirm="نسخهٔ v<?= e($latestVersion) ?> دانلود و نصب شود؟ پیش از نصب، پشتیبان کامل دیتابیس و فایل‌ها گرفته می‌شود.">
                            ⬇️ دانلود و نصب نسخه v<?= e($latestVersion) ?>
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning mb-0">
                        <span class="alert-icon">⚠️</span>
                        <div class="text-sm">
                            برای این Release بستهٔ رسمی ZIP (حاوی <span class="ltr mono">CHECKSUMS.sha256</span>) پیوست نشده است.
                            لطفاً بستهٔ ساخته‌شده با <span class="ltr mono">php bin/build-update.php</span> را در Release قرار دهید
                            یا از کادر روبه‌رو آپلود کنید.
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-success mb-2">
                    <span class="alert-icon">✅</span>
                    <div>
                        سامانه روی آخرین نسخه (<span class="ltr mono">v<?= e($currentVersion) ?></span>) قرار دارد و نیازی به به‌روزرسانی نیست.
                    </div>
                </div>
                <?php if (!empty($latest['html_url'])): ?>
                    <a class="btn btn-secondary btn-sm" href="<?= e((string) $latest['html_url']) ?>" target="_blank" rel="noopener noreferrer">
                        مشاهده صفحه Release در گیت‌هاب ↗
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- کارت ۲: نصب دستی از فایل ZIP -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">نصب دستی با آپلود فایل ZIP</h2>
            <span class="card-subtitle">مناسب هاست‌های بدون دسترسی به گیت‌هاب</span>
        </div>
        <form method="post" action="<?= url('admin/update/upload') ?>" enctype="multipart/form-data" data-ajax>
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">فایل بستهٔ رسمی (<span class="ltr mono">.zip</span>)</label>
                    <input type="file" name="package" class="form-control ltr" accept=".zip,application/zip" required <?= $zipReady ? '' : 'disabled' ?>>
                    <div class="form-hint">
                        حداکثر حجم: <?= fa_digits($maxUploadMb) ?> مگابایت. فقط بسته‌های رسمی دارای فایل
                        <span class="ltr mono">VERSION</span> و <span class="ltr mono">CHECKSUMS.sha256</span> پذیرفته می‌شوند
                        (ساخته‌شده با <span class="ltr mono">php bin/build-update.php</span>).
                    </div>
                </div>
            </div>
            <div class="card-footer">
                <button class="btn" type="submit" <?= $zipReady ? '' : 'disabled' ?>
                        data-confirm="بستهٔ ZIP انتخاب‌شده بررسی و نصب شود؟ قبل از نصب پشتیبان خودکار گرفته می‌شود.">
                    📦 آپلود و نصب بسته
                </button>
            </div>
        </form>
    </div>
</div>

<!-- کارت تنظیمات به‌روزرسانی -->
<div class="card mb-3">
    <div class="card-header">
        <h2 class="card-title">تنظیمات به‌روزرسانی</h2>
        <span class="card-subtitle">منبع انتشار و بررسی خودکار روزانه</span>
    </div>
    <form method="post" action="<?= url('admin/update/settings') ?>" data-ajax>
        <?= csrf_field() ?>
        <div class="card-body grid-2">
            <div class="form-group">
                <label class="form-label">مخزن گیت‌هاب (<span class="ltr mono">owner/repo</span>)</label>
                <input type="text" name="repo" class="form-control ltr" dir="ltr" required
                       placeholder="<?= e(\RB\Services\UpdateService::DEFAULT_REPO) ?>"
                       value="<?= e($repo) ?>">
                <div class="form-hint">نام مالک و مخزن در GitHub (بدون <span class="ltr mono">.git</span>).</div>
            </div>

            <div class="form-group">
                <label class="form-label">کانال دریافت نسخه</label>
                <select name="channel" class="form-control">
                    <option value="stable" <?= $channel === 'stable' ? 'selected' : '' ?>>فقط نسخه‌های پایدار (پیشنهادی)</option>
                    <option value="prerelease" <?= $channel === 'prerelease' ? 'selected' : '' ?>>شامل پیش‌انتشار (Pre-release)</option>
                </select>
                <div class="form-hint">در محیط عملیاتی همیشه روی «نسخه‌های پایدار» نگه دارید.</div>
            </div>

            <div class="form-group">
                <label class="form-label">توکن دسترسی گیت‌هاب (اختیاری)</label>
                <input type="password" name="github_token" class="form-control ltr" dir="ltr" autocomplete="new-password"
                       placeholder="ghp_... یا github_pat_...">
                <div class="form-hint">
                    <?php if ($maskedToken !== null): ?>
                        توکن ذخیره‌شده (رمزنگاری AES-256-GCM): <span class="ltr mono"><?= e($maskedToken) ?></span> — برای حفظ همان توکن، این کادر را خالی بگذارید.
                    <?php else: ?>
                        برای مخزن عمومی نیازی به توکن نیست؛ فقط برای مخزن خصوصی یا افزایش سقف درخواست API وارد کنید.
                    <?php endif; ?>
                </div>
                <?php if ($maskedToken !== null): ?>
                    <label class="checkbox mt-1">
                        <input type="checkbox" name="clear_token" value="1">
                        <span class="checkbox-text text-xs">حذف توکن ذخیره‌شدهٔ گیت‌هاب</span>
                    </label>
                <?php endif; ?>
            </div>

            <div class="form-group" style="display:flex;align-items:center">
                <div>
                    <label class="checkbox">
                        <input type="checkbox" name="auto_check" value="1" <?= $autoCheck ? 'checked' : '' ?>>
                        <span class="checkbox-text">بررسی خودکار روزانه نسخهٔ جدید</span>
                    </label>
                    <div class="form-hint mt-1">
                        حداکثر روزی یک بار هنگام ورود مدیر کل به داشبورد، وجود نسخهٔ جدید بررسی می‌شود (نصب همیشه فقط با تأیید شما انجام می‌شود).
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button class="btn" type="submit">💾 ذخیره تنظیمات</button>
        </div>
    </form>
</div>

<!-- کارت بازگردانی فایل‌ها -->
<div class="card mb-3">
    <div class="card-header">
        <h2 class="card-title">بازگردانی فایل‌ها</h2>
        <span class="card-subtitle"><?= fa_digits(count($backups)) ?> پشتیبان · پشتیبان دیتابیس در بخش <a href="<?= url('admin/backup') ?>">پشتیبان‌گیری</a></span>
    </div>
    <?php if (empty($backups)): ?>
        <div class="empty">
            <div class="empty-icon">📦</div>
            <div class="empty-title">پشتیبان فایلی ثبت نشده است</div>
            <div class="empty-text">پیش از هر به‌روزرسانی، یک نسخهٔ کامل از فایل‌های برنامه در <span class="ltr mono">storage/backups</span> ذخیره می‌شود.</div>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>نام فایل پشتیبان</th>
                    <th>نسخه</th>
                    <th>حجم</th>
                    <th>تاریخ ساخت</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($backups as $b): ?>
                    <tr>
                        <td class="ltr mono text-xs" data-label="فایل"><?= e($b['name']) ?></td>
                        <td class="ltr mono text-xs" data-label="نسخه">v<?= e($b['version']) ?></td>
                        <td class="num" data-label="حجم"><?= fa_digits(number_format(((int) $b['size']) / 1024, 1)) ?> KB</td>
                        <td class="text-xs" data-label="تاریخ"><?= fa_date($b['created_at']) ?></td>
                        <td data-label="">
                            <form method="post" action="<?= url('admin/update/restore') ?>" data-ajax style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="backup" value="<?= e($b['name']) ?>">
                                <button class="btn btn-sm btn-secondary" type="submit"
                                        data-confirm="فایل‌های سامانه به پشتیبان «<?= e($b['name']) ?>» بازگردانی شوند؟ فایل .env و داده‌های دیتابیس تغییر نمی‌کنند.">
                                    ↩️ بازگردانی فایل‌ها
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- کارت تاریخچهٔ به‌روزرسانی -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">تاریخچهٔ به‌روزرسانی‌ها</h2>
        <span class="card-subtitle"><?= fa_digits(count($history)) ?> رویداد اخیر</span>
    </div>
    <?php if (empty($history)): ?>
        <div class="empty">
            <div class="empty-icon">📋</div>
            <div class="empty-title">هنوز به‌روزرسانی انجام نشده است</div>
            <div class="empty-text">سوابق ارتقا و بازگردانی با تاریخ و نام فایل پشتیبان در این جدول نمایش داده می‌شود.</div>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>تاریخ</th>
                    <th>وضعیت</th>
                    <th>روش</th>
                    <th>نسخه</th>
                    <th>پشتیبان دیتابیس</th>
                    <th>توضیحات</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $h):
                    [$sLabel, $sBadge] = $statusBadges[$h['status'] ?? ''] ?? [((string) ($h['status'] ?? '—')), 'muted'];
                    $srcLabel = $sourceLabels[$h['source'] ?? ''] ?? ((string) ($h['source'] ?? '—'));
                ?>
                    <tr>
                        <td class="text-xs" data-label="تاریخ"><?= fa_date((string) ($h['at'] ?? '')) ?></td>
                        <td data-label="وضعیت"><span class="badge badge-<?= e($sBadge) ?>"><?= e($sLabel) ?></span></td>
                        <td class="text-xs" data-label="روش"><?= e($srcLabel) ?></td>
                        <td class="ltr mono text-xs" data-label="نسخه">
                            <?php if (!empty($h['from'])): ?>
                                v<?= e((string) $h['from']) ?> → v<?= e((string) ($h['to'] ?? '')) ?>
                            <?php else: ?>
                                v<?= e((string) ($h['to'] ?? '—')) ?>
                            <?php endif; ?>
                        </td>
                        <td class="ltr mono text-xs" data-label="پشتیبان"><?= e((string) ($h['db_backup'] ?? $h['files_backup'] ?? '—')) ?></td>
                        <td class="text-xs" data-label="توضیحات">
                            <?php if (!empty($h['error'])): ?>
                                <?= e((string) $h['error']) ?>
                            <?php elseif (!empty($h['restored'])): ?>
                                فایل‌های نسخهٔ قبلی بازگردانی شدند.
                            <?php else: ?>
                                به‌روزرسانی با موفقیت اعمال شد.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
