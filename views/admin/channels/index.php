<?php
$botUsername = (string) (\RB\Services\TelegramService::settings()['bot_username'] ?? '');
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">کانال‌های اجباری</h1>
        <p class="text-muted text-sm mb-0">کاربر برای استفاده از ربات باید عضو این کانال‌ها باشد (در صورت فعال بودن الزام)</p>
    </div>
    <div class="flex gap-1">
        <a href="<?= url('admin/channels') ?>" class="btn btn-secondary btn-sm">🔄 بازبینی عضویت ربات</a>
        <a href="<?= url('admin/channels?check=1') ?>" class="btn btn-sm">🩺 بررسی سلامت کانال‌ها</a>
    </div>
</div>

<div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(190px,1fr))">
    <div class="stat <?= $enforcement ? 'success' : 'warning' ?>">
        <div class="stat-head"><div class="stat-icon">🔒</div><div class="stat-label">الزام عضویت</div></div>
        <div class="stat-value" style="font-size:1.05rem"><?= $enforcement ? 'فعال' : 'غیرفعال' ?></div>
        <div class="stat-foot">قابل تغییر در <a href="<?= url('admin/settings#gates') ?>">تنظیمات</a></div></div>
    <div class="stat <?= $contactRequired ? 'success' : 'muted' ?>">
        <div class="stat-head"><div class="stat-icon">📱</div><div class="stat-label">دریافت شماره تماس</div></div>
        <div class="stat-value" style="font-size:1.05rem"><?= $contactRequired ? 'الزامی' : 'غیرالزامی' ?></div></div>
    <div class="stat info"><div class="stat-head"><div class="stat-icon">📢</div><div class="stat-label">کانال‌های تعریف‌شده</div></div>
        <div class="stat-value"><?= fa_digits(count($channels)) ?></div>
        <div class="stat-foot"><?= fa_digits(count(array_filter($channels, static fn($c) => !empty($c['is_active'])))) ?> فعال</div></div>
    <div class="stat purple"><div class="stat-head"><div class="stat-icon">🤖</div><div class="stat-label">ربات</div></div>
        <div class="stat-value" style="font-size:1rem"><?= $botUsername ? '@' . e($botUsername) : 'متصل نیست' ?></div>
        <div class="stat-foot">مدیر کانال باید باشد</div></div>
</div>

<?php if ($enforcement && !$botUsername): ?>
    <div class="alert alert-warning">
        <span class="alert-icon">⚠️</span>
        <div>الزام عضویت فعال است اما ربات متصل نیست؛ بنابراین بررسی عضویت انجام نمی‌شود.
            <a href="<?= url('admin/telegram') ?>">توکن ربات را ثبت کنید</a>.</div>
    </div>
<?php endif; ?>

