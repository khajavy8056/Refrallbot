<?php
$connected = !empty($settings['bot_token_enc']);
$statusMap = [
    'connected' => ['متصل', 'success'],
    'error' => ['خطا در اتصال', 'danger'],
    'disconnected' => ['قطع', 'muted'],
];
[$statusLabel, $statusBadge] = $statusMap[$settings['connection_status'] ?? 'disconnected'] ?? ['نامشخص', 'muted'];
$webhookActive = !empty($settings['webhook_active']);
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">اتصال به ربات تلگرام</h1>
        <p class="text-muted text-sm mb-0">ثبت توکن، تست اتصال، تنظیم Webhook و همگام‌سازی منوها</p>
    </div>
    <a href="?refresh=1" class="btn btn-secondary btn-sm">🔄 به‌روزرسانی وضعیت از تلگرام</a>
</div>

<?php if (!empty($settings['last_error'])): ?>
    <div class="alert alert-error mb-3">
        <strong>آخرین خطای ثبت‌شده:</strong>
        <div class="text-sm mt-1"><?= e((string) $settings['last_error']) ?></div>
        <?php if (!empty($settings['updated_at'])): ?>
            <div class="text-xs text-muted mt-1">زمان: <?= fa_date($settings['updated_at']) ?></div>
        <?php endif; ?>
        <div class="text-xs mt-1">پس از رفع مشکل، «تست اتصال» را بزنید تا این پیام پاک شود.</div>
    </div>
<?php endif; ?>

<div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(190px,1fr))">
    <div class="stat <?= $connected ? 'success' : 'danger' ?>">
        <div class="stat-head"><div class="stat-icon">🤖</div><div class="stat-label">وضعیت ربات</div></div>
        <div class="stat-value" style="font-size:1.05rem"><span class="badge badge-<?= $statusBadge ?>"><?= e($statusLabel) ?></span></div>
        <div class="stat-foot"><?= !empty($settings['bot_username']) ? '@' . e((string) $settings['bot_username']) : 'توکن ثبت نشده' ?></div>
    </div>
    <div class="stat <?= $webhookActive ? 'success' : 'warning' ?>">
        <div class="stat-head"><div class="stat-icon">🔗</div><div class="stat-label">Webhook</div></div>
        <div class="stat-value" style="font-size:1.05rem"><?= $webhookActive ? 'فعال' : 'غیرفعال' ?></div>
        <div class="stat-foot"><?= !empty($settings['webhook_set_at']) ? fa_date($settings['webhook_set_at']) : 'تنظیم نشده' ?></div>
    </div>
    <div class="stat info"><div class="stat-head"><div class="stat-icon">📨</div><div class="stat-label">پیام‌های ارسالی</div></div>
        <div class="stat-value"><?= fa_digits(number_format($counts['messages_sent'])) ?></div>
        <div class="stat-foot">ناموفق: <?= fa_digits(number_format($counts['messages_failed'])) ?></div></div>
    <div class="stat purple"><div class="stat-head"><div class="stat-icon">🔄</div><div class="stat-label">آپدیت‌های دریافتی</div></div>
        <div class="stat-value"><?= fa_digits(number_format($counts['updates'])) ?></div>
        <div class="stat-foot"><?= !empty($settings['last_update_at']) ? fa_date($settings['last_update_at']) : 'بدون آپدیت' ?></div></div>
</div>

<?php if (!$connected): ?>
    <div class="alert alert-warning">
        <span class="alert-icon">🔑</span>
        <div>
            برای شروع، توکن ربات را از <span class="ltr">@BotFather</span> دریافت و در فرم زیر ثبت کنید.
            پس از ثبت موفق، آدرس Webhook پیشنهادی به‌صورت خودکار محاسبه می‌شود.
        </div>
    </div>
<?php endif; ?>

