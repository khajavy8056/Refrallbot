/* ============================================================
   RefrallBot — هسته جاوااسکریپت (بدون وابستگی خارجی)
   ============================================================ */
(function () {
  'use strict';

  const RB = window.RB = window.RB || {};

  /* ------------------------------ ابزارها ------------------------------ */
  RB.enNum = function (value) {
    const map = {
      '۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9',
      '٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9',
      '０':'0','１':'1','２':'2','３':'3','４':'4','５':'5','６':'6','７':'7','８':'8','９':'9'
    };
    return String(value === null || value === undefined ? '' : value)
      .replace(/[\u00AD\u061C\u180E\u200B-\u200F\u202A-\u202E\u2060-\u206F\uFEFF]/g, '')
      .replace(/[۰-۹٠-٩０-９]/g, d => map[d] || d);
  };

  RB.faNum = function (value) {
    const fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return String(value === null || value === undefined ? '' : value)
      .replace(/\d/g, d => fa[+d]);
  };

  RB.money = function (amount, withUnit) {
    const n = Number(amount) || 0;
    const s = n.toLocaleString('en-US');
    return RB.faNum(s) + (withUnit === false ? '' : ' تومان');
  };

  RB.shortNum = function (n) {
    n = Number(n) || 0;
    if (n >= 1e9) return RB.faNum((n / 1e9).toFixed(1)) + ' میلیارد';
    if (n >= 1e6) return RB.faNum((n / 1e6).toFixed(1)) + ' میلیون';
    if (n >= 1e3) return RB.faNum((n / 1e3).toFixed(1)) + ' هزار';
    return RB.faNum(n);
  };

  RB.escape = function (str) {
    const div = document.createElement('div');
    div.textContent = str === null || str === undefined ? '' : String(str);
    return div.innerHTML;
  };

  RB.csrf = function () {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  };

  RB.toast = function (message, type, timeout) {
    let host = document.querySelector('.toasts');
    if (!host) {
      host = document.createElement('div');
      host.className = 'toasts';
      document.body.appendChild(host);
    }
    const icons = { success: '✓', error: '✕', warning: '!', info: 'i' };
    const el = document.createElement('div');
    el.className = 'toast ' + (type || 'info');
    el.innerHTML = '<span>' + (icons[type] || 'i') + '</span><div>' + RB.escape(message) + '</div>';
    host.appendChild(el);
    setTimeout(() => {
      el.classList.add('hide');
      setTimeout(() => el.remove(), 220);
    }, timeout || 4200);
  };

  /* ------------------------------ درخواست AJAX ------------------------------ */
  /*
   * نکات مهمی که در نسخهٔ قبلی رعایت نشده بود و باعث می‌شد خطاهای واقعی سرور
   * (مثل 422 نصب یا 429 محدودیت) به پیام مبهم «پاسخ نامعتبر» تبدیل شوند:
   *   • کد وضعیت HTTP بررسی نمی‌شد.
   *   • بدنهٔ غیر JSON (HTML خطا یا متن ساده) کامل دور ریخته می‌شد.
   *   • هیچ راهی برای دیدن متن واقعی پاسخ در عیب‌یابی نبود.
   *
   * حالا: بدنهٔ خام نگه‌داری می‌شود، متن HTML به متن خوانا تبدیل می‌شود،
   * و برای 429 پیام با زمان تلاش مجدد ساخته می‌شود. هیچ مقدار حساسی
   * (رمز دیتابیس، توکن CSRF) هرگز چاپ نمی‌شود.
   */
  RB.request = async function (url, options) {
    options = options || {};
    const token = RB.csrf();
    const isFormData = (typeof FormData !== 'undefined') && (options.body instanceof FormData);
    const isUrlParams = (typeof URLSearchParams !== 'undefined') && (options.body instanceof URLSearchParams);
    const headers = Object.assign(
      { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': token },
      options.headers || {}
    );
    if (isFormData) {
      // برای FormData هرگز Content-Type دستی ست نمی‌شود تا مرورگر boundary صحیح multipart را بگذارد
      delete headers['Content-Type'];
      delete headers['content-type'];
    } else if (isUrlParams) {
      headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
      options.body = options.body.toString();
    } else if (options.body !== undefined && options.body !== null) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }

    let response;
    try {
      response = await fetch(url, Object.assign({}, options, { headers, credentials: 'same-origin' }));
    } catch (err) {
      return { ok: false, code: 'network', error: 'خطای ارتباط با سرور. اتصال اینترنت یا آدرس سایت را بررسی کنید.' };
    }

    const status = response.status;
    const text = await response.text();
    const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
    let data = null;

    if (text) {
      try { data = JSON.parse(text); } catch (e) { data = null; }
    }

    // ---- پاسخ JSON معتبر
    if (data && typeof data === 'object') {
      if (status === 401 && data.redirect) { window.location.href = data.redirect; }
      if (status === 419) { data.restart = true; }
      if (!data.ok && !data.error) { data.error = RB.statusMessage(status); }
      data.status = status;
      return data;
    }

    // ---- پاسخ غیر JSON: متن خوانا بساز
    let readable = '';
    if (contentType.indexOf('text/html') !== -1) {
      const doc = new DOMParser().parseFromString(text, 'text/html');
      const body = doc.body ? (doc.body.textContent || '') : '';
      readable = body.replace(/\s+/g, ' ').trim().slice(0, 300);
    } else {
      readable = text.replace(/\s+/g, ' ').trim().slice(0, 300);
    }

    const message = RB.statusMessage(status, readable);
    // در حالت توسعه (پارامتر ?debug=1) جزئیات امن در کنسول ثبت می‌شود
    if (/[?&]debug=1\b/.test(location.search)) {
      console.warn('[RB] پاسخ غیر JSON', { url: url, status: status, contentType: contentType, excerpt: readable });
    }

    return {
      ok: false,
      status: status,
      code: 'non_json',
      error: message + (readable ? ' — ' + readable : ''),
      retry_after: parseInt(response.headers.get('Retry-After') || '0', 10) || null
    };
  };

  /* پیام قابل فهم برای کدهای خطای HTTP */
  RB.statusMessage = function (status, detail) {
    const retryAfter = arguments.length > 2 ? arguments[2] : null;
    switch (status) {
      case 401: return 'نشست شما منقضی شده است. دوباره وارد شوید.';
      case 403: return 'دسترسی به این عملیات مجاز نیست.';
      case 409: return 'این عملیات با وضعیت فعلی سیستم سازگار نیست.';
      case 419: return 'توکن امنیتی نامعتبر یا منقضی شده است. صفحه را دوباره بارگذاری کنید.';
      case 422: return detail ? 'اطلاعات وارد شده معتبر نیست: ' + detail : 'اطلاعات وارد شده معتبر نیست.';
      case 429: return 'تعداد درخواست‌ها بیش از حد مجاز است. لطفاً کمی صبر کنید و دوباره تلاش کنید.';
      case 500: return 'خطای داخلی سرور. اگر ادامه داشت با پشتیبانی تماس بگیرید.';
      case 502:
      case 503:
      case 504: return 'سرور موقتاً در دسترس نیست. کمی بعد دوباره تلاش کنید.';
      default: return 'خطای سرور (کد ' + status + ').';
    }
  };

  RB.post = function (url, body) {
    return RB.request(url, { method: 'POST', body: body || {} });
  };

  RB.get = function (url) {
    return RB.request(url, { method: 'GET' });
  };

  /* ------------------------------ کپی ------------------------------ */
  RB.copy = async function (text) {
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
      } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
      }
      RB.toast('کپی شد ✓', 'success', 1800);
      return true;
    } catch (e) {
      RB.toast('کپی انجام نشد', 'error');
      return false;
    }
  };

  document.addEventListener('click', function (e) {
    const copyBtn = e.target.closest('[data-copy]');
    if (copyBtn) {
      e.preventDefault();
      let value = copyBtn.getAttribute('data-copy');
      if (value === '' || value === null) {
        const target = copyBtn.closest('.copy-field');
        if (target) value = target.querySelector('span') ? target.querySelector('span').textContent : target.textContent;
      }
      RB.copy((value || '').trim());
    }
  });

  /* ------------------------------ مودال ------------------------------ */
  RB.modal = function (id, open) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('open', open !== false);
  };

  document.addEventListener('click', function (e) {
    const opener = e.target.closest('[data-modal-open]');
    if (opener) {
      e.preventDefault();
      RB.modal(opener.getAttribute('data-modal-open'), true);
    }
    const closer = e.target.closest('[data-modal-close]');
    if (closer) {
      e.preventDefault();
      const backdrop = closer.closest('.modal-backdrop');
      if (backdrop) backdrop.classList.remove('open');
    }
    if (e.target.classList && e.target.classList.contains('modal-backdrop')) {
      e.target.classList.remove('open');
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-backdrop.open').forEach(m => m.classList.remove('open'));
    }
  });

  /* ------------------------------ سایدبار ------------------------------ */
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-sidebar-toggle]')) {
      document.querySelector('.sidebar').classList.toggle('open');
      const overlay = document.querySelector('.sidebar-overlay');
      if (overlay) overlay.classList.toggle('open');
    }
    if (e.target.classList && e.target.classList.contains('sidebar-overlay')) {
      document.querySelector('.sidebar').classList.remove('open');
      e.target.classList.remove('open');
    }
  });

  /* ------------------------------ فرم‌های ارسال ------------------------------ */
  document.addEventListener('submit', async function (e) {
    const form = e.target;
    if (!form.hasAttribute('data-ajax')) return;
    e.preventDefault();
    const btn = (e.submitter && e.submitter.type === 'submit') ? e.submitter : form.querySelector('[type=submit]');
    const original = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner" style="width:16px;height:16px;border-width:2px"></span>'; }

    let formData;
    try { formData = e.submitter ? new FormData(form, e.submitter) : new FormData(form); }
    catch (_) { formData = new FormData(form); }
    const isMultipart = (form.getAttribute('enctype') || '').toLowerCase() === 'multipart/form-data'
      || form.querySelector('input[type="file"]') !== null;
    let payload;
    if (isMultipart) {
      if (!formData.has('_csrf')) { formData.append('_csrf', RB.csrf()); }
      payload = formData;
    } else {
      // ارسال استاندارد فرم تا PHP آرایه‌ها (levels[1][percent]، menus[1][title])
      // و چک‌باکس‌های چندگانه (conditions[]) و فیلدهای $_POST را کامل بسازد
      const params = new URLSearchParams();
      formData.forEach((value, key) => { params.append(key, String(value)); });
      if (!params.has('_csrf')) { params.append('_csrf', RB.csrf()); }
      payload = params;
    }

    const result = await RB.post(form.getAttribute('action') || window.location.pathname, payload);
    if (btn) { btn.disabled = false; btn.innerHTML = original; }

    if (result.ok) {
      RB.toast(result.message || 'با موفقیت ذخیره شد', 'success');
      if (result.redirect) { setTimeout(() => window.location.href = result.redirect, 700); return; }
      if (result.reload !== false) setTimeout(() => window.location.reload(), 650);
    } else {
      RB.toast(result.error || 'خطا در انجام عملیات', 'error', 6000);
      if (result.errors && Array.isArray(result.errors)) {
        result.errors.forEach(err => RB.toast(err, 'error', 6000));
      }
    }
  });

  /* ------------------------------ تأیید عملیات ------------------------------ */
  document.addEventListener('click', function (e) {
    const el = e.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) {
      e.preventDefault();
      e.stopImmediatePropagation();
    }
  });

  /* ============================================================
     مدیریت تم تاریک / روشن (پیش‌فرض: تاریک)
     ============================================================ */
  function themeStorageKey() {
    const scope = document.documentElement.getAttribute('data-theme-scope') || 'admin';
    return scope === 'miniapp' ? 'rb_mini_theme' : 'rb_admin_theme';
  }

  RB.getTheme = function () {
    return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
  };

  RB.setTheme = function (theme, persist) {
    const next = theme === 'light' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    if (persist !== false) {
      try { localStorage.setItem(themeStorageKey(), next); } catch (e) {}
    }
    document.querySelectorAll('[data-theme-label]').forEach(function (lbl) {
      lbl.textContent = next === 'dark' ? 'روشن' : 'تاریک';
    });
    const meta = document.getElementById('meta-theme-color');
    if (meta) {
      meta.setAttribute('content', next === 'dark' ? '#0b1120' : '#f4f6fb');
    }
    try {
      window.dispatchEvent(new CustomEvent('rb:themechange', { detail: { theme: next } }));
    } catch (e) {}
  };

  // همگام‌سازی برچسب دکمه‌های تم هنگام بارگذاری
  RB.setTheme(RB.getTheme(), false);

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-theme-toggle]');
    if (!btn) return;
    e.preventDefault();
    RB.setTheme(RB.getTheme() === 'dark' ? 'light' : 'dark', true);
  });

  // همگام‌سازی کارت‌های انتخاب حالت ربات
  document.addEventListener('change', function (e) {
    const radio = e.target;
    if (radio && radio.type === 'radio' && radio.closest('.mode-switcher-grid')) {
      const grid = radio.closest('.mode-switcher-grid');
      grid.querySelectorAll('.mode-card').forEach(function (card) {
        const inp = card.querySelector('input[type="radio"]');
        card.classList.toggle('selected', !!(inp && inp.checked));
      });
    }
  });

  /* ============================================================
     موتور نمودار حرفه‌ای و تعاملی روی Canvas (سازگار با دارک‌مود)
     ============================================================ */
  const PALETTE = ['#818cf8', '#06b6d4', '#10b981', '#f59e0b', '#a855f7', '#ef4444', '#38bdf8', '#34d399'];
  const chartRegistry = new Set();

  function chartColors() {
    const isDark = RB.getTheme() === 'dark';
    return {
      isDark: isDark,
      grid: isDark ? 'rgba(148, 163, 184, 0.10)' : 'rgba(148, 163, 184, 0.18)',
      axis: isDark ? 'rgba(148, 163, 184, 0.24)' : 'rgba(148, 163, 184, 0.36)',
      guide: isDark ? 'rgba(129, 140, 248, 0.40)' : 'rgba(99, 102, 241, 0.32)',
      label: isDark ? '#94a3b8' : '#64748b',
      text: isDark ? '#f1f5f9' : '#0f172a',
      dotBg: isDark ? '#111827' : '#ffffff',
      emptyRing: isDark ? 'rgba(148, 163, 184, 0.14)' : '#eef2f7'
    };
  }

  function registerChart(canvas, redrawFn) {
    canvas._rbRedraw = redrawFn;
    chartRegistry.add(canvas);
  }

  function redrawAllCharts() {
    chartRegistry.forEach(function (canvas) {
      if (!document.body.contains(canvas)) {
        chartRegistry.delete(canvas);
        return;
      }
      if (canvas.offsetParent === null) return;
      if (typeof canvas._rbRedraw === 'function') canvas._rbRedraw();
    });
  }

  RB.redrawCharts = redrawAllCharts;

  let resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(redrawAllCharts, 140);
  });
  window.addEventListener('rb:themechange', redrawAllCharts);

  function setupCanvas(canvas) {
    const dpr = window.devicePixelRatio || 1;
    const parent = canvas.parentElement;
    const w = Math.max(120, (parent ? parent.clientWidth : 0) || canvas.getBoundingClientRect().width || 320);
    const h = Math.max(110, (parent ? parent.clientHeight : 0) || canvas.getBoundingClientRect().height || 220);
    canvas.width = Math.round(w * dpr);
    canvas.height = Math.round(h * dpr);
    canvas.style.width = w + 'px';
    canvas.style.height = h + 'px';
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);
    return { ctx, w, h };
  }

  function ensureTooltip(canvas) {
    const wrap = canvas.parentElement;
    if (!wrap) return null;
    if (getComputedStyle(wrap).position === 'static') {
      wrap.style.position = 'relative';
    }
    let tip = wrap.querySelector('.rb-chart-tooltip');
    if (!tip) {
      tip = document.createElement('div');
      tip.className = 'rb-chart-tooltip';
      wrap.appendChild(tip);
    }
    return tip;
  }

  function positionTooltip(tip, wrapW, x, y) {
    if (!tip) return;
    const tw = tip.offsetWidth || 130;
    let left = x - tw / 2;
    left = Math.max(6, Math.min(wrapW - tw - 6, left));
    const top = Math.max(4, y - (tip.offsetHeight || 52) - 12);
    tip.style.left = left + 'px';
    tip.style.top = top + 'px';
  }

  function niceMax(max) {
    if (max <= 0) return 5;
    if (max <= 5) return 5;
    const exp = Math.floor(Math.log10(max));
    const base = Math.pow(10, exp);
    const n = max / base;
    const step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 3 ? 3 : n <= 5 ? 5 : n <= 8 ? 8 : 10;
    return step * base;
  }

  function faShort(n) {
    n = Number(n) || 0;
    if (n >= 1e9) return RB.faNum((n / 1e9).toFixed(n % 1e9 === 0 ? 0 : 1)) + 'B';
    if (n >= 1e6) return RB.faNum((n / 1e6).toFixed(n % 1e6 === 0 ? 0 : 1)) + 'م';
    if (n >= 1e3) return RB.faNum((n / 1e3).toFixed(n % 1e3 === 0 ? 0 : 1)) + 'ه';
    return RB.faNum(Math.round(n * 10) / 10);
  }

  function formatChartVal(v, unit) {
    const num = RB.faNum(Number(v || 0).toLocaleString('en-US'));
    return unit ? (num + ' ' + unit) : num;
  }

  /** رسم منحنی نرم یکنواخت بدون عبور از زیر محور صفر */
  function traceSmoothLine(ctx, pts, smooth) {
    if (!pts.length) return;
    ctx.moveTo(pts[0][0], pts[0][1]);
    if (pts.length === 1) return;
    if (smooth === false || pts.length === 2) {
      for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i][0], pts[i][1]);
      return;
    }
    for (let i = 0; i < pts.length - 1; i++) {
      const p0 = pts[i], p1 = pts[i + 1];
      const cpx = (p0[0] + p1[0]) / 2;
      ctx.bezierCurveTo(cpx, p0[1], cpx, p1[1], p1[0], p1[1]);
    }
  }

  /**
   * نمودار خطی / ناحیه‌ای نرم با Tooltip تعاملی
   */
  RB.lineChart = function (canvas, config) {
    if (!canvas) return;
    let hoverIdx = -1;

    function draw() {
      const { ctx, w, h } = setupCanvas(canvas);
      const theme = chartColors();
      const series = config.series || [];
      const labels = config.labels || [];
      const padRight = 46, padLeft = 14, padTop = 16, padBottom = 32;
      const plotW = Math.max(40, w - padRight - padLeft);
      const plotH = Math.max(40, h - padTop - padBottom);
      const allValues = series.flatMap(function (s) { return s.data || []; });
      const maxVal = niceMax(Math.max(1, ...(allValues.length ? allValues : [1])));
      const steps = 4;

      ctx.font = '11px Vazirmatn, sans-serif';
      ctx.textAlign = 'left';
      ctx.textBaseline = 'middle';

      for (let i = 0; i <= steps; i++) {
        const y = padTop + (plotH / steps) * i;
        ctx.strokeStyle = i === steps ? theme.axis : theme.grid;
        ctx.lineWidth = 1;
        ctx.setLineDash(i === steps ? [] : [3, 4]);
        ctx.beginPath();
        ctx.moveTo(padLeft, y);
        ctx.lineTo(padLeft + plotW, y);
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle = theme.label;
        ctx.fillText(faShort(maxVal - (maxVal / steps) * i), padLeft + plotW + 6, y);
      }

      if (!labels.length) return;
      const xStep = labels.length > 1 ? plotW / (labels.length - 1) : 0;
      const xy = function (i, v) {
        return [
          padLeft + (labels.length > 1 ? xStep * i : plotW / 2),
          padTop + plotH - (Math.max(0, Number(v) || 0) / maxVal) * plotH
        ];
      };

      // برچسب‌های محور X
      ctx.textAlign = 'center';
      ctx.textBaseline = 'top';
      ctx.fillStyle = theme.label;
      const maxLabels = w < 380 ? 5 : 7;
      const every = Math.max(1, Math.ceil(labels.length / maxLabels));
      labels.forEach(function (label, i) {
        if (i % every !== 0 && i !== labels.length - 1) return;
        if (i !== labels.length - 1 && labels.length - 1 - i < Math.floor(every * 0.6)) return;
        ctx.fillText(label, xy(i, 0)[0], padTop + plotH + 9);
      });

      // خط راهنمای عمودی هنگام هاور
      if (hoverIdx >= 0 && hoverIdx < labels.length) {
        const gx = xy(hoverIdx, 0)[0];
        ctx.save();
        ctx.strokeStyle = theme.guide;
        ctx.lineWidth = 1;
        ctx.setLineDash([4, 3]);
        ctx.beginPath();
        ctx.moveTo(gx, padTop);
        ctx.lineTo(gx, padTop + plotH);
        ctx.stroke();
        ctx.restore();
      }

      series.forEach(function (s, si) {
        const data = s.data || [];
        if (!data.length) return;
        const color = s.color || PALETTE[si % PALETTE.length];
        const pts = data.map(function (v, i) { return xy(i, v); });

        if (s.fill !== false) {
          const gradient = ctx.createLinearGradient(0, padTop, 0, padTop + plotH);
          gradient.addColorStop(0, color + (theme.isDark ? '48' : '32'));
          gradient.addColorStop(0.75, color + (theme.isDark ? '12' : '08'));
          gradient.addColorStop(1, color + '00');
          ctx.fillStyle = gradient;
          ctx.beginPath();
          traceSmoothLine(ctx, pts, config.smooth);
          ctx.lineTo(pts[pts.length - 1][0], padTop + plotH);
          ctx.lineTo(pts[0][0], padTop + plotH);
          ctx.closePath();
          ctx.fill();
        }

        ctx.save();
        ctx.strokeStyle = color;
        ctx.lineWidth = s.dashed ? 1.8 : 2.5;
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        if (s.dashed) ctx.setLineDash([5, 4]);
        ctx.beginPath();
        traceSmoothLine(ctx, pts, config.smooth);
        ctx.stroke();
        ctx.restore();

        pts.forEach(function (pt, i) {
          const v = Number(data[i]) || 0;
          const isHovered = i === hoverIdx;
          if (!isHovered && (data.length > 32 || (v === 0 && data.length > 10) || s.dashed)) return;
          const r = isHovered ? 5 : 3;
          if (isHovered) {
            ctx.fillStyle = color + '33';
            ctx.beginPath();
            ctx.arc(pt[0], pt[1], 9, 0, Math.PI * 2);
            ctx.fill();
          }
          ctx.fillStyle = isHovered ? color : theme.dotBg;
          ctx.beginPath();
          ctx.arc(pt[0], pt[1], r, 0, Math.PI * 2);
          ctx.fill();
          ctx.strokeStyle = color;
          ctx.lineWidth = 2;
          ctx.stroke();
        });
      });

      canvas._rbPlotMeta = { padLeft, plotW, padTop, plotH, w, labels, series, unit: config.unit || '', xy };
    }

    if (!canvas._rbBoundLine) {
      canvas._rbBoundLine = true;
      const onPointer = function (clientX) {
        const meta = canvas._rbPlotMeta;
        if (!meta || !meta.labels.length) return;
        const rect = canvas.getBoundingClientRect();
        const x = clientX - rect.left;
        const rel = Math.max(0, Math.min(1, (x - meta.padLeft) / Math.max(1, meta.plotW)));
        const idx = meta.labels.length > 1 ? Math.round(rel * (meta.labels.length - 1)) : 0;
        if (idx !== hoverIdx) {
          hoverIdx = idx;
          if (canvas._rbRedraw) canvas._rbRedraw();
        }
        const tip = ensureTooltip(canvas);
        if (tip) {
          let html = '<div class="rb-chart-tooltip-title">' + RB.escape(meta.labels[idx]) + '</div>';
          let minY = meta.padTop + meta.plotH;
          meta.series.forEach(function (s, si) {
            const c = s.color || PALETTE[si % PALETTE.length];
            const val = (s.data || [])[idx] || 0;
            const py = meta.xy(idx, val)[1];
            if (py < minY) minY = py;
            html += '<div class="rb-chart-tooltip-row"><span><i class="rb-chart-tooltip-dot" style="background:' + c + '"></i>' +
              RB.escape(s.name || '') + '</span><strong>' + RB.escape(formatChartVal(val, s.unit || meta.unit)) + '</strong></div>';
          });
          tip.innerHTML = html;
          tip.classList.add('show');
          positionTooltip(tip, meta.w, meta.xy(idx, 0)[0], minY);
        }
      };
      const onLeave = function () {
        if (hoverIdx !== -1) {
          hoverIdx = -1;
          if (canvas._rbRedraw) canvas._rbRedraw();
        }
        const tip = canvas.parentElement && canvas.parentElement.querySelector('.rb-chart-tooltip');
        if (tip) tip.classList.remove('show');
      };
      canvas.addEventListener('mousemove', function (e) { onPointer(e.clientX); });
      canvas.addEventListener('mouseleave', onLeave);
      canvas.addEventListener('touchstart', function (e) { if (e.touches[0]) onPointer(e.touches[0].clientX); }, { passive: true });
      canvas.addEventListener('touchmove', function (e) { if (e.touches[0]) onPointer(e.touches[0].clientX); }, { passive: true });
      canvas.addEventListener('touchend', function () { setTimeout(onLeave, 1400); }, { passive: true });
    }

    registerChart(canvas, draw);
    draw();
  };

  /**
   * نمودار ستونی با گرادیان و Tooltip تعاملی
   */
  RB.barChart = function (canvas, config) {
    if (!canvas) return;
    let hoverIdx = -1;

    function draw() {
      const { ctx, w, h } = setupCanvas(canvas);
      const theme = chartColors();
      const labels = config.labels || [];
      const data = config.data || [];
      const colors = config.colors || null;
      const color = config.color || PALETTE[0];
      const padRight = 46, padLeft = 12, padTop = 14, padBottom = 32;
      const plotW = Math.max(40, w - padRight - padLeft);
      const plotH = Math.max(40, h - padTop - padBottom);
      const maxVal = niceMax(Math.max(1, ...(data.length ? data : [1])));
      const steps = 4;

      ctx.font = '11px Vazirmatn, sans-serif';
      ctx.textAlign = 'left';
      ctx.textBaseline = 'middle';
      for (let i = 0; i <= steps; i++) {
        const y = padTop + (plotH / steps) * i;
        ctx.strokeStyle = i === steps ? theme.axis : theme.grid;
        ctx.setLineDash(i === steps ? [] : [3, 4]);
        ctx.beginPath();
        ctx.moveTo(padLeft, y);
        ctx.lineTo(padLeft + plotW, y);
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle = theme.label;
        ctx.fillText(faShort(maxVal - (maxVal / steps) * i), padLeft + plotW + 6, y);
      }

      if (!labels.length) return;
      const slot = plotW / labels.length;
      const barW = Math.max(4, Math.min(28, slot * 0.62));

      ctx.textAlign = 'center';
      ctx.textBaseline = 'top';
      const maxLabels = w < 380 ? 5 : 8;
      const every = Math.max(1, Math.ceil(labels.length / maxLabels));
      data.forEach(function (v, i) {
        const numV = Math.max(0, Number(v) || 0);
        const barH = (numV / maxVal) * plotH;
        const x = padLeft + slot * i + (slot - barW) / 2;
        const y = padTop + plotH - barH;
        const c = colors ? colors[i % colors.length] : color;
        const isHov = i === hoverIdx;

        if (isHov) {
          ctx.fillStyle = theme.isDark ? 'rgba(148,163,184,0.08)' : 'rgba(99,102,241,0.06)';
          ctx.fillRect(padLeft + slot * i, padTop, slot, plotH);
        }

        if (numV > 0) {
          const grad = ctx.createLinearGradient(0, y, 0, padTop + plotH);
          grad.addColorStop(0, c);
          grad.addColorStop(1, c + (isHov ? 'cc' : '66'));
          ctx.fillStyle = grad;
          const r = Math.min(6, barW / 2);
          ctx.beginPath();
          if (ctx.roundRect) { ctx.roundRect(x, y, barW, Math.max(barH, 2), [r, r, 0, 0]); }
          else { ctx.rect(x, y, barW, Math.max(barH, 2)); }
          ctx.fill();
        } else {
          ctx.fillStyle = theme.grid;
          ctx.fillRect(x, padTop + plotH - 2, barW, 2);
        }

        if ((i % every === 0 || i === data.length - 1) && (i === data.length - 1 || data.length - 1 - i >= Math.floor(every * 0.55))) {
          ctx.fillStyle = theme.label;
          ctx.fillText(labels[i], padLeft + slot * i + slot / 2, padTop + plotH + 9);
        }
      });

      canvas._rbBarMeta = { padLeft, plotW, padTop, plotH, slot, maxVal, w, labels, data, color, name: config.name || 'مقدار', unit: config.unit || '' };
    }

    if (!canvas._rbBoundBar) {
      canvas._rbBoundBar = true;
      const onPointer = function (clientX) {
        const m = canvas._rbBarMeta;
        if (!m || !m.labels.length) return;
        const rect = canvas.getBoundingClientRect();
        const x = clientX - rect.left;
        const idx = Math.max(0, Math.min(m.labels.length - 1, Math.floor((x - m.padLeft) / Math.max(1, m.slot))));
        if (idx !== hoverIdx) {
          hoverIdx = idx;
          if (canvas._rbRedraw) canvas._rbRedraw();
        }
        const tip = ensureTooltip(canvas);
        if (tip) {
          const val = m.data[idx] || 0;
          const barY = m.padTop + m.plotH - (Math.max(0, Number(val) || 0) / m.maxVal) * m.plotH;
          tip.innerHTML = '<div class="rb-chart-tooltip-title">' + RB.escape(m.labels[idx]) + '</div>' +
            '<div class="rb-chart-tooltip-row"><span><i class="rb-chart-tooltip-dot" style="background:' + m.color + '"></i>' +
            RB.escape(m.name) + '</span><strong>' + RB.escape(formatChartVal(val, m.unit)) + '</strong></div>';
          tip.classList.add('show');
          positionTooltip(tip, m.w, m.padLeft + m.slot * idx + m.slot / 2, barY);
        }
      };
      const onLeave = function () {
        if (hoverIdx !== -1) { hoverIdx = -1; if (canvas._rbRedraw) canvas._rbRedraw(); }
        const tip = canvas.parentElement && canvas.parentElement.querySelector('.rb-chart-tooltip');
        if (tip) tip.classList.remove('show');
      };
      canvas.addEventListener('mousemove', function (e) { onPointer(e.clientX); });
      canvas.addEventListener('mouseleave', onLeave);
      canvas.addEventListener('touchstart', function (e) { if (e.touches[0]) onPointer(e.touches[0].clientX); }, { passive: true });
      canvas.addEventListener('touchend', function () { setTimeout(onLeave, 1400); }, { passive: true });
    }

    registerChart(canvas, draw);
    draw();
  };

  /**
   * نمودار دونات مدرن با برچسب مرکزی
   */
  RB.donutChart = function (canvas, config) {
    if (!canvas) return;

    function draw() {
      const { ctx, w, h } = setupCanvas(canvas);
      const theme = chartColors();
      const data = config.data || [];
      const colors = config.colors || PALETTE;
      const total = data.reduce(function (a, b) { return a + (Number(b.value) || 0); }, 0);
      const cx = w / 2, cy = h / 2;
      const radius = Math.max(30, Math.min(w, h) / 2 - 6);
      const inner = radius * 0.66;

      if (total <= 0) {
        ctx.strokeStyle = theme.emptyRing;
        ctx.lineWidth = radius - inner;
        ctx.beginPath();
        ctx.arc(cx, cy, (radius + inner) / 2, 0, Math.PI * 2);
        ctx.stroke();
        ctx.fillStyle = theme.label;
        ctx.font = '12px Vazirmatn, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(config.emptyText || 'بدون داده', cx, cy);
        return;
      }

      const activeSegments = data.filter(function (d) { return (Number(d.value) || 0) > 0; }).length;
      const gap = activeSegments > 1 ? 0.035 : 0;
      let start = -Math.PI / 2;
      data.forEach(function (item, i) {
        const value = Number(item.value) || 0;
        if (value <= 0) return;
        const angle = (value / total) * Math.PI * 2;
        const segStart = start + gap / 2;
        const segEnd = Math.max(segStart + 0.01, start + angle - gap / 2);
        ctx.beginPath();
        ctx.arc(cx, cy, radius, segStart, segEnd);
        ctx.arc(cx, cy, inner, segEnd, segStart, true);
        ctx.closePath();
        ctx.fillStyle = colors[i % colors.length];
        ctx.fill();
        start += angle;
      });

      if (config.centerValue !== undefined || config.centerLabel) {
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        if (config.centerValue !== undefined) {
          ctx.fillStyle = theme.text;
          ctx.font = '800 ' + Math.max(14, Math.round(radius * 0.25)) + 'px Vazirmatn, sans-serif';
          ctx.fillText(String(config.centerValue), cx, cy - (config.centerLabel ? 7 : 0));
        }
        if (config.centerLabel) {
          ctx.fillStyle = theme.label;
          ctx.font = '500 11px Vazirmatn, sans-serif';
          ctx.fillText(String(config.centerLabel), cx, cy + 12);
        }
      }
    }

    registerChart(canvas, draw);
    draw();
  };

  /* ------------------------------ تب‌ها ------------------------------ */
  document.addEventListener('click', function (e) {
    const tab = e.target.closest('[data-tab]');
    if (!tab) return;
    const group = tab.parentElement;
    group.querySelectorAll('[data-tab]').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    const container = document.querySelector(tab.getAttribute('data-tab-target') || 'body');
    if (container) {
      container.querySelectorAll('[data-tab-panel]').forEach(p => {
        p.classList.toggle('hidden', p.getAttribute('data-tab-panel') !== tab.getAttribute('data-tab'));
      });
    }
  });

  /* ------------------------------ جدول: انتخاب همه ------------------------------ */
  document.addEventListener('change', function (e) {
    if (e.target.id === 'select-all' || e.target.hasAttribute('data-select-all')) {
      document.querySelectorAll('input[data-row-check]').forEach(c => { c.checked = e.target.checked; });
    }
  });

  /* ------------------------------ تریگر رفرش خودکار ------------------------------ */
  RB.autoRefresh = function (url, callback, interval) {
    let timer = null;
    const tick = async () => {
      const data = await RB.get(url);
      if (data && data.ok) callback(data);
    };
    tick();
    timer = setInterval(tick, interval || 20000);
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) clearInterval(timer);
      else { tick(); timer = setInterval(tick, interval || 20000); }
    });
    return () => clearInterval(timer);
  };

  /* ------------------------------ فرمت ارقام ورودی ------------------------------ */
  document.addEventListener('input', function (e) {
    const el = e.target;
    if (el.hasAttribute && el.hasAttribute('data-money')) {
      const raw = RB.enNum(el.value).replace(/[^\d]/g, '');
      el.value = raw ? Number(raw).toLocaleString('en-US') : '';
    }
  });

  RB.unformatMoney = function (value) {
    return RB.enNum(value).replace(/[^\d]/g, '');
  };
})();

