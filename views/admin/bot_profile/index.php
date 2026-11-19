<?php
$selectedPlatform = $selectedPlatform ?? 'telegram';
$allMessengers = $allMessengers ?? [];
$currentMessenger = $currentMessenger ?? null;
?>
<!-- انتخابگر پیام‌رسان -->
<div class="flex gap-1 wrap mb-3">
    <?php foreach ($allMessengers as $mp => $mc): ?>
        <a href="<?= url('admin/bot-profile?platform=' . $mp) ?>"
           class="btn btn-sm <?= $mp === $selectedPlatform ? '' : 'btn-secondary' ?>">
            <?= e(($mc['meta']['icon'] ?? '🤖') . ' ' . ($mc['meta']['name'] ?? $mp)) ?>
            <?php if (!empty($mc['is_enabled']) && !empty($mc['has_token'])): ?>
                <span style="font-size:.65rem;opacity:.85">●</span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($selectedPlatform !== 'telegram' && is_array($currentMessenger)): ?>
    <?php
    $mMeta = $currentMessenger['meta'] ?? [];
    $mName = (string) ($mMeta['name'] ?? $selectedPlatform);
    $mIcon = (string) ($mMeta['icon'] ?? '🤖');
    $mUser = ltrim((string) ($currentMessenger['bot_username'] ?? ''), '@');
    $mBadge = $currentMessenger['status_badge'] ?? ['label' => 'نامشخص', 'class' => 'badge-muted'];
    $panelGuide = match ($selectedPlatform) {
        'bale'   => 'بازوی رسمی @BotFather در پیام‌رسان بله (دستور /mybots → Edit Bot)',
        'rubika' => 'ربات رسمی @BotFather در پیام‌رسان روبیکا (بخش مدیریت ربات)',
        'eitaa'  => 'پنل توسعه‌دهندگان ایتا به نشانی developer.eitaa.com (بخش تنظیمات برنامک)',
        default  => 'پنل رسمی پیام‌رسان',
    };
    ?>
    <div class="flex items-center justify-between wrap gap-3 mb-3">
        <div>
            <h1 class="mb-0"><?= e($mIcon) ?> پروفایل و مشخصات ربات در <?= e($mName) ?></h1>
            <p class="text-muted text-sm mb-0">مدیریت نام، بیوگرافی و توضیحات معرفی ربات برای <?= e($mName) ?></p>
        </div>
        <div class="flex gap-1 wrap">
            <?php if ($selectedPlatform !== 'eitaa' && !empty($currentMessenger['has_token'])): ?>
                <form method="post" action="<?= url('admin/bot-profile/sync') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="platform" value="<?= e($selectedPlatform) ?>">
                    <button type="submit" class="btn btn-secondary btn-sm">🔄 استعلام اطلاعات از <?= e($mName) ?></button>
                </form>
            <?php endif; ?>
            <a href="<?= url('admin/messengers?tab=' . $selectedPlatform) ?>" class="btn btn-outline btn-sm">📡 تنظیمات اتصال <?= e($mName) ?></a>
        </div>
    </div>

    <div class="alert alert-info mb-3">
        <span class="alert-icon">ℹ️</span>
        <div class="text-sm" style="line-height:1.9">
            <strong>شفافیت قابلیت API رسمی <?= e($mName) ?>:</strong>
            برخلاف تلگرام، API رسمی <?= e($mName) ?> در حال حاضر متد برنامه‌نویسی برای تغییر مستقیم تصویر پروفایل و بیوگرافی ربات ارائه نمی‌دهد.
            در این صفحه می‌توانید متن‌های رسمی نام، بیوگرافی و توضیحات معرفی <?= e($mName) ?> را ذخیره و با یک کلیک کپی کنید تا در <b><?= e($panelGuide) ?></b> ثبت نمایید.
        </div>
    </div>

    <div class="grid-2">
        <div class="card">
            <div class="card-header justify-between">
                <h2 class="card-title">✏️ مشخصات و بیوگرافی <?= e($mName) ?></h2>
                <span class="badge <?= e($mBadge['class']) ?>"><?= e($mBadge['label']) ?></span>
            </div>
            <form method="post" action="<?= url('admin/bot-profile/save') ?>" class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="platform" value="<?= e($selectedPlatform) ?>">
                <div class="form-group">
                    <label class="form-label">نام نمایشی ربات (حداکثر ۶۴ کاراکتر)</label>
                    <div style="display:flex;gap:6px">
                        <input type="text" name="bot_name" class="form-control" maxlength="64" value="<?= e((string) ($currentMessenger['bot_name'] ?? '')) ?>">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="RB.copy(this.previousElementSibling.value)">📋</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">بیوگرافی کوتاه / درباره ربات (حداکثر ۱۲۰ کاراکتر)</label>
                    <textarea name="bot_short_description" class="form-control" rows="2" maxlength="120"><?= e((string) ($currentMessenger['bot_short_description'] ?? '')) ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">توضیحات کامل معرفی (حداکثر ۵۱۲ کاراکتر)</label>
                    <textarea name="bot_description" class="form-control" rows="4" maxlength="512"><?= e((string) ($currentMessenger['bot_description'] ?? '')) ?></textarea>
                </div>
                <button type="submit" class="btn">💾 ذخیره در پنل مدیریت</button>
            </form>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">📌 وضعیت فعلی در <?= e($mName) ?></h2></div>
            <div class="card-body text-sm" style="line-height:2.1">
                <div><strong>نام کاربری:</strong> <span class="ltr mono"><?= $mUser !== '' ? '@' . e($mUser) : 'ثبت‌نشده' ?></span></div>
                <div><strong>شناسه ربات:</strong> <span class="ltr mono"><?= e((string) ($currentMessenger['bot_id'] ?: '—')) ?></span></div>
                <div><strong>آخرین بررسی:</strong> <?= !empty($currentMessenger['profile_synced_at']) ? fa_date($currentMessenger['profile_synced_at']) : '—' ?></div>
                <div><strong>محل تغییر تصویر پروفایل:</strong> <?= e($panelGuide) ?></div>
            </div>
        </div>
    </div>