<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header"><h2 class="card-title">توکن ربات</h2>
            <span class="card-subtitle">به‌صورت رمزنگاری‌شده AES-256-GCM ذخیره می‌شود</span></div>
        <form method="post" action="<?= url('admin/telegram/save') ?>" data-ajax>
            <?= csrf_field() ?>
            <div class="card-body">
                <?php if ($maskedToken): ?>
                    <div class="alert alert-success">
                        <span class="alert-icon">✅</span>
                        <div>توکن ذخیره‌شده: <span class="ltr mono"><?= e($maskedToken) ?></span><br>
                            <span class="text-xs">توکن کامل هرگز نمایش داده نمی‌شود. برای جایگزینی، توکن جدید را وارد کنید.</span></div>
                    </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label"><?= $connected ? 'توکن جدید (برای جایگزینی)' : 'توکن ربات' ?></label>
                    <input type="password" name="bot_token" class="form-control ltr" autocomplete="off" dir="ltr"
                           placeholder="123456789:AAExxxxxxxxxxxxxxxxxxxxxxxxxxx">
                    <div class="form-hint">قالب معتبر: عدد ۶ تا ۱۲ رقمی، دو نقطه، سپس رشته ۳۰ تا ۵۰ کاراکتری.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">آدرس پایه سامانه (اختیاری)</label>
                    <input type="text" name="base_url" class="form-control ltr" dir="ltr"
                           placeholder="<?= e(\RB\Support\Request::origin()) ?>"
                           value="<?= e((string) (setting('webhook_base_url', '') ?: '')) ?>">
                    <div class="form-hint">برای ساخت خودکار آدرس Webhook و لینک Mini App استفاده می‌شود. باید با HTTPS در دسترس عموم باشد.</div>
                </div>
            </div>
            <div class="card-footer flex gap-2 wrap">
                <button class="btn" type="submit">💾 ذخیره و تست توکن</button>
            </div>
        </form>
        <?php if ($connected): ?>
            <div class="card-body" style="padding-top:0">
                <div class="flex gap-2 wrap">
                    <form method="post" action="<?= url('admin/telegram/test') ?>" data-ajax>
                        <?= csrf_field() ?>
                        <button class="btn btn-secondary" type="submit">🔌 تست اتصال</button>
                    </form>
                    <form method="post" action="<?= url('admin/telegram/disconnect') ?>" data-ajax>
                        <?= csrf_field() ?>
                        <button class="btn btn-danger" type="submit" data-confirm="اتصال ربات قطع و توکن حذف شود؟">قطع اتصال</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">Webhook</h2>
            <span class="card-subtitle">دریافت پیام‌ها از تلگرام</span></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">آدرس پیشنهادی</label>
                <div class="copy-field">
                    <span class="ltr mono" style="word-break:break-all"><?= e($suggestedWebhook) ?></span>
                    <button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($suggestedWebhook) ?>">کپی</button>
                </div>
                <div class="form-hint">
                    تلگرام فقط آدرس‌های <strong>HTTPS با گواهی معتبر</strong> را می‌پذیرد. اگر سامانه روی IP و بدون SSL اجرا شود،
                    Webhook کار نمی‌کند و باید دامنه/گواهی تهیه کنید. پنل مدیریت اما روی IP هم کار می‌کند.
                </div>
            </div>
            <form method="post" action="<?= url('admin/telegram/webhook/set') ?>" data-ajax>
                <?= csrf_field() ?>
                <div class="form-group">
                    <label class="form-label">آدرس Webhook</label>
                    <input type="text" name="webhook_url" class="form-control ltr" dir="ltr" value="<?= e((string) ($settings['webhook_url'] ?? $suggestedWebhook)) ?>">
                </div>
                <button class="btn btn-success btn-block" type="submit" data-confirm="Webhook روی تلگرام تنظیم شود؟">🔗 تنظیم Webhook</button>
            </form>
            <?php if ($webhookActive): ?>
                <form method="post" action="<?= url('admin/telegram/webhook/delete') ?>" data-ajax class="mt-2">
                    <?= csrf_field() ?>
                    <button class="btn btn-secondary btn-block" type="submit" data-confirm="Webhook حذف شود؟ ربات دیگر پیام دریافت نمی‌کند.">حذف Webhook</button>
                </form>
            <?php endif; ?>

            <?php if (!empty($webhookInfo)): ?>
                <div class="divider"></div>
                <div class="text-sm">
                    <div class="mb-1"><strong>URL فعال:</strong> <span class="ltr mono text-xs"><?= e((string) ($webhookInfo['url'] ?? '—')) ?></span></div>
                    <div class="mb-1"><strong>آپدیت‌های در انتظار:</strong> <?= fa_digits((int) ($webhookInfo['pending_update_count'] ?? 0)) ?></div>
                    <?php if (!empty($webhookInfo['last_error_message'])): ?>
                        <div class="alert alert-error mb-0 mt-2">
                            <span class="alert-icon">⚠️</span>
                            <div>
                                آخرین خطای تلگرام: <?= e((string) $webhookInfo['last_error_message']) ?>
                                <?php if (!empty($webhookInfo['last_error_date'])): ?>
                                    <div class="text-xs"><?= fa_date(date('Y-m-d H:i:s', (int) $webhookInfo['last_error_date'])) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$currentUiMode = \RB\Services\SettingsService::configuredBotUiMode();
