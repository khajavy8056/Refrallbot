<?php
use RB\Messengers\MessengerCapabilities;
$platIcons = ['telegram' => '✈️', 'bale' => '💬', 'rubika' => '🟣', 'eitaa' => '🟠'];
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">📢 کانال‌های اجباری (چندپیام‌رسانی)</h1>
        <p class="text-muted text-sm mb-0">تعریف کانال برای هر پیام‌رسان — بررسی خودکار API در تلگرام و بله، و لینک عضویت + تأیید کاربر در روبیکا و ایتا</p>
    </div>
    <div class="flex gap-1 wrap">
        <a href="<?= url('admin/channels?check=1') ?>" class="btn btn-sm">🩺 بررسی سلامت کانال‌ها</a>
        <a href="<?= url('admin/settings#gates') ?>" class="btn btn-secondary btn-sm">⚙️ تنظیمات شرایط ورود</a>
    </div>
</div>

<div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(195px,1fr))">
    <div class="stat <?= $enforcement ? 'success' : 'warning' ?>">
        <div class="stat-head"><div class="stat-icon">🔒</div><div class="stat-label">الزام عضویت کانال</div></div>
        <div class="stat-value" style="font-size:1.05rem"><?= $enforcement ? 'فعال' : 'غیرفعال' ?></div>
        <div class="stat-foot">قابل تغییر در <a href="<?= url('admin/settings#gates') ?>">تنظیمات</a></div>
    </div>
    <div class="stat <?= $contactRequired ? 'success' : 'muted' ?>">
        <div class="stat-head"><div class="stat-icon">📱</div><div class="stat-label">دریافت شماره تماس</div></div>
        <div class="stat-value" style="font-size:1.05rem"><?= $contactRequired ? 'الزامی' : 'غیرالزامی' ?></div>
    </div>
    <div class="stat info">
        <div class="stat-head"><div class="stat-icon">📢</div><div class="stat-label">کانال‌های تعریف‌شده</div></div>
        <div class="stat-value"><?= fa_digits(count($channels)) ?></div>
        <div class="stat-foot"><?= fa_digits(count(array_filter($channels, static fn($c) => !empty($c['is_active'])))) ?> کانال فعال</div>
    </div>
</div>