<?php return; endif; ?>
<?php
$connected = ($settings['connection_status'] ?? '') === 'connected' && $configured;
$botName = (string) ($settings['bot_name'] ?? '');
$botUsername = ltrim((string) ($settings['bot_username'] ?? ''), '@');
$botId = (int) ($settings['bot_id'] ?? 0);
$botBio = (string) ($settings['bot_short_description'] ?? setting('bot_bio_text', ''));
$botDesc = (string) ($settings['bot_description'] ?? setting('bot_description_text', ''));
$webhookActive = !empty($settings['webhook_active']);
$syncedAt = (string) ($settings['profile_synced_at'] ?? '');
$photoSrc = $hasPhoto ? (url('admin/bot-profile/photo') . '?v=' . rawurlencode((string) ($settings['updated_at'] ?? time()))) : '';
$initial = mb_substr($botName !== '' ? $botName : setting('app_name', 'R'), 0, 1);
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">🎨 پروفایل و ظاهر ربات تلگرام</h1>
        <p class="text-muted text-sm mb-0">
            مدیریت تصویر پروفایل، بیوگرافی کوتاه (Bio)، توضیحات معرفی و مشاهده وضعیت فعلی ربات مستقیماً از پنل مدیریت
        </p>
    </div>
    <div class="flex gap-2 wrap">
        <?php if ($configured): ?>
            <form method="post" action="<?= url('admin/bot-profile/sync') ?>">
                <?= csrf_field() ?>
                <button class="btn btn-secondary btn-sm" type="submit">🔄 دریافت اطلاعات فعلی از تلگرام</button>
            </form>
        <?php endif; ?>
        <a href="<?= url('admin/telegram') ?>" class="btn btn-outline btn-sm">🤖 تنظیمات اتصال و Webhook</a>
    </div>
</div>

<?php if (!$configured): ?>
    <div class="alert alert-warning mb-3">
        <span class="alert-icon">⚠️</span>
        <div>
            <strong>ربات هنوز متصل نشده است.</strong>
            می‌توانید بیوگرافی و توضیحات ربات را همین حالا ذخیره کنید؛ برای اعمال مستقیم تغییرات و آپلود تصویر پروفایل در تلگرام، ابتدا توکن ربات را در بخش
            <a href="<?= url('admin/telegram') ?>"><b>اتصال به ربات</b></a> ثبت نمایید.
        </div>
    </div>
