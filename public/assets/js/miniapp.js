/* ============================================================
   RefrallBot — منطق پنل کاربری (Telegram Mini App v1.2)
   پشتیبانی از دارک‌مود پیش‌فرض + نمودارهای تعاملی دعوت و پروفایل
   ============================================================ */
(function () {
  'use strict';

  const tg = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
  const API = { auth: 'app/auth' };

  const state = {
    profile: null,
    txPage: 1,
    txType: '',
    activeTab: 'home',
    inviteChartMode: 'cumulative', // cumulative | daily
    inviteChartRange: 14,          // 14 | 30
    csrf: (window.RB_MINIAPP || {}).csrf || ''
  };

  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => Array.from(document.querySelectorAll(sel));

  function url(path) {
    const base = document.documentElement.getAttribute('data-base') || '';
    return (base ? base.replace(/\/$/, '') : '') + '/' + path.replace(/^\//, '');
  }

  function el(tag, className, html) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (html !== undefined) node.innerHTML = html;
    return node;
  }

  function esc(s) {
    return window.RB && RB.escape ? RB.escape(s) : String(s == null ? '' : s);
  }

  function haptic(type) {
    if (!tg || !tg.HapticFeedback) return;
    try {
      if (type === 'error') tg.HapticFeedback.notificationOccurred('error');
      else if (type === 'success') tg.HapticFeedback.notificationOccurred('success');
      else tg.HapticFeedback.impactOccurred('light');
    } catch (e) {}
  }

  function syncTelegramChrome() {
    if (!tg) return;
    const isDark = !window.RB || RB.getTheme() === 'dark';
    try {
      tg.setHeaderColor(isDark ? '#1e1b4b' : '#4f46e5');
      tg.setBackgroundColor(isDark ? '#0b1120' : '#f4f6fb');
      if (typeof tg.setBottomBarColor === 'function') {
        tg.setBottomBarColor(isDark ? '#0b1120' : '#f4f6fb');
      }
    } catch (e) {}
  }

  window.addEventListener('rb:themechange', function () {
    syncTelegramChrome();
    haptic();
  });

  async function api(path, options) {
    options = options || {};
    const headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
    if (state.csrf) headers['X-CSRF-Token'] = state.csrf;
    let body = options.body;
    if (body && !(body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    try {
      const res = await fetch(url('app/api/' + path), {
        method: options.method || 'GET',
        headers: headers,
        body: body,
        credentials: 'same-origin'
      });
      const text = await res.text();
      let data = null;
      try { data = JSON.parse(text); } catch (e) { data = { ok: false, error: 'پاسخ نامعتبر سرور (' + res.status + ')' }; }
      if (data && data.csrf) state.csrf = data.csrf;
      if (res.status === 401) { showGate(data.error || 'ابتدا از ربات وارد شوید.'); }
      return data || { ok: false, error: 'خطای نامشخص' };
    } catch (e) {
      return { ok: false, error: 'خطای شبکه. اتصال اینترنت را بررسی کنید.' };
    }
  }

  function toast(message, type) {
    if (tg && tg.showAlert && type === 'error') { tg.showAlert(message); return; }
    if (window.RB && RB.toast) { RB.toast(message, type || 'info'); return; }
  }

  function money(n) {
    return (window.RB ? RB.money(n) : Number(n || 0).toLocaleString('en-US') + ' تومان');
  }

  function enNum(v) {
    if (window.RB && RB.enNum) return RB.enNum(v);
    const map = {
      '۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9',
      '٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9'
    };
    return String(v === null || v === undefined ? '' : v).replace(/[۰-۹٠-٩]/g, d => map[d] || d);
  }

  function faNum(n) { return window.RB ? RB.faNum(n) : String(n); }
  function faFormat(n) { return faNum(Number(n || 0).toLocaleString('en-US')); }

  function loadingList(host, text) {
    if (!host) return;
    host.innerHTML = '<div class="loading"><span class="spinner"></span> ' + (text || 'در حال بارگذاری…') + '</div>';
  }

  function emptyList(host, title, text) {
    if (!host) return;
    host.innerHTML = '<div class="empty" style="padding:22px"><div class="empty-icon">📭</div>' +
      '<div class="empty-title">' + esc(title) + '</div>' +
      (text ? '<div class="empty-text">' + esc(text) + '</div>' : '') + '</div>';
  }

  function showGate(message) {
    const gate = $('#auth-gate');
    const content = $('#mini-content');
    if (content) content.classList.add('hidden');
    if (gate) {
      gate.classList.remove('hidden');
      const err = $('#auth-error');
      if (err && message) err.textContent = message;
    }
  }

  /* ------------------------------ احراز هویت ------------------------------ */
  async function authenticate() {
    const initData = tg && tg.initData ? tg.initData : '';
    if (!initData) {
      const probe = await api('bootstrap');
      if (probe && probe.ok) { return true; }
      showGate('این صفحه باید از داخل تلگرام باز شود. اگر از دسکتاپ باز کرده‌اید، از ربات روی «پنل کاربری» بزنید.');
      return false;
    }
    try {
      const res = await fetch(url(API.auth), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: JSON.stringify({ init_data: initData })
      });
      const data = await res.json().catch(() => null);
      if (!data || !data.ok) {
        showGate((data && data.error) || 'احراز هویت تلگرام ناموفق بود.');
        return false;
      }
      if (data.csrf) state.csrf = data.csrf;
      return true;
    } catch (e) {
      showGate('خطای شبکه در احراز هویت.');
      return false;
    }
  }

  /* ------------------------------ نمودارهای دعوت و پروفایل ------------------------------ */
  function renderHomeChart() {
    const a = state.profile && state.profile.analytics;
    const canvas = $('#mini-chart-home');
    if (!a || !canvas || !window.RB || canvas.offsetParent === null) return;
    const sub = $('#home-chart-sub');
    if (sub && a.kpis) {
      sub.textContent = faFormat(a.kpis.week) + ' دعوت در ۷ روز · ' + faFormat(a.kpis.month) + ' دعوت در ۳۰ روز اخیر';
    }
    RB.lineChart(canvas, {
      labels: a.labels_14 || [],
      unit: 'نفر',
      series: [
        { name: 'دعوت روزانه', data: a.daily_14 || [], color: '#818cf8', fill: true }
      ]
    });
  }

  function renderProfileInviteChart() {
    const a = state.profile && state.profile.analytics;
    const canvas = $('#mini-chart-invites');
    if (!a || !canvas || !window.RB || canvas.offsetParent === null) return;

    const is30 = state.inviteChartRange === 30;
    const labels = is30 ? (a.labels_30 || []) : (a.labels_14 || []);
    if (state.inviteChartMode === 'daily') {
      const vals = is30 ? (a.daily_30 || []) : (a.daily_14 || []);
      RB.barChart(canvas, {
        labels: labels,
        data: vals,
        name: 'دعوت جدید',
        unit: 'نفر',
        color: '#818cf8'
      });
    } else {
      const vals = is30 ? (a.cumulative_30 || []) : (a.cumulative_14 || []);
      RB.lineChart(canvas, {
        labels: labels,
        unit: 'نفر',
        series: [
          { name: 'مجموع دعوت‌های من', data: vals, color: '#06b6d4', fill: true }
        ]
      });
    }
  }

  function renderProfileCharts() {
    const d = state.profile;
    const a = d && d.analytics;
    if (!d || !a || !window.RB) return;

    renderProfileInviteChart();

    const qCanvas = $('#mini-chart-quality');
    if (qCanvas && qCanvas.offsetParent !== null) {
      const qual = Number((a.kpis && a.kpis.qualified_direct) || 0);
      const pend = Number((a.kpis && a.kpis.pending_direct) || 0);
      const indirect = Math.max(0, Number(d.counts.total_refs || 0) - Number(d.counts.direct_refs || 0));
      RB.donutChart(qCanvas, {
        data: [
          { label: 'واجد شرایط', value: qual },
          { label: 'در انتظار', value: pend },
          { label: 'زیرمجموعه لایه‌ها', value: indirect }
        ],
        colors: ['#10b981', '#f59e0b', '#818cf8'],
        centerValue: faFormat(d.counts.total_refs || 0),
        centerLabel: 'کل شبکه من',
        emptyText: 'بدون دعوت'
      });
    }

    const eCanvas = $('#mini-chart-earnings');
    if (eCanvas && eCanvas.offsetParent !== null) {
      RB.lineChart(eCanvas, {
        labels: a.labels_14 || [],
        unit: 'تومان',
        series: [
          { name: 'درآمد روزانه', data: a.earnings_14 || [], color: '#10b981', fill: true }
        ]
      });
    }
  }

  function renderReferralsBarChart() {
    const a = state.profile && state.profile.analytics;
    const canvas = $('#mini-chart-refs-bar');
    if (!a || !canvas || !window.RB || canvas.offsetParent === null) return;
    RB.barChart(canvas, {
      labels: a.labels_14 || [],
      data: a.daily_14 || [],
      name: 'دعوت مستقیم',
      unit: 'نفر',
      color: '#10b981'
    });
  }

  function populateProfileSection(data) {
    const u = data.user || {};
    const a = data.analytics || {};
    const kpis = a.kpis || {};
    const tier = a.tier || { icon: '🌱', name: 'شروع‌کننده', progress_pct: 0, remaining: 5, next_name: 'برنزی' };
    const eb = a.earnings_breakdown || {};
    const initial = (u.name || '؟').trim().charAt(0) || '؟';

    const setText = (sel, txt) => { const n = $(sel); if (n) n.textContent = txt; };

    setText('#mini-tier-pill', tier.icon + ' ' + tier.name);
    setText('#prof-avatar', initial);
    setText('#prof-name', u.name || 'کاربر');
    setText('#prof-meta', (u.username ? '@' + u.username + ' · ' : '') + 'شناسه: ' + faNum(u.telegram_id));
    setText('#prof-tier-badge', tier.icon + ' ' + tier.name);
    setText('#prof-code-badge', 'کد دعوت: ' + (u.referral_code || '—'));
    setText('#prof-joined-date', u.joined_date_fa ? 'عضویت: ' + u.joined_date_fa : '');

    if (tier.next_name) {
      setText('#prof-tier-next', faFormat(tier.remaining) + ' دعوت دیگر تا سطح «' + tier.next_name + '»');
    } else {
      setText('#prof-tier-next', 'تبریک! در بالاترین سطح دعوت‌کنندگان قرار دارید');
    }
    setText('#prof-tier-pct', faNum(tier.progress_pct || 0) + '٪');
    const bar = $('#prof-tier-bar');
    if (bar) bar.style.width = Math.min(100, Math.max(0, Number(tier.progress_pct) || 0)) + '%';

    setText('#kpi-inv-today', faFormat(kpis.today || 0));
    setText('#kpi-inv-week', faFormat(kpis.week || 0));
    setText('#kpi-inv-month', faFormat(kpis.month || 0));
    setText('#kpi-inv-best', faFormat(kpis.best_day || 0));
    setText('#kpi-inv-best-lbl', kpis.best_day > 0 ? ('رکورد (' + kpis.best_day_label + ')') : 'رکورد روزانه');

    setText('#prof-qual-badge', faNum(kpis.qualification_rate || 0) + '٪ تأییدشده');
    setText('#leg-qualified', faFormat(kpis.qualified_direct || 0) + ' نفر');
    setText('#leg-pending', faFormat(kpis.pending_direct || 0) + ' نفر');
    const indirect = Math.max(0, Number(data.counts.total_refs || 0) - Number(data.counts.direct_refs || 0));
    setText('#leg-indirect', faFormat(indirect) + ' نفر');

    setText('#earn-direct', money(eb.direct || 0));
    setText('#earn-welcome', money(eb.welcome || 0));
    setText('#earn-layer', money(eb.layer || 0));
    setText('#earn-campaign', money(eb.campaign || 0));
  }

  /* ------------------------------ داشبورد ------------------------------ */
  async function loadDashboard() {
    const data = await api('dashboard');
    if (!data.ok) {
      if (!data.need_auth) toast(data.error || 'خطا در دریافت داشبورد', 'error');
      return;
    }
    state.profile = data;
    const u = data.user;

    $('#mini-content').classList.remove('hidden');
    $('#auth-gate').classList.add('hidden');
    $('#mini-avatar').textContent = (u.name || '؟').trim().charAt(0) || '؟';
    $('#mini-name').textContent = u.name || 'کاربر';
    $('#mini-sub').textContent = u.username ? '@' + u.username : 'شناسه ' + faNum(u.telegram_id);

    $('#stat-balance').textContent = money(data.wallet.balance);
    $('#stat-pending').textContent = data.wallet.pending > 0
      ? 'در انتظار پرداخت: ' + money(data.wallet.pending)
      : 'آماده تسویه آنی';
    $('#stat-earned').textContent = money(data.wallet.total_earned);
    $('#stat-rank').textContent = u.rank ? '🏆 رتبه شما: ' + faFormat(u.rank) : 'عضویت: ' + (u.joined_date_fa || 'فعال');
    $('#stat-direct').textContent = faFormat(data.counts.direct_refs);
    $('#stat-network').textContent = faFormat(data.counts.total_refs);
    $('#stat-withdrawn').textContent = faFormat(data.wallet.total_withdrawn);

    const link = $('#invite-link');
    if (link) link.textContent = u.invite_link || u.referral_code;

    // نمایش پویای مبالغ پاداش دعوت‌کننده و دعوت‌شده از تنظیمات مدیر
    const rw = data.referral_rewards || {};
    state.shareText = rw.share_text || '';
    state.inviteText = rw.invite_text || '';
    const rwNote = $('#invite-rewards-note');
    if (rwNote) {
      const parts = [];
      if (rw.inviter_reward > 0) parts.push('🎯 پاداش شما: <b>' + esc(rw.inviter_reward_fa) + '</b>');
      if (rw.invitee_reward > 0) parts.push('🎁 هدیه دوستتان: <b>' + esc(rw.invitee_reward_fa) + '</b>');
      if (parts.length) {
        rwNote.innerHTML = parts.join(' · ');
        rwNote.classList.remove('hidden');
      } else {
        rwNote.classList.add('hidden');
      }
    }

    // راهنمای تکمیل شرایط پاداش خوش‌آمدگویی برای کاربر دعوت‌شده
    const guideBox = $('#mini-welcome-guide');
    const guideBody = $('#mini-welcome-guide-body');
    if (guideBox && guideBody) {
      const pendingList = Array.isArray(rw.pending_requirements) ? rw.pending_requirements : [];
      if (u.referred_by && !u.qualified && pendingList.length > 0) {
        const rewardIntro = rw.invitee_reward > 0
          ? '<b>هدیه خوش‌آمدگویی ' + esc(rw.invitee_reward_fa) + ' در انتظار شماست!</b><br>برای دریافت پاداش، مراحل زیر را تکمیل کنید:'
          : '<b>برای تکمیل ثبت‌نام دعوت خود مراحل زیر را انجام دهید:</b>';
        const items = pendingList.map(function (s) { return '• ' + esc(s); }).join('<br>');
        guideBody.innerHTML = rewardIntro + '<div style="margin-top:4px">' + items + '</div>';
        guideBox.classList.remove('hidden');
      } else {
        guideBox.classList.add('hidden');
      }
    }
    const openBot = $('#open-bot');
    if (openBot && u.invite_link) {
      openBot.href = u.invite_link.replace('?start=' + u.referral_code, '');
      openBot.style.display = '';
    }

    const minEl = $('#withdraw-min');
    if (minEl) {
      minEl.textContent = 'حداقل مبلغ برداشت: ' + money(data.withdraw.min_amount) +
        (data.withdraw.enabled ? '' : ' — برداشت موقتاً غیرفعال است');
    }
    const amountInput = $('#withdraw-amount');
    if (amountInput) amountInput.placeholder = String(data.withdraw.min_amount);
    const submit = $('#withdraw-submit');
    if (submit) submit.disabled = !data.withdraw.enabled;

    populateProfileSection(data);
    renderTx('#home-tx', data.recent_transactions || [], 5);
    renderCampaigns(data.campaigns || []);

    // فرم پروفایل
    const pf = $('#profile-form');
    if (pf) {
      pf.first_name.value = u.first_name !== undefined ? u.first_name : (u.name ? (u.name.split(' ')[0] || '') : '');
      pf.last_name.value = u.last_name !== undefined ? u.last_name : (u.name ? (u.name.split(' ').slice(1).join(' ')) : '');
    }

    // ترسیم نمودار تب فعال پس از نمایش DOM
    requestAnimationFrame(function () {
      if (state.activeTab === 'home') renderHomeChart();
      else if (state.activeTab === 'profile') renderProfileCharts();
      else if (state.activeTab === 'referrals') renderReferralsBarChart();
    });
  }

  function renderTx(hostSel, rows, limit) {
    const host = $(hostSel);
    if (!host) return;
    if (!rows.length) { emptyList(host, 'تراکنشی ثبت نشده', 'با دعوت دوستان، پاداش‌ها در این بخش ثبت می‌شود.'); return; }
    host.innerHTML = '';
    rows.slice(0, limit || rows.length).forEach(function (tx) {
      const isCredit = tx.direction === 'credit';
      const row = el('div', 'mini-row');
      row.innerHTML =
        '<div class="mini-row-icon ' + (isCredit ? 'in' : 'out') + '">' + (isCredit ? '↓' : '↑') + '</div>' +
        '<div class="mini-row-main">' +
          '<div class="mini-row-title">' + esc(tx.type_label) + '</div>' +
          '<div class="mini-row-sub">' + esc(tx.created_at) +
            (tx.status !== 'completed' ? ' · ' + esc(tx.status_label) : '') + '</div>' +
        '</div>' +
        '<div class="mini-row-amount ' + (isCredit ? 'in' : 'out') + '">' +
          (isCredit ? '+' : '−') + faNum(tx.amount_fa) +
        '</div>';
      host.appendChild(row);
    });
  }

  function renderCampaigns(list) {
    const host = $('#home-campaigns');
    if (!host) return;
    if (!list.length) { emptyList(host, 'جشنواره فعالی وجود ندارد', 'جشنواره‌های پاداش دعوت در این بخش نمایش داده می‌شوند.'); return; }
    host.innerHTML = '';
    list.forEach(function (c) {
      const pct = c.required > 0 ? Math.min(100, Math.round(c.progress / c.required * 100)) : 0;
      const row = el('div', 'mini-row', '');
      row.style.flexDirection = 'column';
      row.style.alignItems = 'stretch';
      row.innerHTML =
        '<div class="flex justify-between items-center mb-1"><strong>' + esc(c.title) + '</strong>' +
        '<span class="badge badge-brand no-dot">' + money(c.reward) + '</span></div>' +
        (c.description ? '<div class="mini-row-sub mb-1">' + esc(c.description) + '</div>' : '') +
        '<div class="mini-meter" style="margin:4px 0"><span style="width:' + pct + '%"></span></div>' +
        '<div class="mini-row-sub">' + faFormat(c.progress) + ' از ' + faFormat(c.required) +
          ' دعوت (' + faNum(pct) + '٪) · مهلت: ' + (c.ends_at ? esc(c.ends_at) : 'نامحدود') + '</div>';
      host.appendChild(row);
    });
  }

  /* ------------------------------ کیف پول ------------------------------ */
  async function loadTransactions(reset) {
    if (reset) { state.txPage = 1; }
    const host = $('#wallet-tx');
    if (reset) loadingList(host);
    const data = await api('transactions?page=' + state.txPage + (state.txType ? '&type=' + encodeURIComponent(state.txType) : ''));
    if (!data.ok) { toast(data.error || 'خطا', 'error'); return; }
    if (reset) host.innerHTML = '';
    if (!data.rows.length && reset) { emptyList(host, 'تراکنشی یافت نشد'); }
    data.rows.forEach(function (tx) {
      const isCredit = tx.direction === 'credit';
      const row = el('div', 'mini-row');
      row.innerHTML =
        '<div class="mini-row-icon ' + (isCredit ? 'in' : 'out') + '">' + (isCredit ? '↓' : '↑') + '</div>' +
        '<div class="mini-row-main">' +
          '<div class="mini-row-title">' + esc(tx.type_label) + '</div>' +
          '<div class="mini-row-sub">' + esc(tx.created_at) + '</div></div>' +
        '<div class="mini-row-amount ' + (isCredit ? 'in' : 'out') + '">' +
          (isCredit ? '+' : '−') + faNum(tx.amount_fa) + '</div>';
      host.appendChild(row);
    });
    const more = $('#load-more-tx');
    if (more) more.style.display = state.txPage < data.pages ? '' : 'none';
  }

  /* ------------------------------ زیرمجموعه ------------------------------ */
  async function loadReferrals() {
    requestAnimationFrame(renderReferralsBarChart);
    const host = $('#refs-list');
    loadingList(host);
    const data = await api('referrals');
    if (!data.ok) { toast(data.error || 'خطا', 'error'); return; }
    const sumEl = $('#refs-summary');
    if (sumEl) sumEl.textContent = faFormat(data.totals.direct) + ' مستقیم · ' + faFormat(data.totals.network) + ' کل شبکه';
    if (!data.direct.length) {
      emptyList(host, 'هنوز زیرمجموعه‌ای ندارید', 'لینک دعوت خود را برای دوستان بفرستید تا نمودار رشد شما شروع شود!');
    } else {
      host.innerHTML = '';
      data.direct.forEach(function (r) {
        const row = el('div', 'mini-row');
        row.innerHTML =
          '<div class="mini-row-icon in">' + esc((r.name || '؟').charAt(0)) + '</div>' +
          '<div class="mini-row-main">' +
            '<div class="mini-row-title">' + esc(r.name) + '</div>' +
            '<div class="mini-row-sub">' + (r.username ? '@' + esc(r.username) : 'کاربر تلگرام') +
              ' · ' + esc(r.joined_at) +
              (r.refs > 0 ? ' · ' + faFormat(r.refs) + ' زیرمجموعه' : '') + '</div></div>' +
          '<span class="badge badge-' + (r.qualified ? 'success' : 'warning') + '">' + (r.qualified ? 'تأییدشده' : 'در انتظار') + '</span>';
        host.appendChild(row);
      });
    }

    const bars = $('#levels-bars');
    if (bars) {
      if (!data.levels.length) {
        bars.innerHTML = '<div class="text-sm text-muted text-center" style="padding:8px 0">با اولین دعوت موفق، سطوح شبکه شما اینجا نمایش داده می‌شود.</div>';
      } else {
        const max = Math.max.apply(null, data.levels.map(l => l.count));
        bars.innerHTML = data.levels.map(function (l) {
          const pct = max > 0 ? Math.max(5, Math.round(l.count / max * 100)) : 0;
          return '<div class="mb-2"><div class="flex justify-between text-xs mb-1">' +
            '<span class="font-bold">سطح ' + faNum(l.level) + (l.level === 1 ? ' (مستقیم)' : '') + '</span>' +
            '<strong>' + faFormat(l.count) + ' نفر</strong></div>' +
            '<div class="mini-meter"><span style="width:' + pct + '%"></span></div></div>';
        }).join('');
      }
    }
  }

  /* ------------------------------ برداشت ------------------------------ */
  async function loadWithdrawals() {
    const host = $('#withdraw-list');
    loadingList(host);
    const data = await api('withdrawals');
    if (!data.ok) { toast(data.error || 'خطا', 'error'); return; }
    if (!data.rows.length) { emptyList(host, 'درخواستی ثبت نکرده‌اید'); return; }
    host.innerHTML = '';
    const badges = { new: 'info', reviewing: 'warning', approved: 'brand', paying: 'warning', paid: 'success', rejected: 'danger', cancelled: 'muted' };
    data.rows.forEach(function (r) {
      const row = el('div', 'mini-row');
      row.style.flexWrap = 'wrap';
      row.innerHTML =
        '<div class="mini-row-main">' +
          '<div class="mini-row-title">' + faNum(r.amount_fa) + '</div>' +
          '<div class="mini-row-sub">' + esc(r.code) + ' · ' + esc(r.created_at) + '</div>' +
          (r.reject_reason ? '<div class="mini-row-sub" style="color:var(--danger-text)">' + esc(r.reject_reason) + '</div>' : '') +
          (r.tracking_code ? '<div class="mini-row-sub">کد پیگیری: ' + esc(r.tracking_code) + '</div>' : '') +
        '</div>' +
        '<span class="badge badge-' + (badges[r.status] || 'muted') + '">' + esc(r.status_label) + '</span>' +
        (r.cancellable ? '<button class="btn btn-sm btn-ghost" data-cancel="' + r.id + '">لغو</button>' : '');
      host.appendChild(row);
    });
  }

  /* ------------------------------ رتبه و اعلان ------------------------------ */
  async function loadRanking() {
    const host = $('#ranking-list');
    loadingList(host);
    const data = await api('ranking');
    if (!data.ok) { toast(data.error || 'خطا', 'error'); return; }
    if (!data.enabled) { emptyList(host, 'رتبه‌بندی غیرفعال است'); return; }
    if (!data.rows.length) { emptyList(host, 'داده‌ای برای رتبه‌بندی وجود ندارد'); return; }
    host.innerHTML = '';
    const medals = { 1: '🥇', 2: '🥈', 3: '🥉' };
    data.rows.forEach(function (r) {
      const row = el('div', 'mini-row' + (r.is_me ? ' highlight' : ''));
      row.innerHTML =
        '<div class="mini-row-icon in">' + (medals[r.rank] || faNum(r.rank)) + '</div>' +
        '<div class="mini-row-main"><div class="mini-row-title">' + esc(r.name) +
          (r.is_me ? ' <span class="badge badge-brand no-dot">شما</span>' : '') + '</div>' +
          '<div class="mini-row-sub">' + (r.username ? '@' + esc(r.username) : '') + '</div></div>' +
        '<div class="mini-row-amount">' + faNum(r.value_fa) + '</div>';
      host.appendChild(row);
    });
  }

  async function loadNotifications() {
    const host = $('#notifications-list');
    loadingList(host);
    const data = await api('notifications');
    if (!data.ok) { toast(data.error || 'خطا', 'error'); return; }
    if (!data.rows.length) { emptyList(host, 'اعلانی وجود ندارد'); return; }
    host.innerHTML = '';
    data.rows.forEach(function (n) {
      const row = el('div', 'mini-row' + (!n.read ? ' unread' : ''));
      row.innerHTML =
        '<div class="mini-row-main"><div class="mini-row-title">' + esc(n.title) + '</div>' +
        '<div class="mini-row-sub" style="white-space:pre-wrap">' + esc(n.body) + '</div>' +
        '<div class="mini-row-sub">' + esc(n.created_at) + '</div></div>' +
        (!n.read ? '<span class="badge badge-info no-dot">جدید</span>' : '');
      host.appendChild(row);
    });
  }

  /* ------------------------------ تب‌ها ------------------------------ */
  function activateTab(name) {
    state.activeTab = name;
    $$('.mini-tab').forEach(t => t.classList.toggle('active', t.getAttribute('data-tab') === name));
    $$('.mini-panel').forEach(p => p.classList.toggle('hidden', p.getAttribute('data-panel') !== name));
    if (name === 'home') requestAnimationFrame(renderHomeChart);
    if (name === 'profile') requestAnimationFrame(renderProfileCharts);
    if (name === 'wallet') loadTransactions(true);
    if (name === 'referrals') loadReferrals();
    if (name === 'withdraw') loadWithdrawals();
    if (name === 'more') { loadRanking(); loadNotifications(); }
    haptic();
  }

  /* ------------------------------ اتصال رخدادها ------------------------------ */
  document.addEventListener('click', function (e) {
    const tab = e.target.closest('.mini-tab');
    if (tab) { activateTab(tab.getAttribute('data-tab')); return; }

    const goto = e.target.closest('[data-goto]');
    if (goto) { activateTab(goto.getAttribute('data-goto')); return; }

    const modeBtn = e.target.closest('[data-invite-mode]');
    if (modeBtn) {
      state.inviteChartMode = modeBtn.getAttribute('data-invite-mode');
      $$('[data-invite-mode]').forEach(b => b.classList.toggle('active', b === modeBtn));
      renderProfileInviteChart();
      haptic();
      return;
    }

    const rangeBtn = e.target.closest('[data-invite-range]');
    if (rangeBtn) {
      state.inviteChartRange = parseInt(rangeBtn.getAttribute('data-invite-range'), 10) || 14;
      $$('[data-invite-range]').forEach(b => b.classList.toggle('active', b === rangeBtn));
      renderProfileInviteChart();
      haptic();
      return;
    }

    if (e.target.closest('#copy-invite')) {
      const link = $('#invite-link').textContent.trim();
      if (window.RB) RB.copy(link); else if (navigator.clipboard) { navigator.clipboard.writeText(link); toast('کپی شد', 'success'); }
      haptic('success');
      return;
    }
    if (e.target.closest('#share-invite')) {
      const link = $('#invite-link').textContent.trim();
      const text = state.shareText || 'با این لینک به ربات بپیوند و از پاداش دعوت بهره‌مند شو 👇';
      if (tg && tg.openTelegramLink) {
        tg.openTelegramLink('https://t.me/share/url?url=' + encodeURIComponent(link) + '&text=' + encodeURIComponent(text));
      } else if (navigator.share) {
        navigator.share({ title: 'دعوت به ربات', text: state.inviteText || (text + '\n' + link), url: link }).catch(() => {});
      } else if (window.RB) { RB.copy(state.inviteText || link); }
      return;
    }
    if (e.target.closest('#read-all')) {
      api('notifications/read', { method: 'POST', body: {} }).then(function (r) {
        toast(r.message || 'انجام شد', r.ok ? 'success' : 'error');
        loadNotifications();
      });
      return;
    }
    if (e.target.closest('#load-more-tx')) { state.txPage += 1; loadTransactions(false); return; }
    if (e.target.closest('#refresh-all')) { loadDashboard(); haptic(); return; }

    const cancel = e.target.closest('[data-cancel]');
    if (cancel) {
      const id = cancel.getAttribute('data-cancel');
      if (!window.confirm('درخواست برداشت لغو شود و مبلغ به موجودی بازگردد؟')) return;
      api('withdraw/' + id + '/cancel', { method: 'POST', body: {} }).then(function (r) {
        toast(r.message || r.error || '', r.ok ? 'success' : 'error');
        loadWithdrawals();
        loadDashboard();
      });
    }
  });

  document.addEventListener('change', function (e) {
    if (e.target.id === 'tx-filter') {
      state.txType = e.target.value;
      loadTransactions(true);
    }
  });

  function formData(form) {
    const out = {};
    new FormData(form).forEach(function (v, k) { out[k] = v; });
    return out;
  }

  document.addEventListener('submit', async function (e) {
    const form = e.target;
    if (form.id === 'withdraw-form') {
      e.preventDefault();
      const data = formData(form);
      data.amount = enNum(data.amount || '').replace(/[^\d]/g, '');
      data.card_number = enNum(data.card_number || '').replace(/[^\d]/g, '');
      if (!data.amount || !data.card_number) { toast('مبلغ و شماره کارت الزامی است.', 'error'); return; }
      const btn = $('#withdraw-submit');
      const original = btn.textContent;
      btn.disabled = true; btn.textContent = 'در حال ثبت…';
      const res = await api('withdraw', { method: 'POST', body: data });
      btn.disabled = false; btn.textContent = original;
      if (res.ok) { toast(res.message || 'ثبت شد', 'success'); haptic('success'); form.reset(); loadWithdrawals(); loadDashboard(); }
      else { toast(res.error || 'خطا در ثبت', 'error'); haptic('error'); }
      return;
    }
    if (form.id === 'profile-form') {
      e.preventDefault();
      const res = await api('profile', { method: 'POST', body: formData(form) });
      toast(res.message || res.error || '', res.ok ? 'success' : 'error');
      if (res.ok) loadDashboard();
      return;
    }
    if (form.id === 'support-form') {
      e.preventDefault();
      const res = await api('support', { method: 'POST', body: formData(form) });
      toast(res.message || res.error || '', res.ok ? 'success' : 'error');
      if (res.ok) { form.reset(); haptic('success'); }
    }
  });

  /* ------------------------------ شروع ------------------------------ */
  async function boot() {
    if (tg) {
      try {
        tg.ready();
        tg.expand();
      } catch (e) {}
      syncTelegramChrome();
    }
    const ok = await authenticate();
    if (!ok) return;
    if (state.csrf) {
      const meta = document.querySelector('meta[name="csrf-token"]');
      if (meta) meta.setAttribute('content', state.csrf);
    }
    await loadDashboard();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
