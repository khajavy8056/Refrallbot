<?php
$s = static fn(string $key, string $default = '') => (string) ($settings[$key] ?? $default);
$flag = static fn(string $key, bool $default = false) => (int) ($settings[$key] ?? ($default ? 1 : 0)) === 1;
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">تنظیمات سیستم</h1>
        <p class="text-muted text-sm mb-0">همه مقادیر مالی و رفتاری از این صفحه قابل تغییر است؛ هیچ عددی در کد ثابت نیست</p>
    </div>
    <div class="flex gap-1 wrap">
        <a href="#general" class="btn btn-secondary btn-sm">عمومی</a>
        <a href="#withdrawal" class="btn btn-secondary btn-sm">برداشت</a>
        <a href="#messages" class="btn btn-secondary btn-sm">پیام‌ها</a>
        <a href="#gates" class="btn btn-secondary btn-sm">شرایط ورود</a>
        <a href="#ranking" class="btn btn-secondary btn-sm">رتبه‌بندی</a>
    </div>
</div>

<div class="grid-2 mb-3">
    <div class="card" id="general">
        <div class="card-header"><h2 class="card-title">تنظیمات عمومی</h2></div>
        <form method="post" action="<?= url('admin/settings/general') ?>" data-ajax>
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">نام سامانه / برند</label>
                    <input type="text" name="app_name" class="form-control" maxlength="60" required value="<?= e($s('app_name', 'رفرال‌بات')) ?>">
                    <div class="form-hint">در پنل مدیریت، پنل کاربری و پیام‌های ربات نمایش داده می‌شود.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">واحد پول نمایشی</label>
                    <input type="text" class="form-control ltr" value="IRT — تومان" disabled>
                    <div class="form-hint">تمام مبالغ در دیتابیس به‌صورت عدد صحیح تومان ذخیره می‌شوند (نه اعشاری).</div>
                </div>
            </div>
            <div class="card-footer"><button class="btn" type="submit">💾 ذخیره</button></div>
        </form>
    </div>

    <div class="card" id="withdrawal">
        <div class="card-header"><h2 class="card-title">تنظیمات برداشت</h2></div>
        <form method="post" action="<?= url('admin/settings/withdrawal') ?>" data-ajax>
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="form-group">
                    <label class="checkbox">
                        <input type="checkbox" name="withdrawal_enabled" value="1" <?= $flag('withdrawal_enabled', true) ? 'checked' : '' ?>>
                        <span class="checkbox-text">امکان ثبت درخواست برداشت فعال باشد</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label">حداقل مبلغ برداشت (تومان)</label>
                    <input type="text" name="min_withdraw_amount" class="form-control ltr" data-money inputmode="numeric" required
                           value="<?= e(number_format((int) ($settings['min_withdraw_amount'] ?? 30000))) ?>">
                    <div class="form-hint">حداقل ۱٬۰۰۰ تومان. کاربران با موجودی کمتر نمی‌توانند درخواست ثبت کنند.</div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">حداکثر درخواست باز هر کاربر</label>
                        <input type="number" name="max_open_withdrawals" class="form-control ltr" min="0" max="10"
                               value="<?= e((string) (int) ($settings['max_open_withdrawals'] ?? 1)) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">سقف برداشت روزانه (تومان — ۰ = بدون سقف)</label>
                        <input type="text" name="max_daily_withdrawal" class="form-control ltr" data-money inputmode="numeric"
                               value="<?= e(number_format((int) ($settings['max_daily_withdrawal'] ?? 0))) ?>">
                    </div>
                </div>
            </div>
            <div class="card-footer"><button class="btn" type="submit">💾 ذخیره</button></div>
        </form>
    </div>
</div>