<?php endif; ?>

<!-- ردیف وضعیت و اطلاعات فعلی ربات -->
<div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(195px,1fr))">
    <div class="stat <?= $connected ? 'success' : 'warning' ?>">
        <div class="stat-head"><div class="stat-icon">🤖</div><div class="stat-label">وضعیت ربات</div></div>
        <div class="stat-value" style="font-size:1.05rem"><?= $connected ? 'متصل و فعال' : ($configured ? 'نیازمند بررسی' : 'متصل نشده') ?></div>
        <div class="stat-foot"><?= $botUsername !== '' ? '<a class="ltr mono" target="_blank" rel="noopener" href="https://t.me/' . e($botUsername) . '">@' . e($botUsername) . '</a>' : 'نام کاربری ثبت نشده' ?></div>
    </div>
    <div class="stat <?= $webhookActive ? 'info' : 'warning' ?>">
        <div class="stat-head"><div class="stat-icon">🔗</div><div class="stat-label">وضعیت Webhook</div></div>
        <div class="stat-value" style="font-size:1.05rem"><?= $webhookActive ? 'فعال (دریافت آنی)' : 'غیرفعال' ?></div>
        <div class="stat-foot">حالت نمایش: <?= e($botUiModeLabel ?: $botUiMode) ?></div>
    </div>
    <div class="stat purple">
        <div class="stat-head"><div class="stat-icon">📨</div><div class="stat-label">تبادل پیام ربات</div></div>
        <div class="stat-value" style="font-size:1.05rem"><?= fa_digits(number_format((int) ($settings['updates_count'] ?? 0))) ?><small> دریافتی</small></div>
        <div class="stat-foot">ارسالی موفق: <?= fa_digits(number_format((int) ($settings['messages_sent'] ?? 0))) ?></div>
    </div>
    <div class="stat">
        <div class="stat-head"><div class="stat-icon">🕒</div><div class="stat-label">آخرین همگام‌سازی پروفایل</div></div>
        <div class="stat-value" style="font-size:.98rem"><?= $syncedAt !== '' ? fa_date($syncedAt) : '—' ?></div>
        <div class="stat-foot">شناسه ربات: <span class="ltr mono"><?= $botId > 0 ? e((string) $botId) : '—' ?></span></div>
    </div>
</div>

