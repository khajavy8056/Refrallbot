<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">پشتیبان‌گیری</h1>
        <p class="text-muted text-sm mb-0">دامپ SQL از تمام جدول‌ها — بدون نیاز به ابزار خارجی روی هاست</p>
    </div>
</div>

<div class="alert alert-warning">
    <span class="alert-icon">⚠️</span>
    <div>
        فایل‌های پشتیبان شامل اطلاعات حساس (هش رمز، شماره کارت رمزنگاری‌شده، توکن رمزنگاری‌شده) هستند.
        آن‌ها را در پوشه عمومی وب قرار ندهید و پس از دانلود، از سرور حذف کنید.
        کلید رمزنگاری <span class="ltr mono">.env</span> در پشتیبان دیتابیس نیست؛ برای بازیابی، کلید قبلی را نگه دارید.
    </div>
</div>

<div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))">
    <div class="stat info"><div class="stat-head"><div class="stat-icon">🗄</div><div class="stat-label">درایور دیتابیس</div></div>
        <div class="stat-value" style="font-size:1.05rem"><?= e($driver === 'mysql' ? 'MySQL / MariaDB' : strtoupper($driver)) ?></div>
        <div class="stat-foot"><?= fa_digits($tables) ?> جدول</div></div>
    <div class="stat"><div class="stat-head"><div class="stat-icon">👥</div><div class="stat-label">کاربران</div></div>
        <div class="stat-value"><?= fa_digits(number_format($stats['users'])) ?></div></div>
    <div class="stat success"><div class="stat-head"><div class="stat-icon">🧾</div><div class="stat-label">تراکنش‌های مالی</div></div>
        <div class="stat-value"><?= fa_digits(number_format($stats['transactions'])) ?></div></div>
    <div class="stat purple"><div class="stat-head"><div class="stat-icon">🏦</div><div class="stat-label">درخواست برداشت</div></div>
        <div class="stat-value"><?= fa_digits(number_format($stats['withdrawals'])) ?></div></div>
</div>

<div class="card mb-3">
    <div class="card-header"><h2 class="card-title">ساخت نسخه پشتیبان</h2></div>
    <div class="card-body">
        <form method="post" action="<?= url('admin/backup/create') ?>" data-ajax>
            <?= csrf_field() ?>
            <button class="btn" type="submit" data-confirm="نسخه پشتیبان جدید ساخته شود؟">💾 ساخت نسخه پشتیبان</button>
        </form>
        <div class="form-hint mt-2">
            پشتیبان‌ها در <span class="ltr mono">storage/backups</span> ذخیره می‌شوند (بیرون از پوشه public).
            برای دیتابیس‌های بسیار بزرگ، از <span class="ltr mono">mysqldump</span> هاست استفاده کنید.
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title">فایل‌های پشتیبان</h2>
        <span class="card-subtitle"><?= fa_digits(count($files)) ?> فایل</span></div>
    <?php if (empty($files)): ?>
        <div class="empty"><div class="empty-icon">💾</div><div class="empty-title">پشتیبانی ساخته نشده</div>
            <div class="empty-text">با دکمه بالا اولین نسخه پشتیبان را بسازید. پیشنهاد می‌شود این کار با Cron روزانه انجام شود.</div></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>نام فایل</th><th>حجم</th><th>تاریخ ساخت</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($files as $f): ?>
                    <tr>
                        <td class="ltr mono text-xs" data-label="فایل"><?= e($f['name']) ?></td>
                        <td class="num" data-label="حجم"><?= fa_digits(number_format($f['size'] / 1024, 1)) ?> KB</td>
                        <td class="text-xs" data-label="تاریخ"><?= fa_date($f['created_at']) ?></td>
                        <td data-label="">
                            <a class="btn btn-sm btn-secondary" href="<?= url('admin/backup/download/' . rawurlencode($f['name'])) ?>">⬇️ دانلود</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
