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

<?php
$defaultInviteTpl = \RB\Services\ReferralMessageService::defaultInviteTemplate();
$defaultShareTpl = \RB\Services\ReferralMessageService::defaultShareTemplate();
$defaultRefWelcomeTpl = \RB\Services\ReferralMessageService::defaultReferredWelcomeTemplate();
$curInviterAmt = wtoman($commission->effectiveDirectReward());
$curInviteeAmt = wtoman($commission->effectiveInviteeReward());
?>
<div class="card mb-3" id="messages">
    <div class="card-header">
        <h2 class="card-title">✉️ پیام‌های ربات، دعوت دوستان و خوش‌آمدگویی دعوت‌شده</h2>
        <span class="card-subtitle">پشتیبانی کامل از متغیرهای پویا — مبالغ فعلی: دعوت‌کننده <b><?= $curInviterAmt ?></b> · دعوت‌شده <b><?= $curInviteeAmt ?></b></span>
    </div>
    <form method="post" action="<?= url('admin/settings/start-message') ?>" data-ajax id="bot-messages-form">
        <?= csrf_field() ?>
        <input type="hidden" name="referred_welcome_append_checklist_present" value="1">
        <input type="hidden" name="notify_referral_joined_present" value="1">
        <div class="card-body">
            <div class="alert alert-info mb-3">
                <span class="alert-icon">🏷</span>
                <div>
                    <div class="font-bold mb-1">متغیرهای پویا (روی هر متغیر کلیک کنید تا در کادر فعال درج شود):</div>
                    <div class="flex wrap gap-1" style="margin-top:4px">
                        <?php foreach ([
                            '{invite_link}' => 'لینک اختصاصی دعوت',
                            '{referral_code}' => 'کد دعوت',
                            '{inviter_reward}' => 'پاداش دعوت‌کننده (' . $curInviterAmt . ')',
                            '{invitee_reward}' => 'پاداش دعوت‌شده (' . $curInviteeAmt . ')',
                            '{total_reward}' => 'مجموع دو پاداش',
                            '{name}' => 'نام کاربر',
                            '{referrer_name}' => 'نام معرف',
                            '{requirements}' => 'چک‌لیست پویای شرایط فعال',
                            '{app_name}' => 'نام ربات/سامانه',
                            '{min_withdraw}' => 'حداقل برداشت',
                            '{max_levels}' => 'تعداد سطوح',
                        ] as $tok => $desc): ?>
                            <button type="button" class="btn btn-xs btn-outline" data-insert-var="<?= e($tok) ?>" title="<?= e($desc) ?>">
                                <span class="ltr mono"><?= e($tok) ?></span> · <?= e($desc) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-xs text-muted" style="margin-top:6px">
                        اگر هر یک از کادرهای «پیام دعوت» یا «خوش‌آمدگویی دعوت‌شده» را خالی بگذارید، قالب هوشمند پیش‌فرض متناسب با مبالغ و شرایط فعال سامانه نمایش داده می‌شود. معادل‌های فارسی مثل <code>[لینک دعوت]</code>، <code>[پاداش دعوت‌کننده]</code> و <code>[پاداش دعوت‌شده]</code> نیز پشتیبانی می‌شوند.
                    </div>
                </div>
            </div>

            <div class="grid-2 mb-3">
                <div class="form-group mb-0">
                    <div class="flex items-center justify-between wrap gap-1 mb-1">
                        <label class="form-label mb-0">🎁 متن پیام دعوت دوستان (لینک + پاداش دوطرفه)</label>
                        <button type="button" class="btn btn-xs btn-ghost" data-fill-target="invite_message_text" data-fill-value="<?= e($defaultInviteTpl) ?>">درج قالب پیشنهادی</button>
                    </div>
                    <textarea name="invite_message_text" class="form-control" rows="7" maxlength="4000"
                              placeholder="<?= e($defaultInviteTpl) ?>"><?= e($s('invite_message_text')) ?></textarea>
                    <div class="form-hint">این پیام در بخش «دعوت دوستان» ربات و مینی‌اپ با لینک اختصاصی کاربر و مبالغ به‌روز نمایش داده می‌شود.</div>
                </div>

                <div class="form-group mb-0">
                    <div class="flex items-center justify-between wrap gap-1 mb-1">
                        <label class="form-label mb-0">👋 پیام خوش‌آمدگویی و راهنمای تکمیل ثبت‌نام (کاربر دعوت‌شده)</label>
                        <button type="button" class="btn btn-xs btn-ghost" data-fill-target="start_message_referred" data-fill-value="<?= e($defaultRefWelcomeTpl) ?>">درج قالب پیشنهادی</button>
                    </div>
                    <textarea name="start_message_referred" class="form-control" rows="7" maxlength="4000"
                              placeholder="<?= e($defaultRefWelcomeTpl) ?>"><?= e($s('start_message_referred')) ?></textarea>
                    <div class="form-hint">هنگام اولین ورود کاربر جدید از طریق لینک دعوت نمایش داده می‌شود و مبلغ پاداش دعوت‌شده + مراحل لازم (پروفایل، شماره، کانال اجباری و...) را شفاف اعلام می‌کند.</div>
                </div>
            </div>

            <div class="grid-2 mb-3">
                <div class="form-group mb-0">
                    <div class="flex items-center justify-between wrap gap-1 mb-1">
                        <label class="form-label mb-0">📤 متن اشتراک‌گذاری سریع لینک دعوت (دکمه ارسال به دوستان)</label>
                        <button type="button" class="btn btn-xs btn-ghost" data-fill-target="invite_share_text" data-fill-value="<?= e($defaultShareTpl) ?>">درج قالب پیشنهادی</button>
                    </div>
                    <textarea name="invite_share_text" class="form-control" rows="3" maxlength="1500"
                              placeholder="<?= e($defaultShareTpl) ?>"><?= e($s('invite_share_text')) ?></textarea>
                    <div class="form-hint">متن همراه لینک هنگام فوروارد مستقیم به دوستان در تلگرام (خالی = تولید خودکار از روی پیام دعوت).</div>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label">🔔 پیام اطلاع‌رسانی دعوت جدید به معرف (اختیاری)</label>
                    <textarea name="referral_joined_message" class="form-control" rows="3" maxlength="2000"
                              placeholder="تبریک! کاربر «{name}» ({username}) با لینک دعوت شما وارد ربات شد. تعداد دعوت‌های شما: {direct_count} نفر"><?= e($s('referral_joined_message')) ?></textarea>
                    <div class="form-hint">متغیرها: <code>{name}</code>، <code>{username}</code>، <code>{direct_count}</code>، <code>{total_count}</code>، <code>{app_name}</code> (خالی = متن استاندارد).</div>
                </div>
            </div>

            <div class="divider"></div>

            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">متن خوش‌آمد کاربر عادی جدید (/start بدون لینک دعوت)</label>
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
            </div>

            <div class="flex wrap gap-3" style="margin-top:4px">
                <label class="checkbox">
                    <input type="checkbox" name="referred_welcome_append_checklist" value="1" <?= $flag('referred_welcome_append_checklist', true) ? 'checked' : '' ?>>
                    <span class="checkbox-text">درج خودکار چک‌لیست شرایط باقی‌مانده در انتهای پیام خوش‌آمد دعوت‌شده (اگر <code>{requirements}</code> در متن نباشد)</span>
                </label>
                <label class="checkbox">
                    <input type="checkbox" name="notify_referral_joined" value="1" <?= $flag('notify_referral_joined', true) ? 'checked' : '' ?>>
                    <span class="checkbox-text">ارسال اعلان فوری پیوستن زیرمجموعه جدید به دعوت‌کننده</span>
                </label>
                <label class="checkbox">
                    <input type="checkbox" name="show_rules_in_start" value="1" <?= $flag('show_rules_in_start', true) ? 'checked' : '' ?>>
                    <span class="checkbox-text">نمایش قوانین در انتهای پیام /start</span>
                </label>
            </div>
        </div>
        <div class="card-footer flex justify-between items-center wrap gap-2">
            <span class="text-xs text-muted">هر تغییری در پیام‌ها و مبالغ پاداش بلافاصله در ربات و مینی‌اپ اعمال می‌شود.</span>
            <button class="btn" type="submit">💾 ذخیره پیام‌ها</button>
        </div>
    </form>
