<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">منوهای ربات</h1>
        <p class="text-muted text-sm mb-0">متن دکمه‌های کیبورد ربات و ترتیب نمایش — پس از ذخیره، از صفحه اتصال «همگام‌سازی» را بزنید</p>
    </div>
    <a href="<?= url('admin/telegram') ?>" class="btn btn-secondary btn-sm">بازگشت</a>
</div>

<div class="card">
    <form method="post" action="<?= url('admin/telegram/menus/save') ?>" data-ajax>
        <?= csrf_field() ?>
        <?php if (empty($menus)): ?>
            <div class="empty">
                <div class="empty-icon">🧩</div>
                <div class="empty-title">منویی ثبت نشده</div>
                <div class="empty-text">با ثبت توکن ربات، منوهای پیش‌فرض به‌صورت خودکار ساخته می‌شوند.</div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>اقدام (action)</th><th>نوع</th><th>متن دکمه</th><th>فعال</th><th>ترتیب</th></tr></thead>
                    <tbody>
                    <?php foreach ($menus as $menu): ?>
                        <tr>
                            <td class="ltr mono" data-label="اقدام"><?= e((string) $menu['action']) ?></td>
                            <td data-label="نوع"><?= $menu['type'] === 'inline' ? 'دکمه شیشه‌ای' : 'کیبورد' ?></td>
                            <td data-label="متن">
                                <input type="text" class="form-control" maxlength="78"
                                       name="menus[<?= (int) $menu['id'] ?>][title]" value="<?= e((string) $menu['title']) ?>">
                            </td>
                            <td data-label="فعال">
                                <label class="switch">
                                    <input type="checkbox" name="menus[<?= (int) $menu['id'] ?>][is_active]" value="1" <?= !empty($menu['is_active']) ? 'checked' : '' ?>>
                                    <span class="switch-track"></span>
                                </label>
                            </td>
                            <td data-label="ترتیب">
                                <input type="number" class="form-control ltr" style="max-width:90px"
                                       name="menus[<?= (int) $menu['id'] ?>][sort_order]" value="<?= (int) $menu['sort_order'] ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer"><button class="btn" type="submit">💾 ذخیره منوها</button></div>
        <?php endif; ?>
    </form>
</div>