<?php if (!empty($health)): ?>
    <div class="card mb-3">
        <div class="card-header"><h2 class="card-title">نتیجه بررسی سلامت</h2>
            <span class="card-subtitle">اگر ربات در کانال مدیر نباشد، نتیجه «عضو نیست» به‌معنای واقعی نیست</span></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>کانال</th><th>شناسه</th><th>وضعیت ربات در کانال</th><th>پیام</th></tr></thead>
                <tbody>
                <?php foreach ($health as $row): ?>
                    <tr>
                        <td data-label="کانال"><?= e((string) ($row['title'] ?? '—')) ?></td>
                        <td class="ltr mono" data-label="شناسه"><?= e((string) ($row['chat_id'] ?? '—')) ?></td>
                        <td data-label="وضعیت">
                            <span class="badge badge-<?= !empty($row['ok']) ? 'success' : 'danger' ?>">
                                <?= !empty($row['ok']) ? e((string) ($row['status'] ?? 'سالم')) : 'خطا' ?>
                            </span>
                        </td>
                        <td class="text-xs" data-label="پیام"><?= e((string) ($row['message'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2 class="card-title">افزودن / ویرایش کانال</h2></div>
        <form method="post" action="<?= url('admin/channels') ?>">
            <?= csrf_field() ?>
            <div class="card-body">
                <input type="hidden" name="id" value="0">
                <div class="form-group">
                    <label class="form-label">عنوان نمایشی</label>
                    <input type="text" name="title" class="form-control" maxlength="150" required placeholder="مثال: کانال رسمی رفرال‌بات">
                </div>
                <div class="form-group">
                    <label class="form-label">شناسه کانال (chat_id)</label>
                    <input type="text" name="chat_id" class="form-control ltr" dir="ltr" required placeholder="@mychannel یا -1001234567890">
                    <div class="form-hint">کانال عمومی با @username، کانال خصوصی با شناسه عددی ۱۰۰.....</div>
                </div>
                <div class="form-group">
                    <label class="form-label">لینک دعوت</label>
                    <input type="text" name="invite_link" class="form-control ltr" dir="ltr" placeholder="https://t.me/mychannel">
                    <div class="form-hint">اگر خالی و کانال عمومی باشد، خودکار از @username ساخته می‌شود.</div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">ترتیب</label>
                        <input type="number" name="sort_order" class="form-control ltr" value="0">
                    </div>
                    <div class="form-group">
                        <label class="checkbox" style="margin-top:26px">
                            <input type="checkbox" name="is_active" value="1" checked>
                            <span class="checkbox-text">فعال</span>
                        </label>
                    </div>
                </div>
                <div class="alert alert-info mb-0">
                    <span class="alert-icon">ℹ️</span>
                    <div>ربات باید <strong>مدیر کانال</strong> باشد تا بتواند عضویت کاربران را بررسی کند. اگر ربات مدیر نباشد، خطای API با «عضو نیست» اشتباه نمی‌شود و در لاگ نمایش داده می‌شود.</div>
                </div>
            </div>
            <div class="card-footer"><button class="btn" type="submit">➕ افزودن کانال</button></div>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">کانال‌ها</h2></div>
        <?php if (empty($channels)): ?>
            <div class="empty"><div class="empty-icon">📢</div><div class="empty-title">کانالی تعریف نشده</div>
                <div class="empty-text">با فرم کنار، اولین کانال اجباری را اضافه کنید.</div></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>عنوان</th><th>شناسه</th><th>وضعیت</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($channels as $channel): ?>
                        <tr>
                            <td data-label="عنوان"><?= e((string) $channel['title']) ?>
                                <?php if (!empty($channel['invite_link'])): ?>
                                    <div class="user-cell-meta"><a href="<?= e((string) $channel['invite_link']) ?>" target="_blank" rel="noopener" class="ltr text-xs"><?= e((string) $channel['invite_link']) ?></a></div>
                                <?php endif; ?>
                            </td>
                            <td class="ltr mono" data-label="شناسه"><?= e((string) $channel['chat_id']) ?></td>
                            <td data-label="وضعیت"><span class="badge badge-<?= !empty($channel['is_active']) ? 'success' : 'muted' ?>"><?= !empty($channel['is_active']) ? 'فعال' : 'غیرفعال' ?></span></td>
                            <td data-label="">
                                <div class="actions">
                                    <form method="post" action="<?= url('admin/channels') ?>" style="display:inline-flex;gap:6px;align-items:center">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $channel['id'] ?>">
                                        <input type="hidden" name="title" value="<?= e((string) $channel['title']) ?>">
                                        <input type="hidden" name="chat_id" value="<?= e((string) $channel['chat_id']) ?>">
                                        <input type="hidden" name="invite_link" value="<?= e((string) ($channel['invite_link'] ?? '')) ?>">
                                        <input type="hidden" name="sort_order" value="<?= (int) $channel['sort_order'] ?>">
                                        <input type="hidden" name="is_active" value="<?= !empty($channel['is_active']) ? '0' : '1' ?>">
                                        <button class="btn btn-sm btn-secondary" type="submit"><?= !empty($channel['is_active']) ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?></button>
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
