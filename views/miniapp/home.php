<?php $platformHint = $platformHint ?? ''; ?>
<div class="miniapp-shell">
    <header class="mini-topbar">
        <div class="mini-user">
            <div class="avatar" id="mini-avatar">؟</div>
            <div class="mini-user-meta">
                <div class="mini-name" id="mini-name">در حال اتصال…</div>
                <div class="mini-sub" id="mini-sub">احراز هویت امن پیام‌رسان</div>
            </div>
        </div>
        <div class="mini-top-actions">
            <span class="badge badge-brand no-dot hidden" id="mini-platform-badge" style="font-size:.68rem;padding:4px 8px">—</span>
            <button class="mini-icon-btn" type="button" data-theme-toggle title="تغییر حالت تاریک / روشن" aria-label="تغییر تم">
                <span class="theme-icon-dark">🌙</span>
                <span class="theme-icon-light">☀️</span>
            </button>
            <button class="mini-icon-btn" type="button" id="refresh-all" title="به‌روزرسانی" aria-label="به‌روزرسانی">↻</button>
        </div>
    </header>

    <div id="auth-gate" class="mini-gate hidden">
        <div class="empty-icon">🔒</div>
        <div class="empty-title">دسترسی از داخل پیام‌رسان لازم است</div>
        <div class="empty-text">این پنل با احراز هویت رمزنگاری‌شده تلگرام، بله و ایتا کار می‌کند. صفحه را از دکمه «پنل کاربری» یا برنامک پیام‌رسان باز کنید.</div>
        <div id="auth-error" class="form-error"></div>
        <a class="btn btn-block mt-2" id="open-bot" href="#" style="display:none">🤖 بازگشت به ربات</a>
    </div>

    <div id="mini-content" class="hidden">
        <!-- کارت‌های قهرمان کیف پول و درآمد -->
        <div class="mini-cards">
            <div class="mini-card brand">
                <div class="mini-card-label">👛 موجودی قابل برداشت</div>
                <div class="mini-card-value" id="stat-balance">—</div>
                <div class="mini-card-foot" id="stat-pending">—</div>
            </div>
            <div class="mini-card glass">
                <div class="mini-card-label">💎 درآمد کل</div>
                <div class="mini-card-value" id="stat-earned">—</div>
                <div class="mini-card-foot" id="stat-rank">—</div>
            </div>
        </div>

        <!-- شاخص‌های سریع شبکه (تفکیک دقیق مستقیم و لایه‌ای) -->
        <div class="mini-stats">
            <div class="mini-stat" data-goto="referrals" role="button" tabindex="0">
                <span id="stat-direct">—</span>
                <small>دعوت مستقیم (L0)</small>
            </div>
            <div class="mini-stat" data-goto="referrals" role="button" tabindex="0">
                <span id="stat-network">—</span>
                <small>کل شبکه (L0+)</small>
            </div>
            <div class="mini-stat" data-goto="withdraw" role="button" tabindex="0">
                <span id="stat-withdrawn">—</span>
                <small>برداشت (تومان)</small>
            </div>
        </div>

        <!-- جعبه لینک دعوت سریع + دکمه ورود به استودیوی دعوت و بنر -->
        <div class="mini-invite" id="invite-box">
            <div class="mini-invite-head">
                <span class="mini-invite-title">🔗 لینک دعوت اختصاصی شما</span>
                <span class="mini-tier-pill" id="mini-tier-pill">🌱 شروع‌کننده</span>
            </div>
            <div class="text-xs hidden" id="invite-rewards-note" style="margin-bottom:8px;opacity:.92;line-height:1.7"></div>
            <div class="mini-link ltr" id="invite-link">—</div>
            <div class="mini-invite-actions" style="flex-wrap:wrap">
                <button class="btn btn-sm" type="button" id="copy-invite">📋 کپی لینک</button>
                <button class="btn btn-sm btn-success" type="button" id="share-invite">📤 ارسال دعوت</button>
                <button class="btn btn-sm btn-secondary" type="button" data-goto="invite">🎨 بنر و پیام آماده</button>
            </div>
        </div>

        <!-- کارت تعاملی تکمیل شرایط (شماره تماس / کانال / مجوز اعلان ایتا) -->
        <div class="alert alert-info hidden" id="mini-welcome-guide" style="margin-bottom:12px">
            <span class="alert-icon">🎁</span>
            <div style="flex:1;min-width:0">
                <div id="mini-welcome-guide-body" class="text-sm"></div>
                <div id="mini-gate-actions" class="flex gap-1 mt-2" style="flex-wrap:wrap"></div>
            </div>
        </div>

        <!-- تب‌های ناوبری -->
        <nav class="mini-tabs" aria-label="بخش‌های پنل کاربری">
            <button class="mini-tab active" data-tab="home">🏠 خانه</button>
            <button class="mini-tab" data-tab="invite">🎨 دعوت و بنر</button>
            <button class="mini-tab" data-tab="referrals">👥 شبکه</button>
            <button class="mini-tab" data-tab="profile">👤 پروفایل</button>
            <button class="mini-tab" data-tab="wallet">👛 کیف پول</button>
            <button class="mini-tab" data-tab="withdraw">🏦 برداشت</button>
            <button class="mini-tab" data-tab="more">✨ بیشتر</button>
        </nav>

        <!-- تب ۱: خانه -->
        <section class="mini-panel" data-panel="home">
            <div class="card mini-chart-card">
                <div class="card-header justify-between">
                    <div>
                        <h2 class="card-title">📈 نمودار دعوت‌های من (۱۴ روز اخیر)</h2>
                        <span class="card-subtitle" id="home-chart-sub">روند روزانه عضویت با لینک شما</span>
                    </div>
                    <button class="btn btn-ghost btn-sm" type="button" data-goto="profile">آمار کامل ←</button>
                </div>
                <div class="mini-chart-body">
                    <div class="mini-chart-wrap" style="height:175px"><canvas id="mini-chart-home"></canvas></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header justify-between">
                    <h2 class="card-title">🧾 آخرین تراکنش‌ها</h2>
                    <button class="btn btn-ghost btn-sm" type="button" data-goto="wallet">همه ←</button>
                </div>
                <div id="home-tx" class="mini-list"></div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">🎁 جشنواره‌ها و جوایز فعال</h2></div>
                <div id="home-campaigns" class="mini-list"></div>
            </div>
        </section>

        <!-- تب ۲: استودیوی دعوت و بنرهای تبلیغاتی -->
        <section class="mini-panel hidden" data-panel="invite">
            <div class="card">
                <div class="card-header justify-between">
                    <div>
                        <h2 class="card-title">🎨 استودیوی دعوت دوستان</h2>
                        <span class="card-subtitle">لینک دعوت اختصاصی شما در تمام حالت‌ها به‌صورت خودکار درج می‌شود</span>
                    </div>
                </div>
                <div class="card-body">
                    <!-- ۴ حالت دعوت -->
                    <div class="studio-modes" role="tablist">
                        <button type="button" class="studio-mode-btn active" data-studio-mode="message">📝 متن آماده</button>
                        <button type="button" class="studio-mode-btn" data-studio-mode="banner_caption">🖼 بنر + متن</button>
                        <button type="button" class="studio-mode-btn" data-studio-mode="banner_link">🎯 بنر + لینک</button>
                        <button type="button" class="studio-mode-btn" data-studio-mode="links">🔗 همه لینک‌ها</button>
                    </div>

                    <!-- انتخابگر قالب پیام -->
                    <div id="studio-msg-picker">
                        <div class="text-xs text-muted mb-1">انتخاب قالب پیام دعوت:</div>
                        <div class="studio-pills" id="studio-msg-pills"></div>
                    </div>

                    <!-- انتخابگر بنر تبلیغاتی -->
                    <div id="studio-banner-picker" class="hidden">
                        <div class="text-xs text-muted mb-1">انتخاب بنر تبلیغاتی:</div>
                        <div class="studio-banners" id="studio-banner-grid"></div>
                    </div>

                    <!-- پیش‌نمایش زنده -->
                    <div id="studio-preview-wrap" class="studio-preview">
                        <img id="studio-preview-img" class="studio-preview-img hidden" src="" alt="بنر دعوت">
                        <div id="studio-preview-text" class="studio-preview-text">—</div>
                    </div>

                    <!-- دکمه‌های اقدام در حالت پیام/بنر -->
                    <div id="studio-main-actions" class="studio-actions">
                        <button type="button" class="btn btn-success btn-sm" id="studio-share-btn">📤 اشتراک‌گذاری با دوستان</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="studio-copy-text-btn">📋 کپی متن + لینک</button>
                        <button type="button" class="btn btn-sm hidden" id="studio-send-bot-btn">🤖 ارسال بنر به گفتگوی من</button>
                        <a class="btn btn-ghost btn-sm hidden" id="studio-download-banner" href="#" download target="_blank" rel="noopener">⬇️ دریافت تصویر بنر</a>
                    </div>

                    <!-- لیست همه لینک‌های چندپیام‌رسانی -->
                    <div id="studio-links-list" class="hidden"></div>
                </div>
            </div>
        </section>

        <!-- تب ۳: زیرمجموعه‌ها و شبکه لایه‌ای -->
        <section class="mini-panel hidden" data-panel="referrals">
            <div class="card mini-chart-card">
                <div class="card-header justify-between">
                    <h2 class="card-title">📊 دعوت‌های روزانه (۱۴ روز اخیر)</h2>
                    <span class="card-subtitle" id="refs-summary">—</span>
                </div>
                <div class="mini-chart-body">
                    <div class="mini-chart-wrap" style="height:165px"><canvas id="mini-chart-refs-bar"></canvas></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header justify-between">
                    <div>
                        <h2 class="card-title">🕸 تفکیک لایه‌های شبکه و پورسانت</h2>
                        <span class="card-subtitle">لایه ۰ = دعوت مستقیم · لایه‌های ۱ به بعد = زیرمجموعه غیرمستقیم</span>
                    </div>
                </div>
                <div class="card-body" style="padding:14px 16px"><div id="levels-bars"></div></div>
            </div>

            <div class="card">
                <div class="card-header justify-between">
                    <h2 class="card-title">👥 زیرمجموعه‌های مستقیم من (لایه ۰)</h2>
                </div>
                <div id="refs-list" class="mini-list"></div>
            </div>

            <div class="card hidden" id="indirect-card">
                <div class="card-header justify-between">
                    <h2 class="card-title">🌐 آخرین عضویت‌های لایه‌های غیرمستقیم (L1+)</h2>
                </div>
                <div id="indirect-list" class="mini-list"></div>
            </div>
        </section>

        <!-- تب ۴: پروفایل، آمار و اتصال پیام‌رسان‌ها -->
        <section class="mini-panel hidden" data-panel="profile">
            <div class="mini-profile-hero">
                <div class="mini-profile-top">
                    <div class="mini-profile-avatar" id="prof-avatar">؟</div>
                    <div class="mini-profile-info">
                        <div class="mini-profile-name" id="prof-name">—</div>
                        <div class="mini-profile-meta" id="prof-meta">—</div>
                        <div class="mini-profile-badges">
                            <span class="badge badge-brand no-dot" id="prof-tier-badge">🌱 شروع‌کننده</span>
                            <span class="badge badge-info no-dot" id="prof-code-badge">کد: —</span>
                        </div>
                    </div>
                </div>
                <div class="mini-tier-progress">
                    <div class="flex justify-between text-xs mb-1">
                        <span id="prof-tier-next">پیشرفت تا سطح بعدی</span>
                        <strong id="prof-tier-pct">۰٪</strong>
                    </div>
                    <div class="mini-meter"><span id="prof-tier-bar" style="width:0%"></span></div>
                </div>
            </div>

            <!-- ۴ شاخص عملکرد دعوت کاربر -->
            <div class="mini-kpi-grid">
                <div class="mini-kpi">
                    <span class="mini-kpi-icon">⚡</span>
                    <strong id="kpi-inv-today">۰</strong>
                    <small>دعوت امروز</small>
                </div>
                <div class="mini-kpi">
                    <span class="mini-kpi-icon">📅</span>
                    <strong id="kpi-inv-week">۰</strong>
                    <small>۷ روز اخیر</small>
                </div>
                <div class="mini-kpi">
                    <span class="mini-kpi-icon">🗓</span>
                    <strong id="kpi-inv-month">۰</strong>
                    <small>۳۰ روز اخیر</small>
                </div>
                <div class="mini-kpi">
                    <span class="mini-kpi-icon">🔥</span>
                    <strong id="kpi-inv-best">۰</strong>
                    <small id="kpi-inv-best-lbl">رکورد روزانه</small>
                </div>
            </div>

            <!-- اتصال یکپارچه حساب بین پیام‌رسان‌ها -->
            <div class="card">
                <div class="card-header justify-between">
                    <div>
                        <h2 class="card-title">🔄 پیام‌رسان‌های متصل به حساب من</h2>
                        <span class="card-subtitle">کیف پول، کد دعوت و زیرمجموعه‌های واحد در تلگرام، بله، روبیکا و ایتا</span>
                    </div>
                </div>
                <div class="card-body">
                    <div id="linked-messengers-list" class="mb-2"></div>
                    <div class="flex gap-1" style="flex-wrap:wrap">
                        <button type="button" class="btn btn-secondary btn-sm" id="btn-gen-link-token">🔑 ساخت کد اتصال پیام‌رسان دیگر</button>
                    </div>
                    <div id="link-token-output" class="link-token-box hidden"></div>
                    <form id="redeem-link-form" class="mt-2" style="display:flex;gap:6px;align-items:center">
                        <input type="text" name="code" class="form-control ltr" placeholder="کد اتصال (LNK-XXXXXX)" maxlength="32" style="flex:1">
                        <button type="submit" class="btn btn-sm">اتصال</button>
                    </form>
                    <div class="form-hint">اگر در پیام‌رسان دیگری حساب دارید، در آن پیام‌رسان کد اتصال بسازید و اینجا وارد کنید.</div>
                </div>
            </div>

            <!-- نمودار اصلی رشد دعوت‌های کاربر -->
            <div class="card mini-chart-card">
                <div class="card-header justify-between">
                    <div>
                        <h2 class="card-title">📊 آمار و روند دعوت‌های من</h2>
                        <span class="card-subtitle">روی نمودار لمس کنید تا آمار دقیق هر روز نمایش داده شود</span>
                    </div>
                </div>
                <div class="mini-chart-body">
                    <div class="mini-chart-controls">
                        <div class="mini-seg" data-chart-mode-group>
                            <button type="button" class="active" data-invite-mode="cumulative">رشد تجمعی</button>
                            <button type="button" data-invite-mode="daily">دعوت روزانه</button>
                        </div>
                        <div class="mini-seg" data-chart-range-group>
                            <button type="button" class="active" data-invite-range="14">۱۴ روز</button>
                            <button type="button" data-invite-range="30">۳۰ روز</button>
                        </div>
                    </div>
                    <div class="mini-chart-wrap" style="height:210px"><canvas id="mini-chart-invites"></canvas></div>
                </div>
            </div>

            <!-- نمودار حلقه‌ای کیفیت دعوت‌ها -->
            <div class="card mini-chart-card">
                <div class="card-header justify-between">
                    <h2 class="card-title">🎯 وضعیت شبکه من</h2>
                    <span class="badge badge-success no-dot" id="prof-qual-badge">۰٪ تأییدشده</span>
                </div>
                <div class="mini-chart-body">
                    <div class="mini-donut-row">
                        <div class="mini-donut-canvas"><canvas id="mini-chart-quality"></canvas></div>
                        <div class="mini-donut-legend">
                            <div class="mini-legend-item">
                                <span><i class="legend-dot" style="background:#10b981"></i> مستقیم واجد شرایط (L0)</span>
                                <strong id="leg-qualified">۰ نفر</strong>
                            </div>
                            <div class="mini-legend-item">
                                <span><i class="legend-dot" style="background:#f59e0b"></i> مستقیم در انتظار (L0)</span>
                                <strong id="leg-pending">۰ نفر</strong>
                            </div>
                            <div class="mini-legend-item">
                                <span><i class="legend-dot" style="background:#818cf8"></i> زیرمجموعه لایه‌ها (L1+)</span>
                                <strong id="leg-indirect">۰ نفر</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- نمودار روند و ترکیب درآمد کاربر -->
            <div class="card mini-chart-card">
                <div class="card-header justify-between">
                    <div>
                        <h2 class="card-title">💰 نمودار و تفکیک درآمد من</h2>
                        <span class="card-subtitle">درآمد روزانه ۱۴ روز اخیر و تفکیک دقیق منابع</span>
                    </div>
                </div>
                <div class="mini-chart-body">
                    <div class="mini-chart-wrap" style="height:180px"><canvas id="mini-chart-earnings"></canvas></div>
                    <div class="mini-earn-split">
                        <div><small>معرفی مستقیم (L0)</small><strong id="earn-direct">۰</strong></div>
                        <div><small>خوش‌آمدگویی</small><strong id="earn-welcome">۰</strong></div>
                        <div><small>لایه‌های غیرمستقیم</small><strong id="earn-layer">۰</strong></div>
                        <div><small>جایزه کمپین</small><strong id="earn-campaign">۰</strong></div>
                    </div>
                </div>
            </div>

            <!-- ویرایش مشخصات پروفایل -->
            <div class="card">
                <div class="card-header justify-between">
                    <h2 class="card-title">✏️ ویرایش اطلاعات پروفایل</h2>
                    <span class="card-subtitle" id="prof-joined-date"></span>
                </div>
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
                    <button class="btn btn-secondary btn-block" type="submit">💾 ذخیره تغییرات پروفایل</button>
                </form>
            </div>
        </section>

        <!-- تب ۵: کیف پول -->
        <section class="mini-panel hidden" data-panel="wallet">
            <div class="card">
                <div class="card-header justify-between">
                    <h2 class="card-title">👛 کیف پول و تراکنش‌ها</h2>
                    <select class="form-select" id="tx-filter" style="max-width:155px">
                        <option value="">همه تراکنش‌ها</option>
                        <option value="commission_direct">پاداش مستقیم (L0)</option>
                        <option value="commission_welcome">پاداش خوش‌آمدگویی</option>
                        <option value="commission_level">پورسانت لایه‌ای (L1+)</option>
                        <option value="campaign_reward">جایزه کمپین</option>
                        <option value="withdrawal_reserve">برداشت</option>
                        <option value="withdrawal_release">بازگشت برداشت</option>
                        <option value="adjustment">تعدیل مدیر</option>
                    </select>
                </div>
                <div id="wallet-tx" class="mini-list"></div>
                <div class="card-footer"><button class="btn btn-secondary btn-block btn-sm" id="load-more-tx">نمایش بیشتر</button></div>
            </div>
        </section>

        <!-- تب ۶: برداشت -->
        <section class="mini-panel hidden" data-panel="withdraw">
            <div class="card">
                <div class="card-header"><h2 class="card-title">🏦 ثبت درخواست برداشت</h2></div>
                <form id="withdraw-form" class="card-body">
                    <div class="form-group">
                        <label class="form-label">مبلغ (تومان)</label>
                        <input type="text" name="amount" class="form-control ltr" inputmode="numeric" id="withdraw-amount" required>
                        <div class="form-hint" id="withdraw-min">—</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">شماره کارت بانکی (۱۶ رقم)</label>
                        <input type="text" name="card_number" class="form-control ltr" dir="ltr" inputmode="numeric" maxlength="19" required placeholder="6037-9911-XXXX-XXXX">
                    </div>
                    <div class="form-group">
                        <label class="form-label">نام و نام خانوادگی صاحب کارت</label>
                        <input type="text" name="card_holder" class="form-control" required>
                    </div>
                    <button class="btn btn-block" type="submit" id="withdraw-submit">💳 ثبت درخواست برداشت</button>
                    <div class="form-hint">مبلغ هنگام ثبت درخواست از موجودی شما رزرو می‌شود و تا تعیین وضعیت قابل برداشت نیست.</div>
                </form>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">📋 درخواست‌های برداشت من</h2></div>
                <div id="withdraw-list" class="mini-list"></div>
            </div>
        </section>

        <!-- تب ۷: بیشتر (رتبه‌بندی، اعلان‌ها، پشتیبانی) -->
        <section class="mini-panel hidden" data-panel="more">
            <div class="card">
                <div class="card-header"><h2 class="card-title">🏆 رتبه‌بندی برترین‌ها</h2></div>
                <div id="ranking-list" class="mini-list"></div>
            </div>
            <div class="card">
                <div class="card-header justify-between">
                    <h2 class="card-title">🔔 اعلان‌های من</h2>
                    <button class="btn btn-ghost btn-sm" type="button" id="read-all">خواندن همه</button>
                </div>
                <div id="notifications-list" class="mini-list"></div>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">☎️ ارتباط با پشتیبانی</h2></div>
                <form id="support-form" class="card-body">
                    <div class="form-group">
                        <label class="form-label">موضوع (اختیاری)</label>
                        <input type="text" name="subject" class="form-control" maxlength="120">
                    </div>
                    <div class="form-group">
                        <label class="form-label">متن پیام</label>
                        <textarea name="message" class="form-control" rows="3" required maxlength="3000"></textarea>
                    </div>
                    <button class="btn btn-block" type="submit">📨 ارسال پیام به پشتیبانی</button>
                </form>
            </div>
        </section>
    </div>
</div>

<script>
window.RB_MINIAPP = {
  bootstrapUrl: '<?= url('app/api/bootstrap') ?>',
  csrf: '<?= e(csrf_token()) ?>',
  platformHint: '<?= e($platformHint) ?>'
};
</script>
<script src="<?= asset('js/miniapp.js') ?>?v=<?= e(RB_VERSION) ?>"></script>