// Keep the same player node when changing between modal and floating modes so playback continues.
(function () {
  const overlay = document.querySelector('[data-training-overlay]');
  const panel = document.querySelector('[data-training-window]');
  const media = document.querySelector('[data-training-media]');
  const toggle = document.querySelector('[data-training-float]');
  const opener = document.querySelector('[data-training-open]');
  const close = document.querySelector('[data-training-close]');
  const toolbar = document.querySelector('[data-training-drag]');
  if (!overlay || !panel || !media || !toggle || !opener || !close || !toolbar) return;
  // In floating mode the panel navigates inside a same-origin frame while the player
  // remains in its parent document. This avoids restarting the Drive iframe on navigation.
  try {
    if (window.parent !== window && window.parent.RBTrainingShell) {
      opener.addEventListener('click', () => window.parent.RBTrainingShell.open());
      return;
    }
  } catch (_) { /* not our same-origin shell */ }
  const stateKey = 'rb_training_float_v1';
  const timeKey = 'rb_training_time_v1';
  const save = (key, value) => { try { sessionStorage.setItem(key, value); } catch (_) {} };
  const read = key => { try { return sessionStorage.getItem(key); } catch (_) { return null; } };
  const remove = key => { try { sessionStorage.removeItem(key); } catch (_) {} };
  let player = null;
  let workspace = null;
  window.RBTrainingShell = { open: () => open(false) };
  function keepPanelNavigable() {
    if (workspace) return;
    const layout = document.querySelector('.layout');
    if (!layout) return;
    workspace = document.createElement('iframe');
    workspace.className = 'training-workspace';
    workspace.title = 'پنل مدیریت';
    // A fresh GET is needed only once, when the user first makes the player float.
    workspace.src = window.location.href;
    workspace.addEventListener('load', () => {
      try {
        const child = workspace.contentWindow.location;
        if (child.origin !== window.location.origin) return;
        if (/\/admin\/login\/?$/.test(child.pathname)) {
          window.location.assign(child.href);
          return;
        }
        // Refreshing the browser should return to the page currently shown in the frame.
        window.history.replaceState(window.history.state, '', child.pathname + child.search + child.hash);
      } catch (_) { /* a cross-origin page cannot be inspected */ }
    });
    document.body.insertBefore(workspace, overlay);
    layout.hidden = true;
    layout.style.display = 'none';
  }

  function setFloating(floating) {
    overlay.classList.toggle('is-floating', floating);
    panel.setAttribute('aria-modal', floating ? 'false' : 'true');
    toggle.title = floating ? 'نمایش بزرگ' : 'معلق روی پنل';
    toggle.setAttribute('aria-label', toggle.title);
    toggle.textContent = floating ? '□' : '▣';
    if (!floating) { overlay.style.left = ''; overlay.style.top = ''; overlay.style.bottom = ''; remove(stateKey); }
    else { save(stateKey, '1'); keepPanelNavigable(); }
  }
  function open(floating) {
    if (!player) {
      player = document.createElement(panel.dataset.local === '1' ? 'video' : 'iframe');
      if (panel.dataset.local === '1') {
        player.controls = true;
        player.playsInline = true;
        player.preload = 'metadata';
        player.addEventListener('loadedmetadata', () => {
          const saved = Number(read(timeKey));
          if (floating && Number.isFinite(saved) && saved > 0 && saved < player.duration) player.currentTime = saved;
        });
        player.addEventListener('timeupdate', () => { if (overlay.classList.contains('is-floating')) save(timeKey, String(player.currentTime)); });
      } else {
        player.title = 'ویدیوی آموزش پنل';
        player.allow = 'autoplay; fullscreen';
        player.allowFullscreen = true;
        player.referrerPolicy = 'no-referrer';
      }
      player.src = panel.dataset.source;
      media.appendChild(player);
    }
    overlay.hidden = false;
    setFloating(floating);
    if (panel.dataset.local === '1') { const promise = player.play(); if (promise && promise.catch) promise.catch(() => {}); }
  }
  opener.addEventListener('click', () => open(false));
  toggle.addEventListener('click', () => setFloating(!overlay.classList.contains('is-floating')));
  close.addEventListener('click', () => {
    overlay.hidden = true;
    remove(stateKey); remove(timeKey);
    if (player) { if (panel.dataset.local === '1') player.pause(); player.removeAttribute('src'); player.remove(); player = null; }
    if (workspace) workspace.focus(); else opener.focus();
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !overlay.hidden && !overlay.classList.contains('is-floating')) close.click(); });
  // Drag the compact window using the toolbar, without intercepting player controls.
  toolbar.addEventListener('pointerdown', e => {
    if (!overlay.classList.contains('is-floating') || e.target.closest('button, a')) return;
    const rect = overlay.getBoundingClientRect();
    const dx = e.clientX - rect.left, dy = e.clientY - rect.top;
    toolbar.setPointerCapture(e.pointerId);
    const move = event => {
      const x = Math.max(0, Math.min(window.innerWidth - overlay.offsetWidth, event.clientX - dx));
      const y = Math.max(0, Math.min(window.innerHeight - overlay.offsetHeight, event.clientY - dy));
      overlay.style.left = x + 'px'; overlay.style.top = y + 'px'; overlay.style.bottom = 'auto';
    };
    const stop = () => { toolbar.removeEventListener('pointermove', move); toolbar.removeEventListener('pointerup', stop); toolbar.removeEventListener('pointercancel', stop); };
    toolbar.addEventListener('pointermove', move);
    toolbar.addEventListener('pointerup', stop);
    toolbar.addEventListener('pointercancel', stop);
  });
  if (read(stateKey) === '1') open(true);
  else if (panel.dataset.autoOpen === '1') open(false);
})();
