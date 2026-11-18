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
      '٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9'
    };
    return String(value === null || value === undefined ? '' : value)
      .replace(/[۰-۹٠-٩]/g, d => map[d] || d);
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
     نمودار سبک روی Canvas (بدون کتابخانه خارجی)
     ============================================================ */
  const PALETTE = ['#6366f1', '#06b6d4', '#10b981', '#f59e0b', '#a855f7', '#ef4444', '#3b82f6', '#84cc16'];

  function setupCanvas(canvas) {
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const w = rect.width || canvas.parentElement.clientWidth || 600;
    const h = rect.height || 300;
    canvas.width = w * dpr;
    canvas.height = h * dpr;
    canvas.style.width = w + 'px';
    canvas.style.height = h + 'px';
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);
    return { ctx, w, h };
  }

  function niceMax(max) {
    if (max <= 0) return 10;
    const exp = Math.floor(Math.log10(max));
    const base = Math.pow(10, exp);
    const n = max / base;
    const step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
    return step * base;
  }

  function faShort(n) {
    n = Number(n) || 0;
    if (n >= 1e6) return RB.faNum((n / 1e6).toFixed(n % 1e6 === 0 ? 0 : 1)) + 'م';
    if (n >= 1e3) return RB.faNum((n / 1e3).toFixed(n % 1e3 === 0 ? 0 : 1)) + 'ه';
    return RB.faNum(n);
  }

  /**
   * نمودار خطی/ناحیه‌ای
   * @param {HTMLCanvasElement} canvas
   * @param {{labels:string[], series:{name:string,data:number[],color?:string,fill?:boolean}[]}} config
   */
  RB.lineChart = function (canvas, config) {
    if (!canvas) return;
    const { ctx, w, h } = setupCanvas(canvas);
    const series = config.series || [];
    const labels = config.labels || [];
    const padRight = 46, padLeft = 12, padTop = 16, padBottom = 34;
    const plotW = w - padRight - padLeft;
    const plotH = h - padTop - padBottom;
    const allValues = series.flatMap(s => s.data);
    const maxVal = niceMax(Math.max(1, ...allValues));
    const steps = 4;

    ctx.font = '11px Vazirmatn, sans-serif';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';

    // خطوط شبکه و محور Y (سمت راست به‌خاطر RTL خوانایی)
    for (let i = 0; i <= steps; i++) {
      const y = padTop + (plotH / steps) * i;
      ctx.strokeStyle = i === steps ? '#e2e8f0' : '#f1f5f9';
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(padLeft, y);
      ctx.lineTo(padLeft + plotW, y);
      ctx.stroke();
      ctx.fillStyle = '#94a3b8';
      ctx.fillText(faShort(maxVal - (maxVal / steps) * i), padLeft + plotW + 6, y);
    }

    if (!labels.length) return;
    const xStep = labels.length > 1 ? plotW / (labels.length - 1) : 0;
    const xy = (i, v) => [
      padLeft + (labels.length > 1 ? xStep * i : plotW / 2),
      padTop + plotH - (Math.max(0, v) / maxVal) * plotH
    ];

    // برچسب‌های X
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    ctx.fillStyle = '#94a3b8';
    const every = Math.max(1, Math.ceil(labels.length / 8));
    labels.forEach((label, i) => {
      if (i % every !== 0 && i !== labels.length - 1) return;
      ctx.fillText(label, xy(i, 0)[0], padTop + plotH + 10);
    });

    series.forEach((s, si) => {
      const color = s.color || PALETTE[si % PALETTE.length];
      if (s.fill !== false) {
        const gradient = ctx.createLinearGradient(0, padTop, 0, padTop + plotH);
        gradient.addColorStop(0, color + '33');
        gradient.addColorStop(1, color + '03');
        ctx.fillStyle = gradient;
        ctx.beginPath();
        ctx.moveTo(xy(0, 0)[0], padTop + plotH);
        s.data.forEach((v, i) => { const [x, y] = xy(i, v); ctx.lineTo(x, y); });
        ctx.lineTo(xy(s.data.length - 1, 0)[0], padTop + plotH);
        ctx.closePath();
        ctx.fill();
      }
      ctx.strokeStyle = color;
      ctx.lineWidth = 2.4;
      ctx.lineJoin = 'round';
      ctx.beginPath();
      s.data.forEach((v, i) => {
        const [x, y] = xy(i, v);
        if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      });
      ctx.stroke();

      if (s.data.length <= 40) {
        s.data.forEach((v, i) => {
          if (v === 0 && s.data.length > 14) return;
          const [x, y] = xy(i, v);
          ctx.fillStyle = '#fff';
          ctx.beginPath();
          ctx.arc(x, y, 3, 0, Math.PI * 2);
          ctx.fill();
          ctx.strokeStyle = color;
          ctx.lineWidth = 2;
          ctx.stroke();
        });
      }
    });
  };

  /**
   * نمودار ستونی
   */
  RB.barChart = function (canvas, config) {
    if (!canvas) return;
    const { ctx, w, h } = setupCanvas(canvas);
    const labels = config.labels || [];
    const data = config.data || [];
    const colors = config.colors || null;
    const color = config.color || PALETTE[0];
    const padRight = 46, padLeft = 12, padTop = 14, padBottom = 34;
    const plotW = w - padRight - padLeft;
    const plotH = h - padTop - padBottom;
    const maxVal = niceMax(Math.max(1, ...data));
    const steps = 4;

    ctx.font = '11px Vazirmatn, sans-serif';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    for (let i = 0; i <= steps; i++) {
      const y = padTop + (plotH / steps) * i;
      ctx.strokeStyle = i === steps ? '#e2e8f0' : '#f1f5f9';
      ctx.beginPath();
      ctx.moveTo(padLeft, y);
      ctx.lineTo(padLeft + plotW, y);
      ctx.stroke();
      ctx.fillStyle = '#94a3b8';
      ctx.fillText(faShort(maxVal - (maxVal / steps) * i), padLeft + plotW + 6, y);
    }

    if (!labels.length) return;
    const slot = plotW / labels.length;
    const barW = Math.max(3, Math.min(30, slot * 0.62));

    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    const every = Math.max(1, Math.ceil(labels.length / 10));
    data.forEach((v, i) => {
      const barH = (Math.max(0, v) / maxVal) * plotH;
      const x = padLeft + slot * i + (slot - barW) / 2;
      const y = padTop + plotH - barH;
      const grad = ctx.createLinearGradient(0, y, 0, padTop + plotH);
      const c = colors ? colors[i % colors.length] : color;
      grad.addColorStop(0, c);
      grad.addColorStop(1, c + '66');
      ctx.fillStyle = grad;
      const r = Math.min(5, barW / 2);
      ctx.beginPath();
      if (ctx.roundRect) { ctx.roundRect(x, y, barW, Math.max(barH, 1), [r, r, 0, 0]); }
      else { ctx.rect(x, y, barW, Math.max(barH, 1)); }
      ctx.fill();

      if (i % every === 0 || i === data.length - 1) {
        ctx.fillStyle = '#94a3b8';
        ctx.fillText(labels[i], padLeft + slot * i + slot / 2, padTop + plotH + 10);
      }
    });
  };

  /**
   * نمودار دونات
   */
  RB.donutChart = function (canvas, config) {
    if (!canvas) return;
    const { ctx, w, h } = setupCanvas(canvas);
    const data = config.data || [];
    const colors = config.colors || PALETTE;
    const total = data.reduce((a, b) => a + (Number(b.value) || 0), 0);
    const cx = w / 2, cy = h / 2;
    const radius = Math.min(w, h) / 2 - 8;
    const inner = radius * 0.62;

    if (total <= 0) {
      ctx.strokeStyle = '#f1f5f9';
      ctx.lineWidth = radius - inner;
      ctx.beginPath();
      ctx.arc(cx, cy, (radius + inner) / 2, 0, Math.PI * 2);
      ctx.stroke();
      ctx.fillStyle = '#94a3b8';
      ctx.font = '12px Vazirmatn, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('داده‌ای نیست', cx, cy + 4);
      return;
    }

    let start = -Math.PI / 2;
    data.forEach((item, i) => {
      const value = Number(item.value) || 0;
      if (value <= 0) return;
      const angle = (value / total) * Math.PI * 2;
      ctx.beginPath();
      ctx.arc(cx, cy, radius, start, start + angle);
      ctx.arc(cx, cy, inner, start + angle, start, true);
      ctx.closePath();
      ctx.fillStyle = colors[i % colors.length];
      ctx.fill();
      start += angle;
    });
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