$effectiveUiMode = \RB\Services\SettingsService::botUiMode();
$miniappUrlCurrent = \RB\Services\SettingsService::resolvedMiniappUrl();
$defaultMiniUrl = $miniappUrlCurrent;
if ($defaultMiniUrl === '') {
    $autoOrigin = rtrim((string) (setting('webhook_base_url', '') ?: \RB\Support\Request::origin()), '/');
    $autoAppPath = rtrim((string) \RB\Support\Config::get('app.base_path', ''), '/');
    if ($autoAppPath !== '' && str_ends_with($autoOrigin, $autoAppPath)) {
        $autoOrigin = substr($autoOrigin, 0, -strlen($autoAppPath));
    }
    if (str_starts_with(strtolower($autoOrigin), 'https://')) {
        $defaultMiniUrl = rtrim($autoOrigin, '/') . url('app');
    }
}
?>
<div class="card mb-3" id="bot-mode">
    <div class="card-header justify-between">
        <div>
            <h2 class="card-title">🎛 حالت رابط کاربری ربات (تغییر فوری مینی‌اپ ↔ دکمه‌ای)</h2>
            <span class="card-subtitle">هر زمان بخواهید می‌توانید با یک کلیک بین حالت مینی‌اپ، حالت دکمه‌ای یا حالت ترکیبی جابه‌جا شوید</span>
        </div>
        <span class="badge badge-<?= $effectiveUiMode === 'miniapp' ? 'brand' : ($effectiveUiMode === 'hybrid' ? 'success' : 'info') ?>">
            فعال: <?= e(\RB\Services\SettingsService::botUiModeLabel($effectiveUiMode)) ?>
        </span>
    </div>
    <form method="post" action="<?= url('admin/telegram/mode') ?>" data-ajax>
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="mode-switcher-grid">
                <label class="mode-card <?= $currentUiMode === 'miniapp' ? 'selected' : '' ?>">
                    <input type="radio" name="bot_ui_mode" value="miniapp" <?= $currentUiMode === 'miniapp' ? 'checked' : '' ?>>
                    <div class="mode-card-head">
                        <span class="mode-card-icon">📱</span>
                        <span class="mode-card-title">حالت مینی‌اپ</span>
                        <?php if ($currentUiMode === 'miniapp'): ?><span class="badge badge-brand no-dot">انتخاب‌شده</span><?php endif; ?>
                    </div>
                    <p class="mode-card-desc">تمرکز کامل روی وب‌اپ گرافیکی داخل تلگرام (داشبورد، نمودار دعوت‌ها، کیف پول و برداشت) به همراه دسترسی سریع لینک دعوت و پشتیبانی.</p>
                </label>

                <label class="mode-card <?= $currentUiMode === 'buttons' ? 'selected' : '' ?>">
                    <input type="radio" name="bot_ui_mode" value="buttons" <?= $currentUiMode === 'buttons' ? 'checked' : '' ?>>
                    <div class="mode-card-head">
                        <span class="mode-card-icon">⌨️</span>
                        <span class="mode-card-title">حالت دکمه‌ای ربات</span>
                        <?php if ($currentUiMode === 'buttons'): ?><span class="badge badge-info no-dot">انتخاب‌شده</span><?php endif; ?>
                    </div>
                    <p class="mode-card-desc">مینی‌اپ به‌طور کامل خاموش می‌شود و کاربران تمام کارها (حساب، دعوت، درآمد، برداشت و جشنواره‌ها) را با دکمه‌های خود ربات انجام می‌دهند.</p>
                </label>

                <label class="mode-card <?= $currentUiMode === 'hybrid' ? 'selected' : '' ?>">
                    <input type="radio" name="bot_ui_mode" value="hybrid" <?= $currentUiMode === 'hybrid' ? 'checked' : '' ?>>
                    <div class="mode-card-head">
                        <span class="mode-card-icon">✨</span>
                        <span class="mode-card-title">حالت ترکیبی (هر دو)</span>
                        <?php if ($currentUiMode === 'hybrid'): ?><span class="badge badge-success no-dot">انتخاب‌شده</span><?php endif; ?>
                    </div>
                    <p class="mode-card-desc">هم دکمه ورود به مینی‌اپ گرافیکی و هم تمامی دکمه‌های کلاسیک ربات به کاربر نمایش داده می‌شود تا هر طور راحت‌تر است استفاده کند.</p>
                </label>
            </div>

            <div class="form-group mt-3 mb-0">
                <label class="form-label">آدرس مینی‌اپ (HTTPS)</label>
                <input type="text" name="miniapp_url" class="form-control ltr" dir="ltr" maxlength="255"
                       value="<?= e($defaultMiniUrl) ?>" placeholder="https://example.com/app">
                <div class="form-hint">
                    در حالت مینی‌اپ و ترکیبی استفاده می‌شود (الزاماً با <code class="ltr">https://</code>). هنگام انتخاب «حالت دکمه‌ای»، دکمه مینی‌اپ از پایین چت تلگرام هم به‌طور خودکار برداشته می‌شود.
                </div>
            </div>
        </div>
        <div class="card-footer flex items-center justify-between wrap gap-2">
            <span class="text-xs text-muted">تغییر حالت بلافاصله روی پیام‌های جدید و دکمه منوی تلگرام اعمال می‌شود.</span>
            <button class="btn" type="submit">⚡ اعمال و همگام‌سازی حالت ربات</button>
        </div>
    </form>
</div>

<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header"><h2 class="card-title">منوها و دستورات ربات</h2>
            <span class="card-subtitle"><?= !empty($settings['menu_synced']) ? 'آخرین همگام‌سازی: ' . fa_date($settings['menu_synced_at']) : 'همگام‌سازی نشده' ?></span></div>
        <div class="card-body">
            <p class="text-sm text-muted">با همگام‌سازی، دستورات پیش‌فرض (<span class="ltr">start / menu / help</span>) و دکمه منوی Mini App روی ربات تنظیم می‌شود.</p>
            <form method="post" action="<?= url('admin/telegram/menu/sync') ?>" data-ajax>
                <?= csrf_field() ?>
                <button class="btn btn-block" type="submit">🔄 همگام‌سازی منوها و دستورات</button>
            </form>
            <a href="<?= url('admin/telegram/menus') ?>" class="btn btn-secondary btn-block mt-2">ویرایش متن دکمه‌ها</a>
            <div class="text-xs text-muted mt-2">
                دکمه Mini App فقط وقتی ثبت می‌شود که آدرس HTTPS معتبر ذخیره شده باشد
                (فعلی: <span class="ltr"><?= e((string) (setting('miniapp_url', '') ?: 'ثبت نشده')) ?></span>).
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">آخرین پیام‌های ربات</h2></div>
        <?php if (empty($lastMessages)): ?>
            <div class="empty" style="padding:26px"><div class="empty-icon">📭</div><div class="empty-title">پیامی ثبت نشده</div>
                <div class="empty-text">پس از فعال بودن Webhook، پیام‌های ورودی و خروجی اینجا ثبت می‌شوند.</div></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>جهت</th><th>نوع</th><th>وضعیت</th><th>زمان</th></tr></thead>
                    <tbody>
                    <?php foreach ($lastMessages as $m): ?>
                        <tr>
                            <td data-label="جهت"><span class="badge badge-<?= $m['direction'] === 'in' ? 'info' : 'success' ?>"><?= $m['direction'] === 'in' ? 'ورودی' : 'خروجی' ?></span></td>
                            <td data-label="نوع"><?= e((string) ($m['kind'] ?? '—')) ?></td>
                            <td data-label="وضعیت"><?= e((string) ($m['status'] ?? '—')) ?></td>
                            <td class="text-xs" data-label="زمان"><?= fa_date($m['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title">⏱ کرون وب (برای هاست اشتراکی بدون crontab)</h2></div>
    <div class="card-body">
        <div class="alert alert-info">
            <span class="alert-icon">ℹ️</span>
            <div>
                اگر کنترل پنل هاست به شما اجازه‌ی ساخت Cron نمی‌دهد، این آدرس را در بخش
                «Cron Jobs» هاست یا هر سرویس زمان‌بندی رایگان (مثلاً cron-job.org) هر ۵ دقیقه صدا بزنید.
                آدرس شامل توکن است؛ آن را در اختیار کسی قرار ندهید.
            </div>
        </div>
        <?php
        $cronBase = rtrim((string) (setting('webhook_base_url', '') ?: \RB\Support\Request::origin()), '/');
        $cronAppPath = rtrim((string) \RB\Support\Config::get('app.base_path', ''), '/');
        if ($cronAppPath !== '' && str_ends_with($cronBase, $cronAppPath)) {
            $cronBase = substr($cronBase, 0, -strlen($cronAppPath));
        }
        $cronUrl = rtrim($cronBase, '/') . url('cron/run') . '?token=' . ($cronToken ?? '') . '&task=all';
        ?>
        <label class="form-label">آدرس اجرای کامل وظایف</label>
        <div class="copy-field">
            <span class="ltr text-xs"><?= e($cronUrl) ?></span>
            <button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($cronUrl) ?>">کپی</button>
        </div>
        <div class="form-hint">
            وظیفه‌های اجراشده: ارسال همگانی در صف، جوایز کمپین‌ها، نگهداری و پاکسازی، پشتیبان روزانه، بازبینی امنیتی.
            می‌توانید با <code>&amp;task=broadcast</code> فقط بخش ارسال را اجرا کنید.
        </div>
        <div class="form-hint">
            اگر به خط فرمان دسترسی دارید، همین کارها با
            <code class="ltr">php bin/cron.php all</code> هم اجرا می‌شوند.
        </div>
    </div>
</div>

<?php if ($connected && !empty($settings['bot_username'])): ?>
<div class="card">
    <div class="card-header"><h2 class="card-title">لینک‌های عمومی</h2></div>
    <div class="card-body grid-2">
        <div>
            <label class="form-label">لینک ربات</label>
            <div class="copy-field"><span class="ltr">https://t.me/<?= e((string) $settings['bot_username']) ?></span>
                <button class="btn btn-ghost btn-sm" type="button" data-copy="https://t.me/<?= e((string) $settings['bot_username']) ?>">کپی</button></div>
        </div>
        <div>
            <label class="form-label">آدرس Mini App</label>
            <div class="copy-field"><span class="ltr"><?= e((string) (setting('miniapp_url', '') ?: '—')) ?></span>
                <button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e((string) setting('miniapp_url', '')) ?>">کپی</button></div>
        </div>
    </div>
</div>
<?php endif; ?>
