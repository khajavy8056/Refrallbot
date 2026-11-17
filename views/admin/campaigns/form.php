<?php
$isEdit = $campaign !== null;
$value = static function (string $key, string $default = '') use ($campaign) {
    return (string) ($campaign[$key] ?? $default);
};
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0"><?= $isEdit ? 'ویرایش کمپین' : 'ایجاد کمپین جدید' ?></h1>
        <p class="text-muted text-sm mb-0">شرایط و مبلغ جایزه را دقیق تعیین کنید؛ پرداخت‌ها با تراکنش یکتا و در دفتر مالی ثبت می‌شوند</p>
    </div>
    <a href="<?= url('admin/campaigns') ?>" class="btn btn-secondary btn-sm">بازگشت</a>
</div>

<form method="post" action="<?= $isEdit ? url('admin/campaigns/' . $campaign['id']) : url('admin/campaigns') ?>">
    <?= csrf_field() ?>
    <div class="grid-2">
        <div class="card">
            <div class="card-header"><h2 class="card-title">اطلاعات پایه</h2></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">عنوان کمپین</label>
                    <input type="text" name="title" class="form-control" maxlength="150" required value="<?= e($value('title')) ?>" placeholder="مثال: جشنواره پاییزه دعوت دوستان">
                </div>
                <div class="form-group">
                    <label class="form-label">توضیحات (در پنل کاربری نمایش داده می‌شود)</label>
                    <textarea name="description" class="form-control" rows="4" maxlength="3000"><?= e($value('description')) ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">نوع کمپین</label>
                    <select name="type" class="form-select">
                        <?php foreach ($types as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $value('type', 'invite_count') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">«تعداد دعوت مشخص» به‌صورت خودکار پرداخت می‌شود؛ سایر انواع برای پرداخت دستی مدیر است.</div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">تاریخ شروع (شمسی)</label>
                        <input type="text" name="starts_at" class="form-control ltr" dir="ltr" value="<?= e($value('starts_at')) ?>" placeholder="1403/07/01">
                    </div>
                    <div class="form-group">
                        <label class="form-label">تاریخ پایان (شمسی)</label>
                        <input type="text" name="ends_at" class="form-control ltr" dir="ltr" value="<?= e($value('ends_at')) ?>" placeholder="1403/07/31">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">گروه مخاطب</label>
                    <select name="audience" class="form-select">
                        <option value="all" <?= $value('audience', 'all') === 'all' ? 'selected' : '' ?>>همه کاربران</option>
                        <option value="active" <?= $value('audience') === 'active' ? 'selected' : '' ?>>کاربران فعال</option>
                        <option value="with_refs" <?= $value('audience') === 'with_refs' ? 'selected' : '' ?>>کاربران دارای زیرمجموعه</option>
                    </select>
                </div>
                <label class="checkbox">
                    <input type="checkbox" name="is_active" value="1" <?= !$isEdit || !empty($campaign['is_active']) ? 'checked' : '' ?>>
                    <span class="checkbox-text">کمپین فعال باشد</span>
                </label>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">شرایط و جایزه</h2></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">تعداد دعوت مورد نیاز</label>
                    <input type="number" name="required_invites" class="form-control ltr" min="0" value="<?= e($value('required_invites', '0')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">جایزه نقدی ثابت (تومان)</label>
                    <input type="text" name="fixed_reward" class="form-control ltr" data-money inputmode="numeric" value="<?= e($value('fixed_reward', '0')) ?>">
                    <div class="form-hint">مبلغ به کیف پول کاربر (دفتر مالی) واریز می‌شود.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">درصد پاداش اضافه (۰ تا ۱۰۰)</label>
                    <input type="text" name="percent_bonus" class="form-control ltr" dir="ltr" value="<?= e($value('percent_bonus', '0')) ?>">
                    <div class="form-hint">برای کمپین‌های افزایش پورسانت استفاده می‌شود.</div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">حداکثر برنده (۰ = نامحدود)</label>
                        <input type="number" name="max_winners" class="form-control ltr" min="0" value="<?= e($value('max_winners', '0')) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">حداقل فعالیت (تومان)</label>
                        <input type="text" name="min_activity" class="form-control ltr" data-money inputmode="numeric" value="<?= e($value('min_activity', '0')) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">توضیح شرایط (اختیاری)</label>
                    <textarea name="conditions" class="form-control" rows="3" maxlength="1000"><?= e((string) (json_decode((string) ($campaign['conditions'] ?? '{}'), true)['manual_note'] ?? '')) ?></textarea>
                </div>
            </div>
            <div class="card-footer flex gap-2 wrap">
                <button class="btn" type="submit"><?= $isEdit ? '💾 ذخیره تغییرات' : '➕ ایجاد کمپین' ?></button>
                <a href="<?= url('admin/campaigns') ?>" class="btn btn-ghost">انصراف</a>
            </div>
        </div>
    </div>
</form>
