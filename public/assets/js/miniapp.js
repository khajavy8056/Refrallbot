/* ============================================================
   RefrallBot — منطق پنل کاربری (Telegram Mini App)
   ============================================================ */
(function () {
  'use strict';

  const tg = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
  const API = {
    bootstrap: 'app/api/bootstrap',
    dashboard: 'app/api/dashboard',
    referrals: 'app/api/referrals',
    transactions: 'app/api/transactions',
    withdrawals: 'app/api/withdrawals',
    withdraw: 'app/api/withdraw',
    cancelWithdraw: 'app/api/withdraw',
    campaigns: 'app/api/campaigns',
    ranking: 'app/api/ranking',
    notifications: 'app/api/notifications',
    readNotifications: 'app/api/notifications/read',
    support: 'app/api/support',
    profile: 'app/api/profile',
    auth: 'app/auth'
  };

  const state = { profile: null, txPage: 1, txType: '', csrf: (window.RB_MINIAPP || {}).csrf || '' };

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

  function haptic(type) {
    if (!tg || !tg.HapticFeedback) return;
    try {
      if (type === 'error') tg.HapticFeedback.notificationOccurred('error');
      else if (type === 'success') tg.HapticFeedback.notificationOccurred('success');
      else tg.HapticFeedback.impactOccurred('light');
    } catch (e) {}
  }

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
    console.log(message);
  }

  function money(n) {
    return (window.RB ? RB.money(n) : Number(n || 0).toLocaleString('en-US') + ' تومان');
  }

  function faNum(n) { return window.RB ? RB.faNum(n) : String(n); }

  function loadingList(host, text) {
    if (!host) return;
    host.innerHTML = '<div class="loading"><span class="spinner"></span> ' + (text || 'در حال بارگذاری…') + '</div>';
  }

  function emptyList(host, title, text) {
    if (!host) return;
    host.innerHTML = '<div class="empty" style="padding:22px"><div class="empty-icon">📭</div>' +
      '<div class="empty-title">' + title + '</div>' +
      (text ? '<div class="empty-text">' + text + '</div>' : '') + '</div>';
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
      // شاید سشن قبلاً معتبر شده باشد
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
      return true;
    } catch (e) {
      showGate('خطای شبکه در احراز هویت.');
      return false;
    }
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
      ? 'در انتظار: ' + money(data.wallet.pending)
      : 'بدون مبلغ در انتظار';
    $('#stat-earned').textContent = money(data.wallet.total_earned);
    $('#stat-rank').textContent = u.rank ? 'رتبه شما: ' + faNum(u.rank) : 'رتبه‌بندی غیرفعال';
    $('#stat-direct').textContent = faNum(data.counts.direct_refs);
    $('#stat-network').textContent = faNum(data.counts.total_refs);
    $('#stat-withdrawn').textContent = faNum(data.wallet.total_withdrawn);

    const link = $('#invite-link');
    if (link) link.textContent = u.invite_link || u.referral_code;
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

    renderTx('#home-tx', data.recent_transactions || [], 5, true);
    renderCampaigns(data.campaigns || []);

    // پروفایل
    const pf = $('#profile-form');
    if (pf) {
      pf.first_name.value = u.name ? (u.name.split(' ')[0] || '') : '';
      pf.last_name.value = u.name ? (u.name.split(' ').slice(1).join(' ')) : '';
    }

    if (!u.phone) {
      toast('برای دریافت شماره تماس، از دکمه «ارسال شماره» در ربات استفاده کنید.', 'info');
    }
  }

  function renderTx(hostSel, rows, limit, compact) {
    const host = $(hostSel);
    if (!host) return;
    if (!rows.length) { emptyList(host, 'تراکنشی ثبت نشده', 'با دعوت دوستان، درآمد شما در این بخش ثبت می‌شود.'); return; }
    host.innerHTML = '';
    rows.slice(0, limit || rows.length).forEach(function (tx) {
      const isCredit = tx.direction === 'credit';
      const row = el('div', 'mini-row');
      row.innerHTML =
        '<div class="mini-row-icon ' + (isCredit ? 'in' : 'out') + '">' + (isCredit ? '↓' : '↑') + '</div>' +
        '<div class="mini-row-main">' +
          '<div class="mini-row-title">' + (window.RB ? RB.escape(tx.type_label) : tx.type_label) + '</div>' +
          '<div class="mini-row-sub">' + (window.RB ? RB.escape(tx.created_at) : tx.created_at) +
            (tx.status !== 'completed' ? ' · ' + (window.RB ? RB.escape(tx.status_label) : tx.status) : '') + '</div>' +
        '</div>' +
        '<div class="mini-row-amount ' + (isCredit ? 'in' : 'out') + '">' +
          (isCredit ? '+' : '−') + (window.RB ? RB.faNum(tx.amount_fa) : tx.amount_fa) +
        '</div>';
      host.appendChild(row);
    });
  }

  function renderCampaigns(list) {
    const host = $('#home-campaigns');
    if (!host) return;
    if (!list.length) { emptyList(host, 'کمپین فعالی وجود ندارد', 'کمپین‌های جشنواره در این بخش نمایش داده می‌شوند.'); return; }
    host.innerHTML = '';
    list.forEach(function (c) {
      const pct = c.required > 0 ? Math.min(100, Math.round(c.progress / c.required * 100)) : 0;
      const row = el('div', 'mini-row', '');
      row.style.flexDirection = 'column';
      row.style.alignItems = 'stretch';
      row.innerHTML =
        '<div class="flex justify-between items-center mb-1"><strong>' + (window.RB ? RB.escape(c.title) : c.title) + '</strong>' +
        '<span class="badge badge-brand no-dot">' + money(c.reward) + '</span></div>' +
        (c.description ? '<div class="mini-row-sub mb-1">' + (window.RB ? RB.escape(c.description) : '') + '</div>' : '') +
        '<div style="height:6px;background:#e2e8f0;border-radius:99px;overflow:hidden">' +
          '<div style="height:100%;width:' + pct + '%;background:linear-gradient(90deg,#6366f1,#10b981)"></div></div>' +
        '<div class="mini-row-sub">' + faNum(c.progress) + ' از ' + faNum(c.required) +
          ' دعوت · مهلت: ' + (c.ends_at ? (window.RB ? RB.escape(c.ends_at) : c.ends_at) : 'نامحدود') + '</div>';
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
          '<div class="mini-row-title">' + (window.RB ? RB.escape(tx.type_label) : tx.type_label) + '</div>' +
          '<div class="mini-row-sub">' + (window.RB ? RB.escape(tx.created_at) : tx.created_at) + '</div></div>' +
        '<div class="mini-row-amount ' + (isCredit ? 'in' : 'out') + '">' +
          (isCredit ? '+' : '−') + (window.RB ? RB.faNum(tx.amount_fa) : tx.amount_fa) + '</div>';
      host.appendChild(row);
    });
    const more = $('#load-more-tx');
    if (more) more.style.display = state.txPage < data.pages ? '' : 'none';
  }

  /* ------------------------------ زیرمجموعه ------------------------------ */
  async function loadReferrals() {
    const host = $('#refs-list');
    loadingList(host);
    const data = await api('referrals');
    if (!data.ok) { toast(data.error || 'خطا', 'error'); return; }
    $('#refs-summary').textContent = faNum(data.totals.direct) + ' مستقیم · ' + faNum(data.totals.network) + ' کل شبکه';
    if (!data.direct.length) {
      emptyList(host, 'هنوز زیرمجموعه‌ای ندارید', 'لینک دعوت خود را برای دوستان بفرستید.');
    } else {
      host.innerHTML = '';
      data.direct.forEach(function (r) {
        const row = el('div', 'mini-row');
        row.innerHTML =
          '<div class="mini-row-icon in">' + (window.RB ? RB.escape((r.name || '؟').charAt(0)) : '؟') + '</div>' +
          '<div class="mini-row-main">' +
            '<div class="mini-row-title">' + (window.RB ? RB.escape(r.name) : r.name) + '</div>' +
            '<div class="mini-row-sub">' + (r.username ? '@' + (window.RB ? RB.escape(r.username) : r.username) : 'بدون یوزرنیم') +
              ' · ' + (window.RB ? RB.escape(r.joined_at) : r.joined_at) + '</div></div>' +
          '<div class="badge badge-' + (r.qualified ? 'success' : 'warning') + '">' + (r.qualified ? 'واجد شرایط' : 'در انتظار') + '</div>';
        host.appendChild(row);
      });
    }

    const bars = $('#levels-bars');
    if (bars) {
      if (!data.levels.length) {
        bars.innerHTML = '<div class="text-sm text-muted">شبکه‌ای برای نمایش وجود ندارد.</div>';
      } else {
        const max = Math.max.apply(null, data.levels.map(l => l.count));
        bars.innerHTML = data.levels.map(function (l) {
          const pct = max > 0 ? Math.round(l.count / max * 100) : 0;
          return '<div class="mb-2"><div class="flex justify-between text-xs mb-1">' +
            '<span>سطح ' + faNum(l.level) + '</span><span>' + faNum(l.count) + ' نفر</span></div>' +
            '<div style="height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden">' +
            '<div style="height:100%;width:' + pct + '%;background:linear-gradient(90deg,#6366f1,#06b6d4)"></div></div></div>';
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
          '<div class="mini-row-title">' + (window.RB ? RB.faNum(r.amount_fa) : r.amount_fa) + '</div>' +
          '<div class="mini-row-sub">' + (window.RB ? RB.escape(r.code) : r.code) + ' · ' +
            (window.RB ? RB.escape(r.created_at) : r.created_at) + '</div>' +
          (r.reject_reason ? '<div class="mini-row-sub" style="color:#b91c1c">' + (window.RB ? RB.escape(r.reject_reason) : r.reject_reason) + '</div>' : '') +
          (r.tracking_code ? '<div class="mini-row-sub">کد پیگیری: ' + (window.RB ? RB.escape(r.tracking_code) : r.tracking_code) + '</div>' : '') +
        '</div>' +
        '<span class="badge badge-' + (badges[r.status] || 'muted') + '">' + (window.RB ? RB.escape(r.status_label) : r.status) + '</span>' +
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
    data.rows.forEach(function (r) {
      const row = el('div', 'mini-row');
      if (r.is_me) row.style.background = 'var(--brand-50, #eef2ff)';
      row.innerHTML =
        '<div class="mini-row-icon in">' + faNum(r.rank) + '</div>' +
        '<div class="mini-row-main"><div class="mini-row-title">' + (window.RB ? RB.escape(r.name) : r.name) +
          (r.is_me ? ' (شما)' : '') + '</div>' +
          '<div class="mini-row-sub">' + (r.username ? '@' + (window.RB ? RB.escape(r.username) : r.username) : '') + '</div></div>' +
        '<div class="mini-row-amount">' + (window.RB ? RB.faNum(r.value_fa) : r.value_fa) + '</div>';
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
      const row = el('div', 'mini-row');
      if (!n.read) row.style.background = '#f8fafc';
      row.innerHTML =
        '<div class="mini-row-main"><div class="mini-row-title">' + (window.RB ? RB.escape(n.title) : n.title) + '</div>' +
        '<div class="mini-row-sub" style="white-space:pre-wrap">' + (window.RB ? RB.escape(n.body) : n.body) + '</div>' +
        '<div class="mini-row-sub">' + (window.RB ? RB.escape(n.created_at) : n.created_at) + '</div></div>' +
        (!n.read ? '<span class="badge badge-info no-dot">جدید</span>' : '');
      host.appendChild(row);
    });
  }

  /* ------------------------------ تب‌ها ------------------------------ */
  function activateTab(name) {
    $$('.mini-tab').forEach(t => t.classList.toggle('active', t.getAttribute('data-tab') === name));
    $$('.mini-panel').forEach(p => p.classList.toggle('hidden', p.getAttribute('data-panel') !== name));
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

    if (e.target.closest('#copy-invite')) {
      const link = $('#invite-link').textContent.trim();
      if (window.RB) RB.copy(link); else { navigator.clipboard.writeText(link); toast('کپی شد', 'success'); }
      haptic('success');
      return;
    }
    if (e.target.closest('#share-invite')) {
      const link = $('#invite-link').textContent.trim();
      const text = 'با این لینک به ربات بپیوند و از پاداش دعوت بهره‌مند شو:\n' + link;
      if (tg && tg.openTelegramLink) {
        tg.openTelegramLink('https://t.me/share/url?url=' + encodeURIComponent(link) + '&text=' + encodeURIComponent(text));
      } else if (navigator.share) {
        navigator.share({ title: 'دعوت به ربات', text: text, url: link }).catch(() => {});
      } else { window.RB && RB.copy(link); }
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
      data.amount = String(data.amount || '').replace(/[^\d]/g, '');
      data.card_number = String(data.card_number || '').replace(/[^\d]/g, '');
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
        tg.setHeaderColor('#4f46e5');
        tg.setBackgroundColor('#f5f7fb');
      } catch (e) {}
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