</div>
<script>
(function () {
    const form = document.getElementById('bot-messages-form');
    if (!form) return;
    let activeArea = form.querySelector('textarea[name="invite_message_text"]');
    form.querySelectorAll('textarea').forEach(function (ta) {
        ta.addEventListener('focus', function () { activeArea = ta; });
    });
    form.querySelectorAll('[data-insert-var]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!activeArea) return;
            const tok = btn.getAttribute('data-insert-var') || '';
            const start = activeArea.selectionStart ?? activeArea.value.length;
            const end = activeArea.selectionEnd ?? activeArea.value.length;
            activeArea.value = activeArea.value.slice(0, start) + tok + activeArea.value.slice(end);
            activeArea.focus();
            activeArea.selectionStart = activeArea.selectionEnd = start + tok.length;
        });
    });
    form.querySelectorAll('[data-fill-target]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const name = btn.getAttribute('data-fill-target');
            const val = btn.getAttribute('data-fill-value') || '';
            const ta = form.querySelector('textarea[name="' + name + '"]');
            if (!ta) return;
            ta.value = val;
            ta.focus();
            activeArea = ta;
        });
    });
})();
</script>

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

                <div class="divider"></div>
                <div class="form-group" id="ui-mode">
                    <label class="form-label">🎛 حالت نمایش رابط کاربری ربات در پیام‌رسان‌ها</label>
                    <?php
                    $botUiModeNow = \RB\Services\SettingsService::configuredBotUiMode();
                    $miniappUrlNow = (string) ($s('miniapp_url') ?: '');
                    $platformUiModes = $platformUiModes ?? \RB\Services\SettingsService::activePlatformUiModes();
                    $modeNames = ['miniapp' => '📱 فقط مینی‌اپ', 'buttons' => '⌨️ فقط دکمه‌های ربات', 'hybrid' => '✨ ترکیبی (مینی‌اپ + دکمه‌ها)'];
                    ?>
                    <select name="bot_ui_mode" class="form-select" id="miniapp-mode">
                        <option value="miniapp" <?= $botUiModeNow === 'miniapp' ? 'selected' : '' ?>>📱 فقط مینی‌اپ (Mini App Only — نیازمند HTTPS)</option>
                        <option value="buttons" <?= $botUiModeNow === 'buttons' ? 'selected' : '' ?>>⌨️ فقط دکمه‌های ربات (Bot Buttons Only — بدون نیاز به مینی‌اپ)</option>
                        <option value="hybrid" <?= $botUiModeNow === 'hybrid' ? 'selected' : '' ?>>✨ ترکیبی — مینی‌اپ + دکمه‌های کامل ربات (پیشنهادی)</option>
                    </select>
                    <div class="form-hint">
                        سامانه این انتخاب را به‌صورت خودکار با قابلیت رسمی هر پیام‌رسان فعال و متصل تطبیق می‌دهد.
                    </div>
                </div>
                <div class="form-group" id="miniapp-url-group">
                    <label class="form-label">آدرس HTTPS مینی‌اپ (پنل وب کاربری)</label>
                    <input type="text" name="miniapp_url" class="form-control ltr" maxlength="255"
                           value="<?= e($miniappUrlNow) ?>" placeholder="https://example.com/app">
                    <div class="form-hint">
                        مینی‌اپ فقط روی <span class="ltr mono">https://</span> معتبر اجرا می‌شود و برای هر پیام‌رسان متصل پشتیبان Mini App به‌صورت خودکار تنظیم می‌گردد.
                        <?php if ($miniappUrlNow !== '' && !str_starts_with($miniappUrlNow, 'https://')): ?>
                            <span style="color:var(--danger,#dc2626)">آدرس فعلی HTTPS نیست؛ ربات به‌صورت خودکار به حالت دکمه‌ای بازمی‌گردد.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($platformUiModes)): ?>
                    <div style="border:1px solid var(--border);border-radius:10px;padding:10px 12px;background:var(--surface-2);font-size:.78rem">
                        <div class="font-bold mb-1">وضعیت مؤثر فعلی در پیام‌رسان‌های فعال و متصل:</div>
                        <?php foreach ($platformUiModes as $pmKey => $pmInfo): ?>
                            <?php
                            $eff = (string) ($pmInfo['effective'] ?? ($pmInfo['mode'] ?? 'buttons'));
                            $pBadge = \RB\Messengers\MessengerCapabilities::badge((string) $pmKey);
                            ?>
                            <div class="flex justify-between items-center" style="padding:4px 0;border-bottom:1px dashed var(--border)">
                                <span><strong><?= e($pBadge) ?></strong></span>
                                <span class="badge badge-brand no-dot"><?= e($modeNames[$eff] ?? $eff) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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