<div class="grid-2 mb-3">
    <!-- ستون ۱: مدیریت تصویر پروفایل ربات + پیش‌نمایش زنده پروفایل تلگرام -->
    <div class="flex" style="flex-direction:column;gap:16px">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📸 تصویر پروفایل ربات</h2>
                <span class="card-subtitle">تنظیم و تغییر مستقیم آواتار ربات</span>
            </div>
            <div class="card-body">
                <div class="flex items-center gap-3 wrap mb-3">
                    <div id="bot-avatar-box" style="width:92px;height:92px;border-radius:50%;overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#6366f1,#06b6d4);color:#fff;font-size:2rem;font-weight:800;border:3px solid var(--border);box-shadow:0 8px 24px rgba(0,0,0,.22)">
                        <img id="bot-avatar-img" src="<?= e($photoSrc) ?>" alt="تصویر پروفایل ربات"
                             style="width:100%;height:100%;object-fit:cover;<?= $hasPhoto ? '' : 'display:none' ?>">
                        <span id="bot-avatar-fallback" style="<?= $hasPhoto ? 'display:none' : '' ?>"><?= e($initial) ?></span>
                    </div>
                    <div style="flex:1;min-width:190px">
                        <div class="font-bold mb-1"><?= $hasPhoto ? '✅ تصویر پروفایل فعال است' : 'هنوز تصویر پروفایلی تنظیم نشده است' ?></div>
                        <div class="text-xs text-muted" style="line-height:1.7">
                            فرمت‌های مجاز: <b>JPG، PNG، WebP</b> (حداکثر ۵ مگابایت)<br>
                            ابعاد پیشنهادی: <b>۵۱۲×۵۱۲</b> یا <b>۶۴۰×۶۴۰ پیکسل</b> (تصاویر مستطیلی به‌صورت خودکار از مرکز مربعی می‌شوند).
                        </div>
                    </div>
                </div>

                <?php if (!$curlAvailable): ?>
                    <div class="alert alert-warning mb-3">
                        <span class="alert-icon">⚠️</span>
                        <div>افزونه PHP <code>cURL</code> روی سرور فعال نیست؛ برای ارسال فایل تصویر به تلگرام فعال بودن <code>ext-curl</code> الزامی است.</div>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= url('admin/bot-profile/photo') ?>" enctype="multipart/form-data" id="bot-photo-form">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label">انتخاب تصویر جدید</label>
                        <input type="file" name="photo" id="bot-photo-input" class="form-control" accept="image/jpeg,image/png,image/webp" required>
                    </div>
                    <div class="flex gap-2 wrap">
                        <button class="btn" type="submit" <?= !$curlAvailable && $configured ? 'disabled' : '' ?>>
                            📸 ذخیره و اعمال تصویر پروفایل
                        </button>
                    </div>
                </form>

                <?php if ($hasPhoto): ?>
                    <form method="post" action="<?= url('admin/bot-profile/photo/remove') ?>" style="margin-top:10px">
                        <?= csrf_field() ?>
                        <button class="btn btn-ghost btn-sm text-danger" type="submit" data-confirm="تصویر پروفایل فعلی ربات از تلگرام حذف شود؟">
                            🗑 حذف تصویر پروفایل فعلی
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- پیش‌نمایش زنده پروفایل ربات در تلگرام -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">👁 پیش‌نمایش زنده در تلگرام</h2>
                <span class="card-subtitle">نمایش به کاربران</span>
            </div>
            <div class="card-body">
                <div style="border:1px solid var(--border);border-radius:14px;padding:18px;background:var(--surface-2,rgba(15,23,42,.45))">
                    <div class="flex items-center gap-3 mb-3">
                        <div style="width:60px;height:60px;border-radius:50%;overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#6366f1,#06b6d4);color:#fff;font-size:1.4rem;font-weight:800">
                            <img id="preview-avatar-img" src="<?= e($photoSrc) ?>" alt="" style="width:100%;height:100%;object-fit:cover;<?= $hasPhoto ? '' : 'display:none' ?>">
                            <span id="preview-avatar-fallback" style="<?= $hasPhoto ? 'display:none' : '' ?>"><?= e($initial) ?></span>
                        </div>
                        <div style="min-width:0">
                            <div class="flex items-center gap-2 wrap">
                                <strong id="preview-bot-name" style="font-size:1.05rem"><?= e($botName !== '' ? $botName : setting('app_name', 'رفرال‌بات')) ?></strong>
                                <span class="badge badge-info no-dot" style="font-size:.68rem">ربات</span>
                            </div>
                            <div class="text-xs text-muted ltr" style="text-align:right">
                                <?= $botUsername !== '' ? '@' . e($botUsername) : '@your_bot' ?>
                            </div>
                        </div>
                    </div>

                    <div style="padding:10px 12px;border-radius:10px;border:1px solid var(--border);margin-bottom:10px">
                        <div class="text-xs text-muted mb-1">درباره (Bio)</div>
                        <div id="preview-bot-bio" class="text-sm" style="white-space:pre-wrap;word-break:break-word"><?= $botBio !== '' ? e($botBio) : '<span class="text-muted">بیوگرافی کوتاه ربات اینجا نمایش داده می‌شود…</span>' ?></div>
                    </div>

                    <div style="padding:10px 12px;border-radius:10px;border:1px dashed var(--border)">
                        <div class="text-xs text-muted mb-1">این ربات چه کاری انجام می‌دهد؟ (صفحه شروع)</div>
                        <div id="preview-bot-desc" class="text-sm" style="white-space:pre-wrap;word-break:break-word"><?= $botDesc !== '' ? e($botDesc) : '<span class="text-muted">توضیحات معرفی ربات پیش از زدن دکمه Start…</span>' ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ستون ۲: ویرایش بیوگرافی (Bio)، توضیحات معرفی، نام نمایشی و جدول اطلاعات فعلی ربات -->
    <div class="flex" style="flex-direction:column;gap:16px">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📝 بیوگرافی (Bio)، نام و توضیحات ربات</h2>
                <span class="card-subtitle">اعمال مستقیم از طریق API تلگرام</span>
            </div>
            <form method="post" action="<?= url('admin/bot-profile/save') ?>" id="bot-bio-form">
                <?= csrf_field() ?>
                <input type="hidden" name="apply_telegram_present" value="1">
                <div class="card-body">
                    <div class="form-group">
                        <div class="flex justify-between items-center mb-1">
                            <label class="form-label mb-0" for="input-bot-name">نام نمایشی ربات</label>
                            <span class="text-xs text-muted ltr mono" id="count-bot-name">0 / 64</span>
                        </div>
                        <input type="text" name="bot_name" id="input-bot-name" class="form-control" maxlength="64"
                               value="<?= e($botName) ?>" placeholder="مثلاً: ربات دعوت دوستان و کسب درآمد">
                        <div class="form-hint">نامی که در بالای صفحه چت و فهرست گفتگوهای کاربران نمایش داده می‌شود.</div>
                    </div>

                    <div class="form-group">
                        <div class="flex justify-between items-center mb-1">
                            <label class="form-label mb-0" for="input-bot-bio">بیوگرافی کوتاه ربات (Bio / About)</label>
                            <span class="text-xs text-muted ltr mono" id="count-bot-bio">0 / 120</span>
                        </div>
                        <textarea name="bot_short_description" id="input-bot-bio" class="form-control" rows="3" maxlength="120"
                                  placeholder="دوستانت را دعوت کن و هم تو و هم دوستت پاداش نقدی آنی دریافت کنید! 🎁"><?= e($botBio) ?></textarea>
                        <div class="form-hint">در صفحه پروفایل ربات (بخش Bio) و هنگام اشتراک‌گذاری لینک ربات نمایش داده می‌شود (حداکثر ۱۲۰ کاراکتر).</div>
                    </div>

                    <div class="form-group">
                        <div class="flex justify-between items-center mb-1">
                            <label class="form-label mb-0" for="input-bot-desc">توضیحات معرفی ربات (صفحه شروع پیش از Start)</label>
                            <span class="text-xs text-muted ltr mono" id="count-bot-desc">0 / 512</span>
                        </div>
                        <textarea name="bot_description" id="input-bot-desc" class="form-control" rows="5" maxlength="512"
                                  placeholder="به ربات رسمی دعوت دوستان خوش آمدید! 👋&#10;با کلیک روی دکمه Start عضو شوید، هدیه خوش‌آمدگویی بگیرید و با دعوت دوستانتان درآمد نقدی کسب کنید."><?= e($botDesc) ?></textarea>
                        <div class="form-hint">این متن در کادر «این ربات چه کاری انجام می‌دهد؟» به کاربران جدید پیش از زدن دکمه Start نمایش داده می‌شود (حداکثر ۵۱۲ کاراکتر).</div>
                    </div>

                    <label class="checkbox">
                        <input type="checkbox" name="apply_telegram" value="1" checked>
                        <span class="checkbox-text">اعمال فوری تغییرات در سرور تلگرام به‌محض ذخیره</span>
                    </label>
                </div>
                <div class="card-footer flex justify-between items-center wrap gap-2">
                    <span class="text-xs text-muted">تغییرات هم در پایگاه داده ذخیره و هم روی ربات اعمال می‌شوند.</span>
                    <button class="btn" type="submit">💾 ذخیره و اعمال تغییرات</button>
                </div>
            </form>
        </div>

        <!-- جدول خلاصه وضعیت و اطلاعات فنی فعلی ربات -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">ℹ️ اطلاعات و وضعیت فعلی ربات</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                    <tr><td class="text-muted" style="width:42%">وضعیت اتصال</td>
                        <td><span class="badge badge-<?= $connected ? 'success' : 'warning' ?>"><?= $connected ? 'متصل' : 'قطع / تنظیم‌نشده' ?></span></td></tr>
                    <tr><td class="text-muted">نام فعلی ربات</td><td><?= $botName !== '' ? e($botName) : '<span class="text-muted">—</span>' ?></td></tr>
                    <tr><td class="text-muted">نام کاربری</td>
                        <td class="ltr mono"><?= $botUsername !== '' ? '<a target="_blank" rel="noopener" href="https://t.me/' . e($botUsername) . '">@' . e($botUsername) . '</a>' : '—' ?></td></tr>
                    <tr><td class="text-muted">شناسه یکتای ربات (Bot ID)</td>
                        <td class="ltr mono"><?= $botId > 0 ? e((string) $botId) : '—' ?></td></tr>
                    <tr><td class="text-muted">توکن ثبت‌شده</td>
                        <td class="ltr mono text-xs"><?= $maskedToken !== '' ? e($maskedToken) : '—' ?></td></tr>
                    <tr><td class="text-muted">آدرس Webhook</td>
                        <td class="ltr mono text-xs" style="word-break:break-all"><?= !empty($settings['webhook_url']) ? e((string) $settings['webhook_url']) : '—' ?></td></tr>
                    <tr><td class="text-muted">آخرین دریافت آپدیت</td>
                        <td><?= !empty($settings['last_update_at']) ? fa_date((string) $settings['last_update_at']) : '—' ?></td></tr>
                    <?php if (!empty($settings['last_error'])): ?>
                        <tr><td class="text-muted">آخرین خطای ثبت‌شده</td>
                            <td class="text-danger text-xs"><?= e((string) $settings['last_error']) ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const nameInput = document.getElementById('input-bot-name');
    const bioInput  = document.getElementById('input-bot-bio');
    const descInput = document.getElementById('input-bot-desc');
    const photoInput = document.getElementById('bot-photo-input');

    const prevName = document.getElementById('preview-bot-name');
    const prevBio  = document.getElementById('preview-bot-bio');
    const prevDesc = document.getElementById('preview-bot-desc');

    function bindCounter(input, counterId, max, onUpdate) {
        const counter = document.getElementById(counterId);
        if (!input) return;
        const sync = function () {
            const len = Array.from(input.value || '').length;
            if (counter) {
                counter.textContent = len + ' / ' + max;
                counter.style.color = len > max * 0.9 ? 'var(--warning,#f59e0b)' : '';
            }
            if (onUpdate) onUpdate(input.value);
        };
        input.addEventListener('input', sync);
        sync();
    }

    bindCounter(nameInput, 'count-bot-name', 64, function (val) {
        if (!prevName) return;
        const clean = val.trim();
        prevName.textContent = clean || <?= json_encode(setting('app_name', 'رفرال‌بات'), JSON_UNESCAPED_UNICODE) ?>;
        const fb1 = document.getElementById('bot-avatar-fallback');
        const fb2 = document.getElementById('preview-avatar-fallback');
        const ch = Array.from(prevName.textContent)[0] || 'R';
        if (fb1) fb1.textContent = ch;
        if (fb2) fb2.textContent = ch;
    });

    bindCounter(bioInput, 'count-bot-bio', 120, function (val) {
        if (!prevBio) return;
        const clean = val.trim();
        if (clean) {
            prevBio.textContent = clean;
        } else {
            prevBio.innerHTML = '<span class="text-muted">بیوگرافی کوتاه ربات اینجا نمایش داده می‌شود…</span>';
        }
    });

    bindCounter(descInput, 'count-bot-desc', 512, function (val) {
        if (!prevDesc) return;
        const clean = val.trim();
        if (clean) {
            prevDesc.textContent = clean;
        } else {
            prevDesc.innerHTML = '<span class="text-muted">توضیحات معرفی ربات پیش از زدن دکمه Start…</span>';
        }
    });

    if (photoInput) {
        photoInput.addEventListener('change', function () {
            const file = photoInput.files && photoInput.files[0];
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) {
                if (window.RB && RB.toast) RB.toast('حجم تصویر نباید بیشتر از ۵ مگابایت باشد.', 'error');
                photoInput.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = function (ev) {
                const src = String(ev.target && ev.target.result || '');
                if (!src) return;
                ['bot-avatar-img', 'preview-avatar-img'].forEach(function (id) {
                    const img = document.getElementById(id);
                    if (img) { img.src = src; img.style.display = ''; }
                });
                ['bot-avatar-fallback', 'preview-avatar-fallback'].forEach(function (id) {
                    const el = document.getElementById(id);
                    if (el) el.style.display = 'none';
                });
            };
            reader.readAsDataURL(file);
        });
    }
})();
</script>
