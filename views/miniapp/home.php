<?php $botUsernameInView = ''; ?>
<div class="miniapp-shell">
    <div class="mini-topbar">
        <div class="mini-user">
            <div class="avatar" id="mini-avatar">؟</div>
            <div>
                <div class="mini-name" id="mini-name">در حال اتصال…</div>
                <div class="mini-sub" id="mini-sub">احراز هویت تلگرام</div>
            </div>
        </div>
        <button class="btn btn-ghost btn-sm" type="button" id="refresh-all" title="به‌روزرسانی">↻</button>
    </div>

    <div id="auth-gate" class="mini-gate hidden">
        <div class="empty-icon">🔒</div>
        <div class="empty-title">دسترسی از داخل تلگرام لازم است</div>
        <div class="empty-text">این پنل با احراز هویت تلگرام کار می‌کند. در صورت اجرای توکارِ ربات (Mini App) به‌صورت خودکار وارد می‌شوید.</div>
        <div id="auth-error" class="form-error"></div>
        <a class="btn btn-block mt-2" id="open-bot" href="#" style="display:none">باز کردن ربات در تلگرام</a>
    </div>

    <div id="mini-content" class="hidden">
        <div class="mini-cards">
            <div class="mini-card brand">
                <div class="mini-card-label">موجودی قابل برداشت</div>
                <div class="mini-card-value" id="stat-balance">—</div>
                <div class="mini-card-foot" id="stat-pending">—</div>
            </div>
            <div class="mini-card">
                <div class="mini-card-label">درآمد کل</div>
                <div class="mini-card-value" id="stat-earned">—</div>
                <div class="mini-card-foot" id="stat-rank">—</div>
            </div>
        </div>

        <div class="mini-stats">
            <div class="mini-stat"><span id="stat-direct">—</span><small>معرفی مستقیم</small></div>
            <div class="mini-stat"><span id="stat-network">—</span><small>کل شبکه</small></div>
            <div class="mini-stat"><span id="stat-withdrawn">—</span><small>برداشت‌شده</small></div>
        </div>

        <div class="mini-invite" id="invite-box">
            <div>
                <div class="mini-card-label">لینک دعوت اختصاصی شما</div>
                <div class="mini-link ltr" id="invite-link">—</div>
            </div>
            <div class="flex gap-1">
                <button class="btn btn-sm" type="button" id="copy-invite">کپی</button>
                <button class="btn btn-sm btn-secondary" type="button" id="share-invite">اشتراک</button>
            </div>
        </div>

        <div class="mini-tabs">
            <button class="mini-tab active" data-tab="home">خانه</button>
            <button class="mini-tab" data-tab="wallet">کیف پول</button>
            <button class="mini-tab" data-tab="referrals">زیرمجموعه</button>
            <button class="mini-tab" data-tab="withdraw">برداشت</button>
            <button class="mini-tab" data-tab="more">بیشتر</button>
        </div>

        <section class="mini-panel" data-panel="home">
            <div class="card">
                <div class="card-header"><h2 class="card-title">آخرین تراکنش‌ها</h2>
                    <button class="btn btn-ghost btn-sm" type="button" data-goto="wallet">همه ←</button></div>
                <div id="home-tx" class="mini-list"></div>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">کمپین‌های فعال</h2></div>
                <div id="home-campaigns" class="mini-list"></div>
            </div>
        </section>

        <section class="mini-panel hidden" data-panel="wallet">
            <div class="card">
                <div class="card-header"><h2 class="card-title">کیف پول و تراکنش‌ها</h2>
                    <select class="form-select" id="tx-filter" style="max-width:150px">
                        <option value="">همه تراکنش‌ها</option>
                        <option value="commission_direct">پاداش مستقیم</option>
                        <option value="commission_level">پورسانت لایه‌ای</option>
                        <option value="campaign_reward">جایزه کمپین</option>
                        <option value="withdrawal_reserve">برداشت</option>
                        <option value="withdrawal_release">بازگشت برداشت</option>
                        <option value="adjustment">تعدیل مدیر</option>
                    </select></div>
                <div id="wallet-tx" class="mini-list"></div>
                <div class="card-footer"><button class="btn btn-secondary btn-block btn-sm" id="load-more-tx">نمایش بیشتر</button></div>
            </div>
        </section>

        <section class="mini-panel hidden" data-panel="referrals">
            <div class="card">
                <div class="card-header"><h2 class="card-title">زیرمجموعه‌های مستقیم</h2>
                    <span class="card-subtitle" id="refs-summary">—</span></div>
                <div id="refs-list" class="mini-list"></div>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">توزیع شبکه در لایه‌ها</h2></div>
                <div class="card-body"><div id="levels-bars"></div></div>
            </div>
        </section>

        <section class="mini-panel hidden" data-panel="withdraw">
            <div class="card">
                <div class="card-header"><h2 class="card-title">درخواست برداشت</h2></div>
                <form id="withdraw-form" class="card-body">
                    <div class="form-group">
                        <label class="form-label">مبلغ (تومان)</label>
                        <input type="text" name="amount" class="form-control ltr" inputmode="numeric" id="withdraw-amount" required>
                        <div class="form-hint" id="withdraw-min">—</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">شماره کارت (۱۶ رقم)</label>
                        <input type="text" name="card_number" class="form-control ltr" dir="ltr" inputmode="numeric" maxlength="19" required placeholder="6037-9911-XXXX-XXXX">
                    </div>
                    <div class="form-group">
                        <label class="form-label">نام و نام خانوادگی صاحب کارت</label>
                        <input type="text" name="card_holder" class="form-control" required>
                    </div>
                    <button class="btn btn-block" type="submit" id="withdraw-submit">ثبت درخواست برداشت</button>
                    <div class="form-hint">مبلغ هنگام ثبت درخواست از موجودی شما رزرو می‌شود و تا زمان تعیین وضعیت قابل برداشت نیست.</div>
                </form>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">درخواست‌های من</h2></div>
                <div id="withdraw-list" class="mini-list"></div>
            </div>
        </section>

        <section class="mini-panel hidden" data-panel="more">
            <div class="card">
                <div class="card-header"><h2 class="card-title">رتبه‌بندی</h2></div>
                <div id="ranking-list" class="mini-list"></div>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">اعلان‌ها</h2>
                    <button class="btn btn-ghost btn-sm" type="button" id="read-all">خواندن همه</button></div>
                <div id="notifications-list" class="mini-list"></div>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">پروفایل</h2></div>
                <form id="profile-form" class="card-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">نام</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">نام خانوادگی</label>
                            <input type="text" name="last_name" class="form-control">
                        </div>
                    </div>
                    <button class="btn btn-secondary btn-block" type="submit">ذخیره پروفایل</button>
                </form>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">پشتیبانی</h2></div>
                <form id="support-form" class="card-body">
                    <div class="form-group">
                        <label class="form-label">موضوع (اختیاری)</label>
                        <input type="text" name="subject" class="form-control" maxlength="120">
                    </div>
                    <div class="form-group">
                        <label class="form-label">متن پیام</label>
                        <textarea name="message" class="form-control" rows="3" required maxlength="3000"></textarea>
                    </div>
                    <button class="btn btn-block" type="submit">ارسال پیام</button>
                </form>
            </div>
        </section>
    </div>
</div>

<script>
window.RB_MINIAPP = { bootstrapUrl: '<?= url('app/api/bootstrap') ?>', csrf: '<?= e(csrf_token()) ?>' };
</script>
<script src="<?= asset('js/miniapp.js') ?>"></script>