<?php if (!empty($health)): ?>
    <div class="card mb-3">
        <div class="card-header">
            <h2 class="card-title">🩺 نتیجه بررسی سلامت کانال‌ها</h2>
            <span class="card-subtitle">در تلگرام و بله ربات باید مدیر کانال باشد تا بتواند عضویت کاربران را با API بررسی کند</span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>پیام‌رسان</th><th>کانال</th><th>شناسه</th><th>وضعیت</th><th>توضیح</th></tr></thead>
                <tbody>
                <?php foreach ($health as $row): ?>
                    <?php $rp = (string) ($row['platform'] ?? 'telegram'); ?>
                    <tr>
                        <td data-label="پیام‌رسان"><?= e(($platIcons[$rp] ?? '🤖') . ' ' . MessengerCapabilities::label($rp)) ?></td>
                        <td data-label="کانال"><?= e((string) ($row['title'] ?? '—')) ?></td>
                        <td class="ltr mono" data-label="شناسه"><?= e((string) ($row['chat_id'] ?? '—')) ?></td>
                        <td data-label="وضعیت">
                            <span class="badge badge-<?= !empty($row['ok']) ? 'success' : 'danger' ?>">
                                <?= !empty($row['ok']) ? e((string) ($row['status'] ?? 'سالم')) : 'خطا' ?>
                            </span>
                        </td>
                        <td class="text-xs" data-label="توضیح"><?= e((string) ($row['message'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2 class="card-title">➕ افزودن کانال اجباری</h2></div>
        <form method="post" action="<?= url('admin/channels') ?>">
            <?= csrf_field() ?>
            <div class="card-body">
                <input type="hidden" name="id" value="0">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">پیام‌رسان هدف</label>
                        <select name="platform" id="ch-platform" class="form-select">
                            <option value="telegram">✈️ تلگرام (بررسی خودکار API)</option>
                            <option value="bale">💬 بله (بررسی خودکار API)</option>
                            <option value="rubika">🟣 روبیکا (لینک عضویت + تأیید کاربر)</option>
                            <option value="eitaa">🟠 ایتا (لینک عضویت + تأیید کاربر)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">روش بررسی عضویت</label>
                        <select name="check_mode" id="ch-mode" class="form-select">
                            <option value="api">بررسی خودکار با API ربات</option>
                            <option value="self_confirm">نمایش لینک + دکمه «عضو شدم»</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">عنوان نمایشی کانال</label>
                    <input type="text" name="title" class="form-control" maxlength="150" required placeholder="مثال: کانال رسمی اطلاع‌رسانی">
                </div>
                <div class="form-group">
                    <label class="form-label">شناسه کانال (chat_id یا @username)</label>
                    <input type="text" name="chat_id" class="form-control ltr" dir="ltr" required placeholder="@mychannel یا -1001234567890">
                    <div class="form-hint" id="ch-hint">در تلگرام و بله ربات را به‌عنوان مدیر کانال اضافه کنید.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">لینک عضویت کانال</label>
                    <input type="text" name="invite_link" class="form-control ltr" dir="ltr" placeholder="https://t.me/mychannel یا https://ble.ir/mychannel">
                    <div class="form-hint">اگر خالی بگذارید، از روی نام کاربری و پیام‌رسان به‌صورت خودکار ساخته می‌شود.</div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">ترتیب نمایش</label>
                        <input type="number" name="sort_order" class="form-control ltr" value="0">
                    </div>
                    <div class="form-group">
                        <label class="checkbox" style="margin-top:26px">
                            <input type="checkbox" name="is_active" value="1" checked>
                            <span class="checkbox-text">فعال</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="card-footer"><button class="btn" type="submit">➕ افزودن کانال</button></div>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">📋 فهرست کانال‌ها</h2></div>
        <?php if (empty($channels)): ?>
            <div class="empty">
                <div class="empty-icon">📢</div>
                <div class="empty-title">کانالی تعریف نشده است</div>
                <div class="empty-text">از فرم کنار، کانال اجباری هر پیام‌رسان را اضافه کنید.</div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>پیام‌رسان</th><th>عنوان</th><th>روش</th><th>وضعیت</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($channels as $channel): ?>
                        <?php
                        $cp = (string) ($channel['platform'] ?? 'telegram');
                        $cm = (string) ($channel['check_mode'] ?? (MessengerCapabilities::supports($cp, 'channel_check_api') ? 'api' : 'self_confirm'));
                        ?>
                        <tr>
                            <td data-label="پیام‌رسان"><span class="badge badge-brand no-dot"><?= e(($platIcons[$cp] ?? '🤖') . ' ' . MessengerCapabilities::label($cp)) ?></span></td>
                            <td data-label="عنوان">
                                <strong><?= e((string) $channel['title']) ?></strong>
                                <div class="text-xs text-muted ltr mono"><?= e((string) $channel['chat_id']) ?></div>
                                <?php if (!empty($channel['invite_link'])): ?>
                                    <div class="user-cell-meta"><a href="<?= e((string) $channel['invite_link']) ?>" target="_blank" rel="noopener" class="ltr text-xs"><?= e((string) $channel['invite_link']) ?></a></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="روش" class="text-xs"><?= $cm === 'api' ? '🤖 خودکار API' : '☑️ تأیید کاربر' ?></td>
                            <td data-label="وضعیت"><span class="badge badge-<?= !empty($channel['is_active']) ? 'success' : 'muted' ?>"><?= !empty($channel['is_active']) ? 'فعال' : 'غیرفعال' ?></span></td>
                            <td data-label="">
                                <div class="actions">
                                    <form method="post" action="<?= url('admin/channels') ?>" style="display:inline-flex">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $channel['id'] ?>">
                                        <input type="hidden" name="platform" value="<?= e($cp) ?>">
                                        <input type="hidden" name="check_mode" value="<?= e($cm) ?>">
                                        <input type="hidden" name="title" value="<?= e((string) $channel['title']) ?>">
                                        <input type="hidden" name="chat_id" value="<?= e((string) $channel['chat_id']) ?>">
                                        <input type="hidden" name="invite_link" value="<?= e((string) ($channel['invite_link'] ?? '')) ?>">
                                        <input type="hidden" name="sort_order" value="<?= (int) $channel['sort_order'] ?>">
                                        <input type="hidden" name="is_active" value="<?= !empty($channel['is_active']) ? '0' : '1' ?>">
                                        <button class="btn btn-sm btn-secondary" type="submit"><?= !empty($channel['is_active']) ? 'غیرفعال' : 'فعال' ?></button>
                                    </form>
                                    <form method="post" action="<?= url('admin/channels/' . $channel['id'] . '/delete') ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-danger" type="submit" data-confirm="کانال حذف شود؟">حذف</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    const pSel = document.getElementById('ch-platform');
    const mSel = document.getElementById('ch-mode');
    const hint = document.getElementById('ch-hint');
    if (!pSel || !mSel) return;
    function sync() {
        const p = pSel.value;
        const apiOk = (p === 'telegram' || p === 'bale');
        mSel.options[0].disabled = !apiOk;
        if (!apiOk) mSel.value = 'self_confirm';
        if (hint) {
            hint.textContent = apiOk
                ? 'در تلگرام و بله ربات را مدیر کانال کنید تا عضویت به‌صورت خودکار با getChatMember بررسی شود.'
                : 'API رسمی ' + (p === 'rubika' ? 'روبیکا' : 'ایتا') + ' بررسی خودکار عضویت کانال ندارد؛ لینک عضویت همراه دکمه «عضو شدم» نمایش داده می‌شود.';
        }
    }
    pSel.addEventListener('change', sync);
    sync();
})();
</script>