<div class="card mb-3" id="messages">
    <div class="card-header">
        <h2 class="card-title">پیام‌های ربات</h2>
        <span class="card-subtitle">از متغیرهای {name} و {link} می‌توانید استفاده کنید</span>
    </div>
    <form method="post" action="<?= url('admin/settings/start-message') ?>" data-ajax>
        <?= csrf_field() ?>
        <div class="card-body grid-2">
            <div class="form-group">
                <label class="form-label">متن خوش‌آمد کاربر جدید (/start)</label>
                <textarea name="start_message_new" class="form-control" rows="5" maxlength="4000"><?= e($s('start_message_new')) ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">متن خوش‌آمد کاربر بازگشتی</label>
                <textarea name="start_message_returning" class="form-control" rows="5" maxlength="4000"><?= e($s('start_message_returning')) ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">متن قوانین</label>
                <textarea name="start_rules_text" class="form-control" rows="4" maxlength="4000"><?= e($s('start_rules_text')) ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">متن راهنما</label>
                <textarea name="guide_text" class="form-control" rows="4" maxlength="4000"><?= e($s('guide_text')) ?></textarea>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label class="checkbox">
                    <input type="checkbox" name="show_rules_in_start" value="1" <?= $flag('show_rules_in_start', true) ? 'checked' : '' ?>>
                    <span class="checkbox-text">نمایش قوانین در پیام /start</span>
                </label>
            </div>
        </div>
        <div class="card-footer"><button class="btn" type="submit">💾 ذخیره پیام‌ها</button></div>
    </form>
</div>

<div class="grid-2 mb-3">
    <div class="card" id="gates">
        <div class="card-header"><h2 class="card-title">شرایط ورود و عضویت</h2></div>
        <form method="post" action="<?= url('admin/settings/gates') ?>" data-ajax>
            <?= csrf_field() ?>
            <div class="card-body">
                <label class="checkbox" style="margin-bottom:10px">
                    <input type="checkbox" name="force_channel_membership" value="1" <?= $flag('force_channel_membership') ? 'checked' : '' ?>>
                    <span class="checkbox-text">عضویت در کانال‌های اجباری الزامی باشد</span>
                </label>
                <div class="form-group">
                    <label class="form-label">پیام درخواست عضویت در کانال</label>
                    <textarea name="join_channels_message" class="form-control" rows="3" maxlength="600"><?= e($s('join_channels_message')) ?></textarea>
                    <div class="form-hint">کانال‌ها را از صفحه <a href="<?= url('admin/channels') ?>">کانال‌های اجباری</a> مدیریت کنید.</div>
                </div>
                <div class="divider"></div>
                <label class="checkbox" style="margin-bottom:10px">
                    <input type="checkbox" name="contact_required" value="1" <?= $flag('contact_required') ? 'checked' : '' ?>>
                    <span class="checkbox-text">دریافت شماره تماس (Request Contact) الزامی باشد</span>
                </label>
                <div class="form-group">
                    <label class="form-label">پیام درخواست شماره تماس</label>
                    <textarea name="contact_request_message" class="form-control" rows="3" maxlength="600"><?= e($s('contact_request_message')) ?></textarea>
                </div>
            </div>
            <div class="card-footer"><button class="btn" type="submit">💾 ذخیره</button></div>
        </form>
    </div>

    <div class="card" id="ranking">
        <div class="card-header"><h2 class="card-title">رتبه‌بندی کاربران</h2></div>
        <form method="post" action="<?= url('admin/settings/ranking') ?>" data-ajax>
            <?= csrf_field() ?>
            <div class="card-body">
                <label class="checkbox" style="margin-bottom:12px">
                    <input type="checkbox" name="ranking_enabled" value="1" <?= $flag('ranking_enabled', true) ? 'checked' : '' ?>>
                    <span class="checkbox-text">جدول رتبه‌بندی در پنل کاربری نمایش داده شود</span>
                </label>
                <div class="form-group">
                    <label class="form-label">معیار رتبه‌بندی</label>
                    <select name="ranking_metric" class="form-select">
                        <?php foreach ($metrics as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $s('ranking_metric', 'direct_ref_count') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alert alert-info mb-0">
                    <span class="alert-icon">ℹ️</span>
                    <div>رتبه‌بندی به‌صورت زنده از دیتابیس محاسبه می‌شود و داده‌ی ذخیره‌شده جداگانه ندارد.</div>
                </div>
            </div>
            <div class="card-footer"><button class="btn" type="submit">💾 ذخیره</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title">خلاصه تنظیمات فعال</h2></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>کلید</th><th>مقدار</th><th>گروه</th></tr></thead>
            <tbody>
            <?php foreach ($settings as $key => $value): ?>
                <tr>
                    <td class="ltr mono" data-label="کلید"><?= e((string) $key) ?></td>
                    <td data-label="مقدار"><?= e(mb_strimwidth((string) $value, 0, 70, '…')) ?></td>
                    <td data-label="گروه"><?= e((string) (\RB\Services\SettingsService::groupOf((string) $key))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
