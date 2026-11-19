/* ============================================================
   RefrallBot — منطق یکپارچه Mini App چندپیام‌رسانی (v1.4)
   پشتیبانی رسمی از Telegram WebApp، Bale MiniApp و Eitaa WebApp
   + استودیوی دعوت ۴ حالته (بنر + قالب پیام + تضمین لینک)
   + تفکیک دقیق لایه ۰ (مستقیم) و لایه‌های ۱+ (غیرمستقیم)
   + اتصال امن حساب بین پیام‌رسان‌ها
   ============================================================ */
(function () {
  'use strict';

  /** تشخیص هوشمند SDK پیام‌رسان میزبان (Bale / Eitaa / Telegram) */
  function detectHostWebApp() {
    const q = new URLSearchParams(window.location.search || '');
    const hint = (q.get('platform') || (window.RB_MINIAPP && window.RB_MINIAPP.platformHint) || document.documentElement.getAttribute('data-platform-hint') || '').toLowerCase();
    const bale = window.Bale && window.Bale.WebApp ? window.Bale.WebApp : null;
    const eitaa = window.Eitaa && window.Eitaa.WebApp ? window.Eitaa.WebApp : null;
    const tg = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;

    if (hint === 'bale' && bale) return { platform: 'bale', sdk: bale };
    if (hint === 'eitaa' && eitaa) return { platform: 'eitaa', sdk: eitaa };
    if (hint === 'telegram' && tg) return { platform: 'telegram', sdk: tg };

    if (eitaa && eitaa.initData) return { platform: 'eitaa', sdk: eitaa };
    if (bale && bale.initData) return { platform: 'bale', sdk: bale };
    if (tg && tg.initData) return { platform: 'telegram', sdk: tg };

    if (bale) return { platform: 'bale', sdk: bale };
    if (eitaa) return { platform: 'eitaa', sdk: eitaa };
    if (tg) return { platform: hint || 'telegram', sdk: tg };
    return { platform: hint || '', sdk: null };
  }

  const host = detectHostWebApp();
  const sdk = host.sdk;
  const API = { auth: 'app/auth' };

  const state = {
    platform: host.platform || 'telegram',
    profile: null,
    txPage: 1,
    txType: '',
    activeTab: 'home',
    inviteChartMode: 'cumulative',
    inviteChartRange: 14,
    studioMode: 'message', // message | banner_caption | banner_link | links
    studioMsgIdx: 0,
    studioBannerIdx: 0,
    shareText: '',
    inviteText: '',
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
    if (!sdk || !sdk.HapticFeedback) return;
    try {
      if (type === 'error' && sdk.HapticFeedback.notificationOccurred) sdk.HapticFeedback.notificationOccurred('error');
      else if (type === 'success' && sdk.HapticFeedback.notificationOccurred) sdk.HapticFeedback.notificationOccurred('success');
      else if (sdk.HapticFeedback.impactOccurred) sdk.HapticFeedback.impactOccurred('light');
    } catch (e) {}
  }

  function syncHostChrome() {
    if (!sdk) return;
    const isDark = !window.RB || RB.getTheme() === 'dark';
    try {
      if (typeof sdk.setHeaderColor === 'function') sdk.setHeaderColor(isDark ? '#1e1b4b' : '#4f46e5');
      if (typeof sdk.setBackgroundColor === 'function') sdk.setBackgroundColor(isDark ? '#0b1120' : '#f4f6fb');
      if (typeof sdk.setBottomBarColor === 'function') sdk.setBottomBarColor(isDark ? '#0b1120' : '#f4f6fb');
    } catch (e) {}
  }

  window.addEventListener('rb:themechange', function () {
    syncHostChrome();
    haptic();
  });

  function copyText(text, okMsg) {
    if (!text) return;
    if (window.RB && RB.copy) {
      RB.copy(text);
      if (okMsg) toast(okMsg, 'success');
    } else if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        toast(okMsg || 'کپی شد!', 'success');
      }).catch(function () {});
    }
    haptic('success');
  }

  /** تضمین صددرصدی حضور لینک دعوت در هر متن اشتراک‌گذاری */
  function ensureLinkInText(text, link) {
    const cleanLink = (link || '').trim();
    const cleanText = (text || '').trim();
    if (!cleanLink) return cleanText;
    if (!cleanText) return cleanLink;
    if (cleanText.indexOf(cleanLink) !== -1) return cleanText;
    return cleanText + '\n\n🔗 ' + cleanLink;
  }

  /** اشتراک‌گذاری هوشمند سازگار با تلگرام، بله، ایتا و مرورگر */
  function shareInviteContent(fullText, link, shareTextOnly) {
    const safeLink = (link || '').trim();
    const guaranteed = ensureLinkInText(fullText, safeLink);

    if (state.platform === 'telegram' && sdk && typeof sdk.openTelegramLink === 'function' && safeLink) {
      const txt = (shareTextOnly || fullText || '').replace(safeLink, '').trim();
      sdk.openTelegramLink('https://t.me/share/url?url=' + encodeURIComponent(safeLink) + '&text=' + encodeURIComponent(txt));
      haptic('success');
      return;
    }
    if (navigator.share) {
      navigator.share({ text: guaranteed }).then(function () {
        haptic('success');
      }).catch(function (err) {
        if (err && err.name === 'AbortError') return;
        copyText(guaranteed, 'متن دعوت همراه لینک کپی شد؛ برای دوستان ارسال کنید!');
      });
      return;
    }
    copyText(guaranteed, 'متن دعوت همراه لینک کپی شد؛ برای دوستان ارسال کنید!');
  }

  function openExternalOrMessengerLink(href) {
    if (!href) return;
    try {
      if (state.platform === 'telegram' && sdk && typeof sdk.openTelegramLink === 'function' && href.indexOf('https://t.me/') === 0) {
        sdk.openTelegramLink(href);
        return;
      }
      if (state.platform === 'eitaa' && sdk && typeof sdk.openEitaaLink === 'function' && href.indexOf('https://eitaa.com/') === 0) {
        sdk.openEitaaLink(href);
        return;
      }
      if (sdk && typeof sdk.openLink === 'function') {
        sdk.openLink(href);
        return;
      }
    } catch (e) {}
    window.open(href, '_blank', 'noopener');
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
      if (res.status === 401) { showGate(data.error || 'ابتدا از پیام‌رسان وارد شوید.'); }
      return data || { ok: false, error: 'خطای نامشخص' };
    } catch (e) {
      return { ok: false, error: 'خطای شبکه. اتصال اینترنت را بررسی کنید.' };
    }
  }

  function toast(message, type) {
    if (sdk && typeof sdk.showAlert === 'function' && type === 'error') {
      try { sdk.showAlert(message); return; } catch (e) {}
    }
    if (window.RB && RB.toast) { RB.toast(message, type || 'info'); }
  }

  function money(n) {
    return (window.RB ? RB.money(n) : Number(n || 0).toLocaleString('en-US') + ' تومان');
  }

  function enNum(v) {
    if (window.RB && RB.enNum) return RB.enNum(v);
    const map = {
      '۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9',
      '٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9',
      '０':'0','１':'1','２':'2','３':'3','４':'4','５':'5','６':'6','７':'7','８':'8','９':'9'
    };
    return String(v === null || v === undefined ? '' : v)
      .replace(/[\u00AD\u061C\u180E\u200B-\u200F\u202A-\u202E\u2060-\u206F\uFEFF]/g, '')
      .replace(/[۰-۹٠-٩０-９]/g, d => map[d] || d);
  }

  function faNum(n) { return window.RB ? RB.faNum(n) : String(n); }
  function faFormat(n) { return faNum(Number(n || 0).toLocaleString('en-US')); }

  function loadingList(hostEl, text) {
    if (!hostEl) return;
    hostEl.innerHTML = '<div class="loading"><span class="spinner"></span> ' + (text || 'در حال بارگذاری…') + '</div>';
  }

  function emptyList(hostEl, title, text) {
    if (!hostEl) return;
    hostEl.innerHTML = '<div class="empty" style="padding:22px"><div class="empty-icon">📭</div>' +
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

  /* ------------------------------ احراز هویت چندپیام‌رسانی ------------------------------ */
  async function authenticate() {
    const initData = sdk && sdk.initData ? String(sdk.initData) : '';
    const q = new URLSearchParams(window.location.search || '');
    const startParam = (sdk && sdk.initDataUnsafe && sdk.initDataUnsafe.start_param)
      || q.get('tgWebAppStartParam')
      || q.get('startapp')
      || q.get('start_param')
      || '';

    if (!initData) {
      const probe = await api('bootstrap');
      if (probe && probe.ok) {
        if (probe.platform) state.platform = probe.platform;
        return true;
      }
      showGate('این صفحه باید از داخل تلگرام، بله یا ایتا باز شود. از داخل ربات یا برنامک روی «پنل کاربری» بزنید.');
      return false;
    }
    try {
      const res = await fetch(url(API.auth), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: JSON.stringify({
          init_data: initData,
          platform: state.platform || '',
          start_param: startParam
        })
      });
      const data = await res.json().catch(() => null);
      if (!data || !data.ok) {
        showGate((data && data.error) || 'احراز هویت ناموفق بود.');
        return false;
      }
      if (data.csrf) state.csrf = data.csrf;
      if (data.platform) state.platform = data.platform;
      return true;
    } catch (e) {
      showGate('خطای شبکه در احراز هویت.');
      return false;
    }
  }

  /* ------------------------------ تعاملات بومی SDK (شماره تماس / مجوز پیام ایتا) ------------------------------ */
  function extractPhoneFromCallbackArg(arg) {
    if (!arg) return '';
    if (typeof arg === 'string') {
      try {
        const parsed = JSON.parse(arg);
        return extractPhoneFromCallbackArg(parsed);
      } catch (e) {
        const params = new URLSearchParams(arg);
        if (params.get('contact')) return extractPhoneFromCallbackArg(params.get('contact'));
        const m = arg.match(/\+?\d{10,15}/);
        return m ? m[0] : '';
      }
    }
    if (typeof arg === 'object') {
      if (arg.phone_number) return String(arg.phone_number);
      if (arg.phone) return String(arg.phone);
      if (arg.responseUnsafe && arg.responseUnsafe.contact && arg.responseUnsafe.contact.phone_number) {
        return String(arg.responseUnsafe.contact.phone_number);
      }
      if (arg.contact && arg.contact.phone_number) return String(arg.contact.phone_number);
      if (arg.response) return extractPhoneFromCallbackArg(arg.response);
    }
    return '';
  }

  function triggerRequestContact() {
    if (!sdk || typeof sdk.requestContact !== 'function') {
      toast('ثبت شماره تماس را از دکمه «ارسال شماره» در گفتگوی ربات انجام دهید.', 'info');
      return;
    }
    try {
      sdk.requestContact(async function (status, payload) {
        if (status === false) {
          toast('اشتراک‌گذاری شماره لغو شد.', 'info');
          return;
        }
        const phone = extractPhoneFromCallbackArg(payload) || extractPhoneFromCallbackArg(status);
        if (phone) {
          const res = await api('phone', { method: 'POST', body: { phone: phone } });
          toast(res.message || res.error || '', res.ok ? 'success' : 'error');
          if (res.ok) loadDashboard();
        } else {
          // در تلگرام و بله شماره به‌صورت پیام contact به وبهوک ربات نیز ارسال می‌شود
          toast('شماره تماس ارسال شد؛ در حال به‌روزرسانی وضعیت…', 'success');
          setTimeout(loadDashboard, 1800);
        }
      });
    } catch (e) {
      toast('امکان درخواست خودکار شماره در این نسخه پیام‌رسان نیست؛ از ربات استفاده کنید.', 'info');
    }
  }

  function triggerRequestWriteAccess() {
    if (!sdk || typeof sdk.requestWriteAccess !== 'function') return;
    try {
      sdk.requestWriteAccess(async function (allowed) {
        if (allowed) {
          await api('write-access', { method: 'POST', body: { allowed: 1 } });
          toast('دریافت اعلان در پیام‌رسان فعال شد!', 'success');
          loadDashboard();
        }
      });
    } catch (e) {}
  }

  /* ------------------------------ استودیوی دعوت ۴ حالته ------------------------------ */
  function renderInviteStudio() {
    const d = state.profile;
    const studio = (d && d.invite_studio) || {};
    const messages = Array.isArray(studio.messages) ? studio.messages : [];
    const banners = Array.isArray(studio.banners) ? studio.banners : [];
    const primaryLink = studio.primary_link || (d && d.user && d.user.invite_link) || '';
    const landingLink = studio.landing_link || '';
    const platformLinks = Array.isArray(studio.platform_links) ? studio.platform_links : [];

    if (state.studioMsgIdx >= messages.length) state.studioMsgIdx = 0;
    if (state.studioBannerIdx >= banners.length) state.studioBannerIdx = 0;

    // اگر بنری موجود نیست و کاربر حالت بنری را زد، به حالت پیام برگرد
    if ((state.studioMode === 'banner_caption' || state.studioMode === 'banner_link') && !banners.length) {
      state.studioMode = 'message';
    }

    $$('[data-studio-mode]').forEach(function (b) {
      b.classList.toggle('active', b.getAttribute('data-studio-mode') === state.studioMode);
    });

    const msgPicker = $('#studio-msg-picker');
    const bannerPicker = $('#studio-banner-picker');
    const previewWrap = $('#studio-preview-wrap');
    const previewImg = $('#studio-preview-img');
    const previewText = $('#studio-preview-text');
    const mainActions = $('#studio-main-actions');
    const linksList = $('#studio-links-list');
    const sendBotBtn = $('#studio-send-bot-btn');
    const dlBanner = $('#studio-download-banner');

    const isLinksMode = state.studioMode === 'links';
    const isMsgMode = state.studioMode === 'message';
    const isBannerCap = state.studioMode === 'banner_caption';
    const isBannerLink = state.studioMode === 'banner_link';

    if (msgPicker) msgPicker.classList.toggle('hidden', !isMsgMode || messages.length <= 1);
    if (bannerPicker) bannerPicker.classList.toggle('hidden', !(isBannerCap || isBannerLink));
    if (previewWrap) previewWrap.classList.toggle('hidden', isLinksMode);
    if (mainActions) mainActions.classList.toggle('hidden', isLinksMode);
    if (linksList) linksList.classList.toggle('hidden', !isLinksMode);

    if (isMsgMode) {
      const pills = $('#studio-msg-pills');
      if (pills) {
        pills.innerHTML = messages.map(function (m, i) {
          return '<button type="button" class="studio-pill' + (i === state.studioMsgIdx ? ' active' : '') +
            '" data-msg-idx="' + i + '">' + esc(m.title) + '</button>';
        }).join('');
      }
      const curMsg = messages[state.studioMsgIdx] || { text: ensureLinkInText(state.inviteText, primaryLink) };
      if (previewImg) previewImg.classList.add('hidden');
      if (previewText) previewText.textContent = ensureLinkInText(curMsg.text, primaryLink);
      if (sendBotBtn) sendBotBtn.classList.add('hidden');
      if (dlBanner) dlBanner.classList.add('hidden');
    } else if (isBannerCap || isBannerLink) {
      const grid = $('#studio-banner-grid');
      if (grid) {
        grid.innerHTML = banners.map(function (b, i) {
          return '<div class="studio-banner-card' + (i === state.studioBannerIdx ? ' active' : '') +
            '" data-banner-idx="' + i + '">' +
            '<img src="' + esc(b.image_url) + '" alt="' + esc(b.title) + '" loading="lazy">' +
            '<div class="studio-banner-title">' + esc(b.title) + '</div></div>';
        }).join('');
      }
      const curBanner = banners[state.studioBannerIdx];
      if (curBanner) {
        if (previewImg) {
          previewImg.src = curBanner.image_url;
          previewImg.classList.remove('hidden');
        }
        const rawCap = isBannerCap ? curBanner.caption : curBanner.short_caption;
        if (previewText) previewText.textContent = ensureLinkInText(rawCap, primaryLink);
        if (sendBotBtn) {
          sendBotBtn.classList.toggle('hidden', !studio.supports_bot_send);
        }
        if (dlBanner) {
          dlBanner.href = curBanner.image_url;
          dlBanner.classList.remove('hidden');
        }
      }
    } else if (isLinksMode && linksList) {
      let html = '';
      if (landingLink) {
        html += '<div class="studio-link-row">' +
          '<div style="min-width:0"><div class="text-xs font-bold">🌐 لینک هوشمند چندپیام‌رسانی (پیشنهادی)</div>' +
          '<div class="studio-link-url ltr">' + esc(landingLink) + '</div></div>' +
          '<button type="button" class="btn btn-sm" data-copy-url="' + esc(landingLink) + '">📋 کپی</button></div>';
      }
      platformLinks.forEach(function (pl) {
        if (!pl.link) return;
        html += '<div class="studio-link-row">' +
          '<div style="min-width:0"><div class="text-xs font-bold">' + esc((pl.icon || '🤖') + ' ' + pl.name) + '</div>' +
          '<div class="studio-link-url ltr">' + esc(pl.link) + '</div></div>' +
          '<button type="button" class="btn btn-secondary btn-sm" data-copy-url="' + esc(pl.link) + '">📋 کپی</button></div>';
        if (pl.miniapp_link) {
          html += '<div class="studio-link-row">' +
            '<div style="min-width:0"><div class="text-xs font-bold">' + esc((pl.icon || '📱') + ' مینی‌اپ مستقیم ' + pl.name) + '</div>' +
            '<div class="studio-link-url ltr">' + esc(pl.miniapp_link) + '</div></div>' +
            '<button type="button" class="btn btn-ghost btn-sm" data-copy-url="' + esc(pl.miniapp_link) + '">📋 کپی</button></div>';
        }
      });
      if (!html && primaryLink) {
        html = '<div class="studio-link-row"><div class="studio-link-url ltr">' + esc(primaryLink) + '</div>' +
          '<button type="button" class="btn btn-sm" data-copy-url="' + esc(primaryLink) + '">📋 کپی</button></div>';
      }
      linksList.innerHTML = html;
    }
  }

  function currentStudioSelection() {
    const d = state.profile || {};
    const studio = d.invite_studio || {};
    const primaryLink = studio.primary_link || (d.user && d.user.invite_link) || '';
    const messages = Array.isArray(studio.messages) ? studio.messages : [];
    const banners = Array.isArray(studio.banners) ? studio.banners : [];

    if (state.studioMode === 'banner_caption' || state.studioMode === 'banner_link') {
      const b = banners[state.studioBannerIdx];
      const cap = b ? (state.studioMode === 'banner_caption' ? b.caption : b.short_caption) : primaryLink;
      return {
        type: 'banner',
        id: b ? b.id : 0,
        fullCaption: state.studioMode === 'banner_caption',
        text: ensureLinkInText(cap, primaryLink),
        shareText: cap,
        link: primaryLink
      };
    }
    const m = messages[state.studioMsgIdx];
    const txt = m ? m.text : (state.inviteText || primaryLink);
    return {
      type: 'message',
      id: m ? m.id : 0,
      fullCaption: true,
      text: ensureLinkInText(txt, primaryLink),
      shareText: m ? m.share_text : state.shareText,
      link: primaryLink
    };
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
      series: [{ name: 'دعوت روزانه', data: a.daily_14 || [], color: '#818cf8', fill: true }]
    });
  }

  function renderProfileInviteChart() {
    const a = state.profile && state.profile.analytics;
    const canvas = $('#mini-chart-invites');
    if (!a || !canvas || !window.RB || canvas.offsetParent === null) return;
    const is30 = state.inviteChartRange === 30;
    const labels = is30 ? (a.labels_30 || []) : (a.labels_14 || []);
    if (state.inviteChartMode === 'daily') {
      RB.barChart(canvas, {
        labels: labels,
        data: is30 ? (a.daily_30 || []) : (a.daily_14 || []),
        name: 'دعوت جدید',
        unit: 'نفر',
        color: '#818cf8'
      });
    } else {
      RB.lineChart(canvas, {
        labels: labels,
        unit: 'نفر',
        series: [{ name: 'مجموع دعوت‌های من', data: is30 ? (a.cumulative_30 || []) : (a.cumulative_14 || []), color: '#06b6d4', fill: true }]
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
      const indirect = Math.max(0, Number(d.counts.indirect_refs != null ? d.counts.indirect_refs : (d.counts.total_refs - d.counts.direct_refs)) || 0);
      RB.donutChart(qCanvas, {
        data: [
          { label: 'مستقیم تأییدشده (L0)', value: qual },
          { label: 'مستقیم در انتظار', value: pend },
          { label: 'لایه‌های غیرمستقیم', value: indirect }
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
        series: [{ name: 'درآمد روزانه', data: a.earnings_14 || [], color: '#10b981', fill: true }]
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
    const currentLevel = data.user_level && data.user_level.current;
    ['#mini-avatar-medal', '#prof-avatar-medal'].forEach(function (selector) {
      const medal = $(selector);
      if (!medal) return;
      medal.classList.toggle('hidden', !currentLevel);
      if (currentLevel) {
        medal.textContent = currentLevel.icon || '🏅';
        medal.title = (currentLevel.name || '') + (currentLevel.badge ? ' · ' + currentLevel.badge : '');
        if (/^#[0-9a-f]{6}$/i.test(currentLevel.color || '')) medal.style.borderColor = currentLevel.color;
      }
    });
    setText('#prof-name', u.name || 'کاربر');
    setText('#prof-meta', (u.username ? '@' + u.username + ' · ' : '') + 'شناسه: ' + faNum(u.external_id || u.telegram_id));
    setText('#prof-tier-badge', tier.icon + ' ' + tier.name + (tier.badge ? ' · ' + tier.badge : ''));
    const tierBadge = $('#prof-tier-badge');
    if (tierBadge && /^#[0-9a-f]{6}$/i.test(tier.color || '')) tierBadge.style.color = tier.color;
    setText('#prof-tier-benefits', tier.benefits || '');
    setText('#prof-code-badge', 'کد دعوت: ' + (u.referral_code || '—'));
    setText('#prof-joined-date', u.joined_date_fa ? 'عضویت: ' + u.joined_date_fa : '');

    setText('#prof-tier-next', tier.next_name
      ? (faFormat(tier.remaining) + ' دعوت دیگر تا سطح «' + tier.next_name + '»')
      : 'تبریک! در بالاترین سطح دعوت‌کنندگان قرار دارید');
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
    const indirect = Math.max(0, Number(data.counts.indirect_refs != null ? data.counts.indirect_refs : (data.counts.total_refs - data.counts.direct_refs)) || 0);
    setText('#leg-indirect', faFormat(indirect) + ' نفر');

    setText('#earn-direct', money(eb.direct || 0));
    setText('#earn-welcome', money(eb.welcome || 0));
    setText('#earn-layer', money(eb.layer || 0));
    setText('#earn-campaign', money(eb.campaign || 0));

    // نمایش پیام‌رسان‌های متصل
    const linkedHost = $('#linked-messengers-list');
    if (linkedHost) {
      const list = Array.isArray(data.connected_messengers) ? data.connected_messengers : [];
      if (!list.length) {
        linkedHost.innerHTML = '<span class="badge badge-info no-dot">' + esc((data.platform && data.platform.label) || 'تلگرام') + '</span>';
      } else {
        linkedHost.innerHTML = '<div class="flex gap-1" style="flex-wrap:wrap">' + list.map(function (m) {
          return '<span class="badge badge-' + (m.is_primary ? 'brand' : 'success') + ' no-dot">' +
            esc(m.label) + (m.username ? ' (@' + esc(m.username) + ')' : '') +
            (m.is_primary ? ' · اصلی' : '') + '</span>';
        }).join('') + '</div>';
      }
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
    if (data.platform && data.platform.code) state.platform = data.platform.code;
    const u = data.user;
    const plat = data.platform || {};

    $('#mini-content').classList.remove('hidden');
    $('#auth-gate').classList.add('hidden');
    $('#mini-avatar').textContent = (u.name || '؟').trim().charAt(0) || '؟';
    $('#mini-name').textContent = u.name || 'کاربر';
    $('#mini-sub').textContent = u.username ? '@' + u.username : 'شناسه ' + faNum(u.external_id || u.telegram_id);

    const pBadge = $('#mini-platform-badge');
    if (pBadge && plat.label) {
      pBadge.textContent = plat.label;
      pBadge.classList.remove('hidden');
    }

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
    if (link) link.textContent = u.invite_link || u.gateway_link || u.referral_code;

    const rw = data.referral_rewards || {};
    state.shareText = rw.share_text || '';
    state.inviteText = rw.invite_text || '';
    const rwNote = $('#invite-rewards-note');
    if (rwNote) {
      const parts = [];
      if (rw.inviter_reward > 0) parts.push('🎯 پاداش مستقیم شما: <b>' + esc(rw.inviter_reward_fa) + '</b>');
      if (rw.invitee_reward > 0) parts.push('🎁 هدیه دوستتان: <b>' + esc(rw.invitee_reward_fa) + '</b>');
      if (rw.multilevel_enabled && rw.max_levels > 0) parts.push('🕸 پورسانت تا لایه ' + faNum(rw.max_levels));
      if (parts.length) {
        rwNote.innerHTML = parts.join(' · ');
        rwNote.classList.remove('hidden');
      } else {
        rwNote.classList.add('hidden');
      }
    }

    // راهنمای تعاملی تکمیل شرایط (شماره تماس / کانال اجباری / مجوز پیام ایتا)
    const guideBox = $('#mini-welcome-guide');
    const guideBody = $('#mini-welcome-guide-body');
    const gateActions = $('#mini-gate-actions');
    if (guideBox && guideBody && gateActions) {
      const pendingList = Array.isArray(rw.pending_requirements) ? rw.pending_requirements : [];
      const reqChannels = Array.isArray(rw.required_channels) ? rw.required_channels : [];
      const needPhone = !!rw.need_phone;
      const needWrite = !!plat.can_request_write && !plat.can_send_message && sdk && typeof sdk.requestWriteAccess === 'function';

      if ((u.referred_by && !u.qualified && pendingList.length > 0) || needPhone || reqChannels.length > 0 || needWrite) {
        let html = '';
        if (u.referred_by && !u.qualified && rw.invitee_reward > 0) {
          html += '<b>هدیه خوش‌آمدگویی ' + esc(rw.invitee_reward_fa) + ' در انتظار شماست!</b><br>';
        }
        if (pendingList.length > 0) {
          html += pendingList.map(s => '• ' + esc(s)).join('<br>');
        } else if (needWrite) {
          html += 'برای دریافت اعلان آنی پاداش‌ها و برداشت‌ها در ایتا، مجوز پیام را فعال کنید.';
        }
        guideBody.innerHTML = html;

        let btns = '';
        if (needPhone && plat.can_request_contact) {
          btns += '<button type="button" class="btn btn-sm" id="btn-sdk-phone">📱 ثبت شماره تماس</button>';
        }
        reqChannels.forEach(function (ch) {
          if (ch.join_link) {
            const crossTag = ch.is_cross_platform && ch.platform_badge ? ' (' + esc(ch.platform_badge) + ')' : '';
            btns += '<button type="button" class="btn btn-secondary btn-sm" data-open-link="' + esc(ch.join_link) + '">📢 عضویت در ' + esc(ch.title) + crossTag + '</button>';
          }
        });
        if (reqChannels.length > 0) {
          const allSelf = reqChannels.every(c => c.self_confirm);
          const needCrossLink = reqChannels.some(c => c.needs_account_link);
          btns += '<button type="button" class="btn btn-success btn-sm" id="btn-verify-channels" data-self="' + (allSelf ? '1' : '0') + '">' +
            (needCrossLink ? '🔗 اتصال حساب و بررسی عضویت' : (allSelf ? '✅ عضو شدم (ثبت)' : '✅ بررسی عضویت')) + '</button>';
        }
        if (needWrite) {
          btns += '<button type="button" class="btn btn-ghost btn-sm" id="btn-sdk-write">🔔 فعال‌سازی اعلان</button>';
        }
        gateActions.innerHTML = btns;
        guideBox.classList.remove('hidden');
      } else {
        guideBox.classList.add('hidden');
      }
    }

    const openBot = $('#open-bot');
    if (openBot && u.invite_link) {
      openBot.href = u.invite_link.split('?')[0];
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
    renderInviteStudio();
    renderTx('#home-tx', data.recent_transactions || [], 5);
    renderCampaigns(data.campaigns || []);

    const pf = $('#profile-form');
    if (pf) {
      pf.first_name.value = u.first_name !== undefined ? u.first_name : '';
      pf.last_name.value = u.last_name !== undefined ? u.last_name : '';
    }

    requestAnimationFrame(function () {
      if (state.activeTab === 'home') renderHomeChart();
      else if (state.activeTab === 'profile') renderProfileCharts();
      else if (state.activeTab === 'referrals') renderReferralsBarChart();
    });
  }

  function renderTx(hostSel, rows, limit) {
    const hostEl = $(hostSel);
    if (!hostEl) return;
    if (!rows.length) { emptyList(hostEl, 'تراکنشی ثبت نشده', 'با دعوت دوستان، پاداش‌ها در این بخش ثبت می‌شود.'); return; }
    hostEl.innerHTML = '';
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
      hostEl.appendChild(row);
    });
  }

  function renderCampaigns(list) {
    const hostEl = $('#home-campaigns');
    if (!hostEl) return;
    if (!list.length) { emptyList(hostEl, 'جشنواره فعالی وجود ندارد', 'جشنواره‌های پاداش دعوت در این بخش نمایش داده می‌شوند.'); return; }
    hostEl.innerHTML = '';
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
      hostEl.appendChild(row);
    });
  }

  /* ------------------------------ کیف پول ------------------------------ */
  async function loadTransactions(reset) {
    if (reset) { state.txPage = 1; }
    const hostEl = $('#wallet-tx');
    if (reset) loadingList(hostEl);
    const data = await api('transactions?page=' + state.txPage + (state.txType ? '&type=' + encodeURIComponent(state.txType) : ''));
    if (!data.ok) { toast(data.error || 'خطا', 'error'); return; }
    if (reset) hostEl.innerHTML = '';
    if (!data.rows.length && reset) { emptyList(hostEl, 'تراکنشی یافت نشد'); }
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
      hostEl.appendChild(row);
    });
    const more = $('#load-more-tx');
    if (more) more.style.display = state.txPage < data.pages ? '' : 'none';
  }

  /* ------------------------------ زیرمجموعه‌ها و لایه‌ها ------------------------------ */
  async function loadReferrals() {
    requestAnimationFrame(renderReferralsBarChart);
    const hostEl = $('#refs-list');
    loadingList(hostEl);
    const data = await api('referrals');
    if (!data.ok) { toast(data.error || 'خطا', 'error'); return; }

    const sumEl = $('#refs-summary');
    if (sumEl) {
      sumEl.textContent = faFormat(data.totals.direct) + ' مستقیم (L0) · ' +
        faFormat(data.totals.indirect || 0) + ' غیرمستقیم (L1+)';
    }

    if (!data.direct.length) {
      emptyList(hostEl, 'هنوز زیرمجموعه مستقیمی ندارید', 'از بخش «دعوت و بنر» پیام یا بنر اختصاصی خود را برای دوستان بفرستید!');
    } else {
      hostEl.innerHTML = '';
      data.direct.forEach(function (r) {
        const row = el('div', 'mini-row');
        row.innerHTML =
          '<div class="mini-row-icon in">' + esc((r.name || '؟').charAt(0)) + '</div>' +
          '<div class="mini-row-main">' +
            '<div class="mini-row-title">' + esc(r.name) + '</div>' +
            '<div class="mini-row-sub">' +
              esc(r.platform_label || 'تلگرام') +
              (r.username ? ' · @' + esc(r.username) : '') +
              ' · ' + esc(r.joined_at) +
              (r.refs > 0 ? ' · ' + faFormat(r.refs) + ' دعوت' : '') +
            '</div></div>' +
          '<span class="badge badge-' + (r.qualified ? 'success' : 'warning') + '">' + (r.qualified ? 'تأییدشده' : 'در انتظار') + '</span>';
        hostEl.appendChild(row);
      });
    }

    // نمایش لایه ۰ و لایه‌های ۱+ همراه با درآمد هر لایه
    const bars = $('#levels-bars');
    if (bars) {
      if (!data.levels.length) {
        bars.innerHTML = '<div class="text-sm text-muted text-center" style="padding:8px 0">با اولین دعوت موفق، تفکیک لایه‌های شبکه و پورسانت هر لایه اینجا نمایش داده می‌شود.</div>';
      } else {
        const max = Math.max.apply(null, data.levels.map(l => l.count));
        bars.innerHTML = data.levels.map(function (l) {
          const pct = max > 0 ? Math.max(6, Math.round(l.count / max * 100)) : 0;
          const lbl = l.label || (l.layer === 0 ? 'لایه ۰ — دعوت مستقیم' : ('لایه ' + faNum(l.layer) + ' — غیرمستقیم'));
          return '<div class="mb-2"><div class="flex justify-between text-xs mb-1" style="gap:6px;flex-wrap:wrap">' +
            '<span class="font-bold">' + esc(lbl) + '</span>' +
            '<span><strong>' + faFormat(l.count) + ' نفر</strong>' +
              (l.earned > 0 ? ' · <span style="color:var(--success-text)">' + esc(l.earned_fa) + '</span>' : '') +
            '</span></div>' +
            '<div class="mini-meter"><span style="width:' + pct + '%"></span></div></div>';
        }).join('');
      }
    }

    // آخرین زیرمجموعه‌های غیرمستقیم
    const indCard = $('#indirect-card');
    const indList = $('#indirect-list');
    if (indCard && indList) {
      const indRows = Array.isArray(data.indirect) ? data.indirect : [];
      if (indRows.length > 0) {
        indCard.classList.remove('hidden');
        indList.innerHTML = '';
        indRows.forEach(function (r) {
          const row = el('div', 'mini-row');
          row.innerHTML =
            '<div class="mini-row-icon">🕸</div>' +
            '<div class="mini-row-main">' +
              '<div class="mini-row-title">' + esc(r.name) + ' <span class="badge badge-brand no-dot">' + esc(r.layer_label) + '</span></div>' +
              '<div class="mini-row-sub">' + esc(r.platform_label) + ' · ' + esc(r.joined_at) + '</div></div>' +
            '<span class="badge badge-' + (r.qualified ? 'success' : 'warning') + '">' + (r.qualified ? 'تأییدشده' : 'در انتظار') + '</span>';
          indList.appendChild(row);
        });
      } else {
        indCard.classList.add('hidden');
      }
    }
  }

  /* ------------------------------ برداشت ------------------------------ */
  async function loadWithdrawals() {
    const hostEl = $('#withdraw-list');
    loadingList(hostEl);
    const data = await api('withdrawals');
    if (!data.ok) { toast(data.error || 'خطا', 'error'); return; }
    if (!data.rows.length) { emptyList(hostEl, 'درخواستی ثبت نکرده‌اید'); return; }
    hostEl.innerHTML = '';
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
      hostEl.appendChild(row);
    });
  }

  /* ------------------------------ رتبه و اعلان ------------------------------ */
  async function loadRanking() {
    const hostEl = $('#ranking-list');
    loadingList(hostEl);
    const data = await api('ranking');
    if (!data.ok) { toast(data.error || 'خطا', 'error'); return; }
    if (!data.enabled) { emptyList(hostEl, 'رتبه‌بندی غیرفعال است'); return; }
    if (!data.rows.length) { emptyList(hostEl, 'داده‌ای برای رتبه‌بندی وجود ندارد'); return; }
    hostEl.innerHTML = '';
    const medals = { 1: '🥇', 2: '🥈', 3: '🥉' };
    data.rows.forEach(function (r) {
      const row = el('div', 'mini-row' + (r.is_me ? ' highlight' : ''));
      row.innerHTML =
        '<div class="mini-row-icon in">' + (medals[r.rank] || faNum(r.rank)) + '</div>' +
        '<div class="mini-row-main"><div class="mini-row-title">' + esc(r.name) +
          (r.is_me ? ' <span class="badge badge-brand no-dot">شما</span>' : '') + '</div>' +
          '<div class="mini-row-sub">' + (r.username ? '@' + esc(r.username) : '') + '</div></div>' +
        '<div class="mini-row-amount">' + faNum(r.value_fa) + '</div>';
      hostEl.appendChild(row);
    });
  }

  async function loadNotifications() {
    const hostEl = $('#notifications-list');
    loadingList(hostEl);
    const data = await api('notifications');
    if (!data.ok) { toast(data.error || 'خطا', 'error'); return; }
    if (!data.rows.length) { emptyList(hostEl, 'اعلانی وجود ندارد'); return; }
    hostEl.innerHTML = '';
    data.rows.forEach(function (n) {
      const row = el('div', 'mini-row' + (!n.read ? ' unread' : ''));
      row.innerHTML =
        '<div class="mini-row-main"><div class="mini-row-title">' + esc(n.title) + '</div>' +
        '<div class="mini-row-sub" style="white-space:pre-wrap">' + esc(n.body) + '</div>' +
        '<div class="mini-row-sub">' + esc(n.created_at) + '</div></div>' +
        (!n.read ? '<span class="badge badge-info no-dot">جدید</span>' : '');
      hostEl.appendChild(row);
    });
  }

  /* ------------------------------ تب‌ها ------------------------------ */
  function activateTab(name) {
    state.activeTab = name;
    $$('.mini-tab').forEach(t => t.classList.toggle('active', t.getAttribute('data-tab') === name));
    $$('.mini-panel').forEach(p => p.classList.toggle('hidden', p.getAttribute('data-panel') !== name));
    if (name === 'home') requestAnimationFrame(renderHomeChart);
    if (name === 'invite') renderInviteStudio();
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

    // استودیوی دعوت
    const sMode = e.target.closest('[data-studio-mode]');
    if (sMode) {
      state.studioMode = sMode.getAttribute('data-studio-mode');
      renderInviteStudio();
      haptic();
      return;
    }
    const msgPill = e.target.closest('[data-msg-idx]');
    if (msgPill) {
      state.studioMsgIdx = parseInt(msgPill.getAttribute('data-msg-idx'), 10) || 0;
      renderInviteStudio();
      haptic();
      return;
    }
    const banCard = e.target.closest('[data-banner-idx]');
    if (banCard) {
      state.studioBannerIdx = parseInt(banCard.getAttribute('data-banner-idx'), 10) || 0;
      renderInviteStudio();
      haptic();
      return;
    }
    const copyUrlBtn = e.target.closest('[data-copy-url]');
    if (copyUrlBtn) {
      copyText(copyUrlBtn.getAttribute('data-copy-url'), 'لینک دعوت کپی شد!');
      return;
    }
    if (e.target.closest('#studio-copy-text-btn')) {
      const sel = currentStudioSelection();
      copyText(sel.text, 'متن دعوت همراه لینک اختصاصی شما کپی شد!');
      if (sel.id > 0) api('invite/track', { method: 'POST', body: { type: sel.type, id: sel.id } });
      return;
    }
    if (e.target.closest('#studio-share-btn')) {
      const sel = currentStudioSelection();
      shareInviteContent(sel.text, sel.link, sel.shareText);
      if (sel.id > 0) api('invite/track', { method: 'POST', body: { type: sel.type, id: sel.id } });
      return;
    }
    if (e.target.closest('#studio-send-bot-btn')) {
      const sel = currentStudioSelection();
      const btn = $('#studio-send-bot-btn');
      const old = btn.textContent;
      btn.disabled = true; btn.textContent = 'در حال ارسال بنر…';
      api('invite/send-banner', { method: 'POST', body: { banner_id: sel.id, full_caption: sel.fullCaption ? 1 : 0 } })
        .then(function (r) {
          btn.disabled = false; btn.textContent = old;
          toast(r.message || r.error || '', r.ok ? 'success' : 'error');
        });
      return;
    }

    // تکمیل شرایط تعاملی
    if (e.target.closest('#btn-sdk-phone')) { triggerRequestContact(); return; }
    if (e.target.closest('#btn-sdk-write')) { triggerRequestWriteAccess(); return; }
    const openLinkBtn = e.target.closest('[data-open-link]');
    if (openLinkBtn) { openExternalOrMessengerLink(openLinkBtn.getAttribute('data-open-link')); return; }
    const verBtn = e.target.closest('#btn-verify-channels');
    if (verBtn) {
      const isSelf = verBtn.getAttribute('data-self') === '1';
      verBtn.disabled = true;
      api('channels/verify', { method: 'POST', body: { self_confirm: isSelf ? 1 : 0 } }).then(function (r) {
        verBtn.disabled = false;
        if (!r.ok && r.needs_link && r.link_prompt) {
          const lp = r.link_prompt;
          const guideBody = $('#mini-welcome-guide-body');
          const gateActions = $('#mini-gate-actions');
          if (guideBody && lp.code) {
            guideBody.innerHTML =
              '<b>🔗 اتصال حساب ' + esc(lp.badge || '') + ' برای تأیید رسمی عضویت</b><br>' +
              esc(r.error || '') +
              '<div class="mt-1">کد اتصال یک‌بارمصرف: <code class="ltr">' + esc(lp.code) + '</code></div>';
          }
          if (gateActions && lp.deep_link && !gateActions.querySelector('[data-cross-link]')) {
            gateActions.insertAdjacentHTML(
              'afterbegin',
              '<button type="button" class="btn btn-sm" data-cross-link="1" data-open-link="' + esc(lp.deep_link) + '">🔗 اتصال آنی در ' + esc(lp.badge || 'ربات') + '</button>'
            );
          }
          toast(r.error || 'ابتدا حساب پیام‌رسان مقصد را متصل کنید.', 'error');
          return;
        }
        toast(r.message || r.error || '', r.ok ? 'success' : 'error');
        if (r.ok) loadDashboard();
      });
      return;
    }

    // اتصال پیام‌رسان
    if (e.target.closest('#btn-gen-link-token')) {
      const btn = $('#btn-gen-link-token');
      btn.disabled = true;
      api('link-token', { method: 'POST', body: {} }).then(function (r) {
        btn.disabled = false;
        if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
        const box = $('#link-token-output');
        if (box) {
          box.innerHTML = '<div class="text-xs text-muted mb-1">کد اتصال یک‌بارمصرف (معتبر تا ۱۵ دقیقه):</div>' +
            '<div class="ltr mono font-bold" style="font-size:1.1rem;margin-bottom:6px">' + esc(r.token) + '</div>' +
            '<button type="button" class="btn btn-sm" data-copy-url="' + esc(r.token) + '">📋 کپی کد</button>';
          box.classList.remove('hidden');
        }
        toast(r.message || 'کد ساخته شد', 'success');
      });
      return;
    }

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
      copyText($('#invite-link').textContent.trim(), 'لینک دعوت کپی شد!');
      return;
    }
    if (e.target.closest('#share-invite')) {
      const link = $('#invite-link').textContent.trim();
      shareInviteContent(state.inviteText || state.shareText, link, state.shareText);
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

  const IR_BANKS = {
    '603799':'بانک ملی ایران','589210':'بانک سپه','627648':'بانک توسعه صادرات','207177':'بانک توسعه صادرات',
    '627961':'بانک صنعت و معدن','603770':'بانک کشاورزی','639217':'بانک کشاورزی','628023':'بانک مسکن',
    '627760':'پست بانک ایران','502908':'بانک توسعه تعاون','627412':'بانک اقتصاد نوین','622106':'بانک پارسیان',
    '627884':'بانک پارسیان','639194':'بانک پارسیان','502229':'بانک پاسارگاد','639347':'بانک پاسارگاد',
    '627488':'بانک کارآفرین','502910':'بانک کارآفرین','621986':'بانک سامان','639346':'بانک سینا',
    '639607':'بانک سرمایه','502806':'بانک شهر','504706':'بانک شهر','502938':'بانک دی',
    '603769':'بانک صادرات ایران','610433':'بانک ملت','991975':'بانک ملت','627353':'بانک تجارت',
    '585983':'بانک تجارت','589463':'بانک رفاه کارگران','627381':'بانک انصار (سپه)','639370':'بانک مهر اقتصاد (سپه)',
    '639599':'بانک قوامین (سپه)','636949':'بانک حکمت ایرانیان (سپه)','505801':'موسسه کوثر (سپه)',
    '505785':'بانک ایران‌زمین','505416':'بانک گردشگری','636214':'بانک آینده','504172':'بانک قرض‌الحسنه رسالت',
    '606373':'بانک قرض‌الحسنه مهر ایران','585947':'بانک خاورمیانه','505809':'بانک خاورمیانه',
    '585848':'بانک ایران و ونزوئلا','636795':'بانک مرکزی','606256':'موسسه ملل','507677':'موسسه نور','628157':'موسسه توسعه'
  };

  function detectCardBank(digits) {
    if (!digits || digits.length < 6) return '';
    if (digits.slice(0, 8) === '62198619') return 'بلوبانک (بانک سامان)';
    return IR_BANKS[digits.slice(0, 6)] || '';
  }

  function passesLuhn16(digits) {
    if (!/^\d{16}$/.test(digits)) return false;
    let sum = 0, dbl = false;
    for (let i = 15; i >= 0; i--) {
      let d = digits.charCodeAt(i) - 48;
      if (dbl) { d *= 2; if (d > 9) d -= 9; }
      sum += d;
      dbl = !dbl;
    }
    return (sum % 10) === 0;
  }

  function cleanCardText(raw) {
    return enNum(raw || '')
      .replace(/[\u00AD\u061C\u180E\u200B-\u200F\u202A-\u202E\u2060-\u206F\uFEFF]/g, '')
      .replace(/[\u00A0\u2000-\u200A\u202F\u205F\u3000]/g, ' ')
      .trim();
  }

  function validateCardClient(raw) {
    const clean = cleanCardText(raw);
    if (!clean) return { valid: false, error: 'لطفاً شماره کارت ۱۶ رقمی خود را وارد کنید.' };
    if (/^IR[\s\-._]*\d{6,}/i.test(clean)) return { valid: false, error: 'شماره شبا (IR) وارد شده است. لطفاً شماره کارت ۱۶ رقمی روی کارت بانکی را وارد کنید.' };
    if (/[*×✕✖•●▪]|X{2,}/i.test(clean)) return { valid: false, error: 'شماره کارت به‌صورت ستاره‌دار (ماسک‌شده) وارد شده است. لطفاً هر ۱۶ رقم را کامل وارد کنید.' };
    if (/[^0-9\s\-‐‑‒–—―−﹣－ـ._/,،٬٫·:]/.test(clean)) return { valid: false, error: 'شماره کارت فقط باید شامل ارقام باشد (فاصله و خط تیره مجاز است).' };
    const gm = clean.match(/^(\d{4})[\s\-‐‑‒–—―−﹣－ـ._/,،٬٫·:]+(\d{4})[\s\-‐‑‒–—―−﹣－ـ._/,،٬٫·:]+(\d{4})[\s\-‐‑‒–—―−﹣－ـ._/,،٬٫·:]+(\d{4})$/);
    if (gm) {
      const fwd = gm[1] + gm[2] + gm[3] + gm[4];
      const revG = gm[4] + gm[3] + gm[2] + gm[1];
      if (!detectCardBank(fwd) && detectCardBank(revG) && passesLuhn16(revG)) {
        return { valid: false, error: 'ترتیب ۴تایی ارقام کارت برعکس وارد شده است. لطفاً از چپ به راست (شروع با ' + faNum(gm[4]) + ') وارد کنید.' };
      }
    }
    const digits = clean.replace(/[^0-9]/g, '');
    if (!digits) return { valid: false, error: 'لطفاً شماره کارت ۱۶ رقمی خود را وارد کنید.' };
    if (digits.length < 16) return { valid: false, error: 'تعداد ارقام شماره کارت کم است (' + faNum(digits.length) + ' رقم وارد شده؛ باید دقیقاً ۱۶ رقم باشد).' };
    if (digits.length > 16) return { valid: false, error: 'تعداد ارقام شماره کارت بیش از حد مجاز است (' + faNum(digits.length) + ' رقم وارد شده؛ باید دقیقاً ۱۶ رقم باشد).' };
    if ('0178'.indexOf(digits[0]) !== -1 || /^(\d)\1{15}$/.test(digits) || digits.slice(1, 11) === '0000000000' || digits.slice(10, 16) === '000000') {
      return { valid: false, error: 'ساختار شماره کارت معتبر نیست. لطفاً یک شماره کارت بانکی ۱۶ رقمی معتبر وارد کنید.' };
    }
    if (!passesLuhn16(digits)) {
      return { valid: false, error: 'شماره کارت واردشده معتبر نیست (رقم کنترلی کارت صحیح نیست). لطفاً ۱۶ رقم روی کارت را دوباره بررسی کنید.' };
    }
    return { valid: true, digits: digits, bank: detectCardBank(digits) };
  }

  document.addEventListener('input', function (e) {
    const el = e.target;
    if (!el || el.id !== 'withdraw-card') return;
    const hint = $('#withdraw-card-hint');
    const clean = cleanCardText(el.value);
    // اگر فقط ارقام و فاصله/خط تیره وارد شده و ۱۶ رقم یا کمتر است، به‌صورت خوانا ۴تایی قالب‌بندی کن
    if (clean && /^[0-9\s\-‐‑‒–—―−ـ]+$/.test(clean)) {
      const onlyDigits = clean.replace(/[^0-9]/g, '');
      if (onlyDigits.length <= 16) {
        const grouped = onlyDigits.replace(/(\d{4})(?=\d)/g, '$1-');
        if (el.value !== grouped) el.value = grouped;
      }
    }
    if (!hint) return;
    if (!clean) {
      hint.textContent = 'ارقام فارسی یا انگلیسی، با فاصله یا خط تیره یا کپی مستقیم از همراه بانک پذیرفته می‌شود.';
      hint.style.color = '';
      return;
    }
    const digitsOnly = clean.replace(/[^0-9]/g, '');
    const bank = detectCardBank(digitsOnly);
    if (digitsOnly.length === 16 || /[^0-9\s\-‐‑‒–—―−﹣－ـ._/,،٬٫·:]/.test(clean)) {
      const check = validateCardClient(el.value);
      if (check.valid) {
        hint.textContent = '✅ شماره کارت معتبر است' + (check.bank ? ' · ' + check.bank : '');
        hint.style.color = 'var(--success, #16a34a)';
      } else {
        hint.textContent = '⚠️ ' + check.error;
        hint.style.color = 'var(--danger, #dc2626)';
      }
    } else if (bank) {
      hint.textContent = '🏦 ' + bank + ' · ' + faNum(digitsOnly.length) + ' از ۱۶ رقم';
      hint.style.color = '';
    } else {
      hint.textContent = faNum(digitsOnly.length) + ' از ۱۶ رقم وارد شده';
      hint.style.color = '';
    }
  });

  document.addEventListener('submit', async function (e) {
    const form = e.target;
    if (form.id === 'withdraw-form') {
      e.preventDefault();
      const data = formData(form);
      data.amount = enNum(data.amount || '').replace(/[^\d]/g, '');
      if (!data.amount) { toast('لطفاً مبلغ برداشت را وارد کنید.', 'error'); return; }
      const cardCheck = validateCardClient(data.card_number || '');
      if (!cardCheck.valid) { toast(cardCheck.error, 'error'); haptic('error'); return; }
      data.card_number = cardCheck.digits;
      const btn = $('#withdraw-submit');
      const original = btn.textContent;
      btn.disabled = true; btn.textContent = 'در حال ثبت…';
      const res = await api('withdraw', { method: 'POST', body: data });
      btn.disabled = false; btn.textContent = original;
      if (res.ok) {
        toast(res.message || 'ثبت شد', 'success');
        haptic('success');
        form.reset();
        const hint = $('#withdraw-card-hint');
        if (hint) { hint.textContent = 'ارقام فارسی یا انگلیسی، با فاصله یا خط تیره یا کپی مستقیم از همراه بانک پذیرفته می‌شود.'; hint.style.color = ''; }
        loadWithdrawals();
        loadDashboard();
      }
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
    if (form.id === 'redeem-link-form') {
      e.preventDefault();
      const res = await api('link-redeem', { method: 'POST', body: formData(form) });
      toast(res.message || res.error || '', res.ok ? 'success' : 'error');
      if (res.ok) { form.reset(); loadDashboard(); }
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
    if (sdk) {
      try { if (typeof sdk.ready === 'function') sdk.ready(); } catch (e) {}
      try { if (typeof sdk.expand === 'function') sdk.expand(); } catch (e) {}
      syncHostChrome();
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
