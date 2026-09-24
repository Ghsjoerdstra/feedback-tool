/*!
 * Site Feedback widget
 *
 * De plugin laadt dit script alleen voor ingelogde beheerders en zet vooraf
 * window.SFB_CONFIG (REST-URL, nonce, gebruiker, Asana aan/uit).
 */
(function () {
  'use strict';

  if (window.__sfbLoaded || window.self !== window.top) return;
  window.__sfbLoaded = true;

  var H2C_URL = 'https://cdn.jsdelivr.net/npm/html2canvas-pro@1/dist/html2canvas-pro.min.js';
  var ACCENT = '#5b3df5';
  var MARK = '#ff3b6b';

  var cfg = window.SFB_CONFIG;
  if (!cfg || !cfg.api) return;
  cfg.api = String(cfg.api).replace(/\/+$/, '');

  /* ---------------------------------------------------------------------
   * API
   * ------------------------------------------------------------------ */
  function endpoint(path, query) {
    var url = cfg.api + path;
    var qs = query ? new URLSearchParams(query).toString() : '';
    return qs ? url + (url.indexOf('?') > -1 ? '&' : '?') + qs : url;
  }

  function api(method, path, body, query) {
    var headers = { Accept: 'application/json' };
    if (body) headers['Content-Type'] = 'application/json';
    headers['X-WP-Nonce'] = cfg.nonce;

    return fetch(endpoint(path, query), {
      method: method,
      headers: headers,
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) {
          var err = new Error((data && data.message) || 'Er ging iets mis (' + res.status + ')');
          err.status = res.status;
          throw err;
        }
        return data;
      });
    });
  }

  /* ---------------------------------------------------------------------
   * Helpers
   * ------------------------------------------------------------------ */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function cssEsc(s) {
    return window.CSS && CSS.escape ? CSS.escape(s) : String(s).replace(/([^\w-])/g, '\\$1');
  }

  function uniqueId(el) {
    if (!el.id) return false;
    try { return document.querySelectorAll('#' + cssEsc(el.id)).length === 1; } catch (e) { return false; }
  }

  /** Bouwt een zo kort mogelijke, unieke CSS-selector voor een element. */
  function getSelector(el) {
    var parts = [];
    while (el && el.nodeType === 1 && el !== document.documentElement) {
      if (uniqueId(el)) { parts.unshift('#' + cssEsc(el.id)); break; }
      var part = el.tagName.toLowerCase();
      var parent = el.parentElement;
      if (parent) {
        var same = Array.prototype.filter.call(parent.children, function (c) { return c.tagName === el.tagName; });
        if (same.length > 1) part += ':nth-of-type(' + (same.indexOf(el) + 1) + ')';
      }
      parts.unshift(part);
      el = parent;
    }
    return parts.join(' > ');
  }

  function describe(el) {
    return {
      tag: el.tagName.toLowerCase(),
      id: el.id || '',
      classes: (el.getAttribute('class') || '').trim(),
      text: (el.innerText || el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 200),
      html: (el.outerHTML || '').slice(0, 1000)
    };
  }

  function urlKey(u) {
    try {
      var x = new URL(u, location.href);
      return x.hostname.toLowerCase() + (x.pathname.replace(/\/+$/, '') || '/');
    } catch (e) { return ''; }
  }

  function isThisPage(item) { return urlKey(item.url) === urlKey(location.href); }

  function pathOf(u) {
    try { var x = new URL(u); return (x.hostname !== location.hostname ? x.hostname : '') + x.pathname; } catch (e) { return u; }
  }

  function findEl(selector) {
    if (!selector) return null;
    try { return document.querySelector(selector); } catch (e) { return null; }
  }

  /** Positie (viewport-coördinaten) waar een feedback-item thuishoort. */
  function itemPoint(item) {
    var m = item.mouse || {};
    var el = findEl(item.selector);
    if (el && el.getClientRects().length) {
      var r = el.getBoundingClientRect();
      var px = m.pct_x != null ? m.pct_x : 50;
      var py = m.pct_y != null ? m.pct_y : 50;
      return { x: r.left + r.width * px / 100, y: r.top + r.height * py / 100, el: el, rect: r };
    }
    return { x: (m.page_x || 0) - window.scrollX, y: (m.page_y || 0) - window.scrollY, el: null, rect: null };
  }

  var h2cPromise = null;
  function loadHtml2Canvas() {
    if (window.html2canvas) return Promise.resolve(window.html2canvas);
    if (h2cPromise) return h2cPromise;
    h2cPromise = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = cfg.html2canvas || H2C_URL;
      s.async = true;
      s.onload = function () {
        if (window.html2canvas) resolve(window.html2canvas);
        else reject(new Error('Screenshot-bibliotheek niet beschikbaar'));
      };
      s.onerror = function () { h2cPromise = null; reject(new Error('Kon screenshot-bibliotheek niet laden')); };
      document.head.appendChild(s);
    });
    return h2cPromise;
  }

  /* ---------------------------------------------------------------------
   * Styling (in Shadow DOM, dus geen conflicten met het thema)
   * ------------------------------------------------------------------ */
  var CSS_TEXT = [
    ':host{all:initial}',
    '*{box-sizing:border-box;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}',
    '.w{--a:' + ACCENT + ';--m:' + MARK + ';--t:#1d1d1f;--mut:#6e6e73;--line:#e5e5ea;--bg2:#f5f5f7}',
    'button{font:inherit;cursor:pointer}',

    '.tab{position:fixed;right:0;top:50%;transform:translateY(-50%);width:42px;height:48px;border:0;border-radius:10px 0 0 10px;background:var(--a);color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 18px rgba(0,0,0,.22);transition:width .15s;z-index:3}',
    '.tab:hover{width:48px}.tab svg{width:21px;height:21px}',
    '.count{position:absolute;top:-7px;left:-7px;min-width:20px;height:20px;padding:0 5px;border-radius:10px;background:var(--m);color:#fff;font-size:11px;font-weight:700;line-height:20px;text-align:center;border:2px solid #fff}',
    '.count:empty{display:none}',
    '.open .tab,.picking .tab{display:none}',

    '.panel{position:fixed;top:0;right:0;height:100%;width:min(380px,100vw);background:#fff;color:var(--t);box-shadow:-10px 0 34px rgba(0,0,0,.18);transform:translateX(105%);transition:transform .22s ease;display:flex;flex-direction:column;z-index:4;font-size:14px;line-height:1.45}',
    '.open .panel{transform:none}',
    '.head{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--line)}',
    '.head strong{flex:1;font-size:15px}',
    '.icon-btn{border:0;background:transparent;width:30px;height:30px;border-radius:8px;color:var(--mut);font-size:20px;line-height:1;display:flex;align-items:center;justify-content:center}',
    '.icon-btn:hover{background:var(--bg2);color:var(--t)}',
    '.body{flex:1;overflow:auto;padding:16px}',
    '.hi{margin:0 0 14px;color:var(--mut);display:flex;align-items:center;gap:8px}',
    '.hi img{width:26px;height:26px;border-radius:50%}',

    '.big{display:flex;align-items:center;gap:14px;width:100%;text-align:left;border:1px solid var(--line);background:#fff;border-radius:12px;padding:16px;margin-bottom:10px;color:var(--t)}',
    '.big:hover{border-color:var(--a);box-shadow:0 0 0 3px rgba(91,61,245,.12)}',
    '.big .ic{flex:none;width:40px;height:40px;border-radius:10px;background:var(--bg2);display:flex;align-items:center;justify-content:center;font-size:20px;color:var(--a)}',
    '.big.primary .ic{background:var(--a);color:#fff}',
    '.big b{display:block;font-size:15px}.big small{color:var(--mut);font-size:12.5px}',

    '.btn{border:1px solid var(--line);background:#fff;color:var(--t);border-radius:8px;padding:8px 12px;font-size:13.5px;font-weight:500;text-decoration:none;display:inline-flex;align-items:center;gap:6px}',
    '.btn:hover{border-color:#c7c7cc}',
    '.btn.primary{background:var(--a);border-color:var(--a);color:#fff}',
    '.btn.primary:hover{filter:brightness(1.08)}',
    '.btn.danger{color:#c62828}',
    '.btn[disabled]{opacity:.55;cursor:default}',
    '.actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}',
    '.actions .grow{flex:1;justify-content:center}',

    '.shot{position:relative;border:1px solid var(--line);border-radius:10px;background:var(--bg2);min-height:140px;display:flex;align-items:center;justify-content:center;overflow:hidden;color:var(--mut);font-size:13px}',
    '.shot img{display:block;width:100%;cursor:zoom-in}',
    '.spin{width:18px;height:18px;border:2px solid var(--line);border-top-color:var(--a);border-radius:50%;animation:sp .8s linear infinite;margin-right:8px}',
    '@keyframes sp{to{transform:rotate(360deg)}}',
    '.meta{margin:12px 0 0;display:grid;grid-template-columns:auto 1fr;gap:4px 12px;font-size:12.5px}',
    '.meta dt{color:var(--mut)}.meta dd{margin:0;min-width:0;overflow-wrap:anywhere}',
    'code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11.5px;background:var(--bg2);padding:1px 5px;border-radius:4px}',
    'label.lbl{display:block;font-weight:600;margin:16px 0 6px}',
    'textarea{width:100%;min-height:110px;resize:vertical;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font:inherit;color:var(--t);outline:none}',
    'textarea:focus{border-color:var(--a);box-shadow:0 0 0 3px rgba(91,61,245,.15)}',
    'textarea.err{border-color:var(--m)}',
    '.hint{color:var(--mut);font-size:12px;margin-top:6px}',

    '.tabs{display:flex;background:var(--bg2);border-radius:9px;padding:3px;margin-bottom:10px}',
    '.tabs button{flex:1;border:0;background:transparent;padding:7px;border-radius:7px;color:var(--mut);font-weight:500}',
    '.tabs button.on{background:#fff;color:var(--t);box-shadow:0 1px 3px rgba(0,0,0,.1)}',
    '.chk{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--mut);margin-bottom:12px}',
    '.list{list-style:none;margin:0;padding:0}',
    '.item{display:flex;gap:10px;padding:10px;border:1px solid var(--line);border-radius:10px;margin-bottom:8px;cursor:pointer;align-items:flex-start}',
    '.item:hover{border-color:var(--a)}',
    '.num{flex:none;width:22px;height:22px;border-radius:50%;background:var(--a);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;margin-top:2px}',
    '.item.done .num{background:#34c759}',
    '.thumb{flex:none;width:64px;height:44px;border-radius:6px;object-fit:cover;object-position:top;background:var(--bg2);border:1px solid var(--line)}',
    '.item .txt{flex:1;min-width:0}',
    '.item p{margin:0 0 3px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}',
    '.item small{color:var(--mut);font-size:11.5px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.empty{text-align:center;color:var(--mut);padding:30px 10px}',
    '.badge{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;background:#fdecef;color:#c62828}',
    '.badge.done{background:#e8f8ec;color:#1b7f3b}',
    '.comment{white-space:pre-wrap;font-size:15px;margin:14px 0 4px}',
    '.asana{margin-top:18px;border-top:1px solid var(--line);padding-top:14px}',
    '.asana-head{display:flex;align-items:center;gap:8px}.asana-head b{font-size:14px}.asana-head a{margin-left:auto;color:var(--a);font-size:13px;text-decoration:none}',
    '.asana-head b::before{content:"";display:inline-block;width:10px;height:10px;border-radius:50%;background:#f06a6a;margin-right:6px}',
    '.comments{margin:12px 0 10px}',
    '.c{background:var(--bg2);border-radius:10px;padding:8px 11px;margin-bottom:7px;font-size:13px}',
    '.c small{color:var(--mut);font-size:11.5px}.c p{margin:3px 0 0;white-space:pre-wrap;overflow-wrap:anywhere}',
    'textarea.reply{min-height:64px}',
    '.asana-badge{background:#fff0f3;color:#e8384f}',
    '.asana-err{background:#fdecef;color:#b3261e;border-radius:8px;padding:8px 10px;font-size:12.5px;margin:10px 0}',
    '.back{border:0;background:none;color:var(--a);padding:0;margin-bottom:12px;font-weight:500}',

    '.hl,.sel{position:fixed;pointer-events:none;display:none;border-radius:3px;z-index:1}',
    '.hl{border:2px solid var(--a);background:rgba(91,61,245,.08);transition:all .06s}',
    '.hl span{position:absolute;left:-2px;top:-22px;background:var(--a);color:#fff;font-size:11px;padding:2px 6px;border-radius:4px 4px 0 0;white-space:nowrap;font-family:ui-monospace,Menlo,Consolas,monospace}',
    '.sel{border:2px solid var(--m);background:rgba(255,59,107,.07)}',
    '.dot{position:fixed;width:18px;height:18px;margin:-9px 0 0 -9px;border-radius:50%;background:var(--m);border:2px solid #fff;pointer-events:none;display:none;z-index:2;animation:pl 1.4s ease-out infinite}',
    '@keyframes pl{0%{box-shadow:0 0 0 0 rgba(255,59,107,.55)}100%{box-shadow:0 0 0 16px rgba(255,59,107,0)}}',
    '.pin{position:fixed;width:26px;height:26px;margin:-13px 0 0 -13px;border-radius:50%;background:var(--a);color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.3);cursor:pointer;z-index:2;padding:0}',
    '.pin.done{background:#34c759}.pin:hover{transform:scale(1.12)}',

    '.banner{position:fixed;top:16px;left:50%;transform:translateX(-50%);background:#1d1d1f;color:#fff;padding:8px 8px 8px 18px;border-radius:999px;display:none;align-items:center;gap:14px;box-shadow:0 8px 28px rgba(0,0,0,.3);z-index:5;font-size:14px;white-space:nowrap}',
    '.picking .banner{display:flex}',
    '.banner button{border:0;background:rgba(255,255,255,.15);color:#fff;border-radius:999px;padding:6px 12px}',
    '.banner button:hover{background:rgba(255,255,255,.25)}',

    '.lightbox{position:fixed;inset:0;background:rgba(0,0,0,.8);display:none;align-items:center;justify-content:center;z-index:6;padding:24px;cursor:zoom-out}',
    '.lightbox.on{display:flex}.lightbox img{max-width:100%;max-height:100%;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,.5)}',
    '.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(20px);background:#1d1d1f;color:#fff;padding:10px 16px;border-radius:10px;font-size:14px;opacity:0;transition:all .2s;pointer-events:none;z-index:7;max-width:90vw}',
    '.toast.on{opacity:1;transform:translateX(-50%)}.toast.bad{background:#c62828}'
  ].join('\n');

  var ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M12 7v6M9 10h6"/></svg>';

  /* ---------------------------------------------------------------------
   * Widget
   * ------------------------------------------------------------------ */
  function init() {
    var host = document.createElement('div');
    host.id = 'sfb-root';
    host.style.cssText = 'position:fixed;top:0;left:0;width:0;height:0;z-index:2147483000;';
    var root = host.attachShadow({ mode: 'open' });
    root.innerHTML =
      '<style>' + CSS_TEXT + '</style>' +
      '<div class="w">' +
      '  <button class="tab" data-action="toggle" title="Feedback" aria-label="Feedback">' + ICON + '<span class="count"></span></button>' +
      '  <div class="hl"><span></span></div><div class="sel"></div><div class="dot"></div><div class="pins"></div>' +
      '  <div class="banner">Klik op het element waar je feedback over hebt <button data-action="cancel-pick">Annuleren (Esc)</button></div>' +
      '  <aside class="panel" aria-label="Feedback">' +
      '    <div class="head"><strong>Feedback</strong><button class="icon-btn" data-action="close" aria-label="Sluiten">&times;</button></div>' +
      '    <div class="body"></div>' +
      '  </aside>' +
      '  <div class="lightbox" data-action="close-lightbox"><img alt=""></div>' +
      '  <div class="toast"></div>' +
      '</div>';
    document.body.appendChild(host);

    var $ = function (s) { return root.querySelector(s); };
    var wrap = $('.w'), body = $('.body'), hl = $('.hl'), sel = $('.sel'), dot = $('.dot'), pins = $('.pins');

    var state = {
      open: false,
      view: 'home',
      scope: 'page',
      showResolved: false,
      items: [],
      pageCount: 0,
      draft: null,
      shotPromise: null,
      current: null,
      track: null // item dat op de pagina gemarkeerd wordt
    };

    /* ---------- algemene UI ---------- */
    function toast(msg, bad) {
      var t = $('.toast');
      t.textContent = msg;
      t.className = 'toast on' + (bad ? ' bad' : '');
      clearTimeout(toast.timer);
      toast.timer = setTimeout(function () { t.className = 'toast'; }, 3200);
    }

    function setCount(n) {
      state.pageCount = n;
      $('.count').textContent = n > 0 ? String(n) : '';
    }

    function openPanel() { state.open = true; wrap.classList.add('open'); }
    function closePanel() {
      state.open = false;
      wrap.classList.remove('open');
      state.track = null;
      hide(sel); hide(dot);
      renderPins();
    }

    function show(el, rect) {
      el.style.display = 'block';
      if (rect) {
        el.style.left = rect.left + 'px'; el.style.top = rect.top + 'px';
        el.style.width = rect.width + 'px'; el.style.height = rect.height + 'px';
      }
    }
    function hide(el) { el.style.display = 'none'; }
    function placeDot(x, y) { dot.style.display = 'block'; dot.style.left = x + 'px'; dot.style.top = y + 'px'; }

    function loadPageCount() {
      return api('GET', '/feedback', null, { url: location.href, status: 'open' })
        .then(function (items) { setCount(items.length); return items; })
        .catch(function () {});
    }

    /* ---------- views ---------- */
    function render(view) {
      state.view = view;
      if (view !== 'form' && state.draft) { state.draft = null; hide(sel); hide(dot); }
      if (view === 'home') renderHome();
      if (view === 'list') renderList();
      if (view === 'detail') renderDetail();
      renderPins();
      body.scrollTop = 0;
    }

    function renderHome() {
      var u = cfg.user || {};
      body.innerHTML =
        (u.name ? '<p class="hi">' + (u.avatar ? '<img src="' + esc(u.avatar) + '" alt="">' : '') + 'Hoi ' + esc(u.name) + '</p>' : '') +
        '<button class="big primary" data-action="start"><span class="ic">+</span><span><b>Geef feedback</b><small>Klik een element op de pagina aan</small></span></button>' +
        '<button class="big" data-action="list"><span class="ic">☰</span><span><b>Zie feedback</b><small>' +
        (state.pageCount ? state.pageCount + ' open op deze pagina' : 'Geen open feedback op deze pagina') +
        '</small></span></button>';
    }

    function renderForm() {
      var d = state.draft;
      state.view = 'form';
      body.innerHTML =
        '<div class="shot"><span class="spin"></span>Screenshot maken…</div>' +
        '<dl class="meta">' +
        '<dt>Element</dt><dd><code>' + esc(d.selector) + '</code></dd>' +
        (d.element.text ? '<dt>Tekst</dt><dd>“' + esc(d.element.text.slice(0, 80)) + '”</dd>' : '') +
        '<dt>Positie</dt><dd>' + Math.round(d.mouse.page_x) + ', ' + Math.round(d.mouse.page_y) + ' px</dd>' +
        '<dt>Scherm</dt><dd>' + d.viewport.width + ' × ' + d.viewport.height + ' px</dd>' +
        '</dl>' +
        '<label class="lbl" for="sfb-text">Wat is er mis?</label>' +
        '<textarea id="sfb-text" placeholder="Beschrijf het probleem of je wens…"></textarea>' +
        '<div class="hint">Ctrl/Cmd + Enter om toe te voegen</div>' +
        '<div class="actions"><button class="btn" data-action="cancel-form">Annuleren</button>' +
        '<button class="btn primary grow" data-action="submit">Voeg feedback toe</button></div>';
      renderPins();
      setTimeout(function () { var ta = body.querySelector('textarea'); if (ta) ta.focus(); }, 250);
    }

    function setShot(dataUrl, error) {
      var box = body.querySelector('.shot');
      if (!box || state.view !== 'form') return;
      box.innerHTML = dataUrl
        ? '<img src="' + dataUrl + '" alt="Screenshot" data-action="zoom">'
        : '<span>Geen screenshot (' + esc(error || 'onbekende fout') + '). Je kunt de feedback wel opslaan.</span>';
    }

    function renderList() {
      body.innerHTML =
        '<div class="tabs"><button data-action="scope" data-scope="page" class="' + (state.scope === 'page' ? 'on' : '') + '">Deze pagina</button>' +
        '<button data-action="scope" data-scope="all" class="' + (state.scope === 'all' ? 'on' : '') + '">Alles</button></div>' +
        '<label class="chk"><input type="checkbox" data-action="resolved"' + (state.showResolved ? ' checked' : '') + '> Toon ook opgeloste feedback</label>' +
        '<div class="results"><div class="empty"><span class="spin" style="display:inline-block;vertical-align:middle"></span> Laden…</div></div>';

      var query = { status: state.showResolved ? 'all' : 'open' };
      if (state.scope === 'page') query.url = location.href;

      api('GET', '/feedback', null, query).then(function (items) {
        state.items = items;
        if (state.scope === 'page' && !state.showResolved) setCount(items.length);
        if (state.view !== 'list') return;
        var box = body.querySelector('.results');
        if (!items.length) {
          box.innerHTML = '<div class="empty">' + (state.scope === 'page' ? 'Nog geen feedback op deze pagina.' : 'Nog geen feedback.') + '</div>';
        } else {
          box.innerHTML = '<ul class="list">' + items.map(function (it, i) {
            var done = it.status === 'resolved';
            return '<li class="item' + (done ? ' done' : '') + '" data-action="open" data-id="' + it.id + '">' +
              '<span class="num">' + (i + 1) + '</span>' +
              (it.screenshot ? '<img class="thumb" src="' + esc(it.screenshot) + '" alt="" loading="lazy">' : '') +
              '<div class="txt"><p>' + esc(it.comment) + '</p>' +
              '<small>' + esc(it.user.name) + ' · ' + esc(it.date_human) + (state.scope === 'all' ? ' · ' + esc(pathOf(it.url)) : '') + '</small>' +
              (done ? '<span class="badge done">Opgelost</span>' : '') +
              (it.asana && it.asana.assignee ? '<small>👤 ' + esc(it.asana.assignee) + (it.asana.section ? ' · ' + esc(it.asana.section) : '') + '</small>' : '') +
              (it.asana && it.asana.comments && it.asana.comments.length ? ' <span class="badge asana-badge">💬 ' + it.asana.comments.length + '</span>' : '') +
              '</div></li>';
          }).join('') + '</ul>';
        }
        renderPins();
      }).catch(function (err) {
        var box = body.querySelector('.results');
        if (box) box.innerHTML = '<div class="empty">' + esc(err.message) + '</div>';
      });
    }

    function renderDetail() {
      var it = state.current;
      if (!it) return render('list');
      var done = it.status === 'resolved';
      var m = it.mouse || {}, v = it.viewport || {};
      var onPage = isThisPage(it);
      body.innerHTML =
        '<button class="back" data-action="list">← Terug naar overzicht</button>' +
        (it.screenshot ? '<div class="shot"><img src="' + esc(it.screenshot) + '" alt="Screenshot" data-action="zoom"></div>' : '') +
        '<p class="comment">' + esc(it.comment) + '</p>' +
        '<span class="badge' + (done ? ' done' : '') + '">' + (done ? 'Opgelost' : 'Open') + '</span>' +
        '<dl class="meta">' +
        '<dt>Door</dt><dd>' + esc(it.user.name) + ' · ' + esc(it.date_local) + '</dd>' +
        '<dt>Pagina</dt><dd><a href="' + esc(it.url) + '">' + esc(it.page_title || pathOf(it.url)) + '</a></dd>' +
        '<dt>Element</dt><dd><code>' + esc(it.selector) + '</code></dd>' +
        '<dt>Positie</dt><dd>' + Math.round(m.page_x || 0) + ', ' + Math.round(m.page_y || 0) + ' px</dd>' +
        '<dt>Scherm</dt><dd>' + esc(v.width) + ' × ' + esc(v.height) + ' px</dd>' +
        '</dl>' +
        '<div class="actions">' +
        '<button class="btn primary" data-action="goto">' + (onPage ? '◎ Toon op pagina' : 'Ga naar pagina →') + '</button>' +
        '<button class="btn" data-action="status">' + (done ? 'Heropen' : '✓ Markeer opgelost') + '</button>' +
        (!(it.asana && it.asana.gid) && cfg.asana ? '<button class="btn" data-action="asana">+ Asana-taak</button>' : '') +
        '<a class="btn" href="' + esc(it.edit_link) + '" target="_blank" rel="noopener">Bewerk in WP</a>' +
        '<button class="btn danger" data-action="delete">Verwijder</button>' +
        '</div>' +
        renderAsana(it);
      if (onPage) highlight(it, false);
    }

    /** Blok met wat er in Asana met deze feedback gebeurt: status, wie, wanneer, reacties. */
    function renderAsana(it) {
      var a = it.asana || {};
      if (!a.gid) {
        if (a.error) {
          return '<div class="asana"><div class="asana-head"><b>Asana</b><span class="badge">Niet verstuurd</span></div>' +
            '<p class="asana-err">' + esc(a.error) + '</p>' +
            (cfg.asana ? '<button class="btn" data-action="asana">Opnieuw proberen</button>' : '') + '</div>';
        }
        return a.deleted ? '<div class="asana"><div class="asana-head"><b>Asana</b></div><p class="hint">De taak is in Asana verwijderd.</p></div>' : '';
      }
      var comments = a.comments || [];
      return '<div class="asana">' +
        '<div class="asana-head"><b>Asana</b><span class="badge' + (a.completed ? ' done' : '') + '">' + (a.completed ? 'Voltooid' : 'Open') + '</span>' +
        '<a href="' + esc(a.url) + '" target="_blank" rel="noopener">Open taak ↗</a></div>' +
        '<dl class="meta">' +
        '<dt>Toegewezen</dt><dd>' + esc(a.assignee || '—') + '</dd>' +
        '<dt>Deadline</dt><dd>' + esc(a.due_on || '—') + '</dd>' +
        (a.section ? '<dt>Sectie</dt><dd>' + esc(a.section) + '</dd>' : '') +
        '</dl>' +
        '<div class="comments">' + (comments.length ? comments.map(function (c) {
          return '<div class="c"><b>' + esc(c.author) + '</b> <small>' + esc(c.date) + '</small><p>' + esc(c.text) + '</p></div>';
        }).join('') : '<p class="hint">Nog geen reacties in Asana.</p>') + '</div>' +
        '<textarea class="reply" placeholder="Reageer… (komt als reactie in Asana)"></textarea>' +
        '<div class="actions"><span class="hint grow" style="justify-content:flex-start">' + (a.synced ? 'Bijgewerkt ' + esc(a.synced) : '') + '</span>' +
        '<button class="btn" data-action="reply">Plaats reactie</button></div>' +
        '</div>';
    }

    /* ---------- pins & highlight op de pagina ---------- */
    function renderPins() {
      var showPins = state.open && (state.view === 'list' || state.view === 'detail');
      if (!showPins) { pins.innerHTML = ''; return; }
      var html = '';
      state.items.forEach(function (it, i) {
        if (!isThisPage(it)) return;
        var p = itemPoint(it);
        if (p.x < -30 || p.y < -30 || p.x > window.innerWidth + 30 || p.y > window.innerHeight + 30) return;
        html += '<button class="pin' + (it.status === 'resolved' ? ' done' : '') + '" data-action="open" data-id="' + it.id + '" style="left:' + p.x + 'px;top:' + p.y + 'px" title="' + esc(it.comment.slice(0, 80)) + '">' + (i + 1) + '</button>';
      });
      pins.innerHTML = html;
    }

    function updateTrack() {
      if (!state.track) return;
      var p = itemPoint(state.track);
      // Alleen de omlijning; de stip alleen als het element niet meer bestaat.
      if (p.rect) { show(sel, p.rect); hide(dot); } else { hide(sel); placeDot(p.x, p.y); }
    }

    function highlight(item, scroll) {
      state.track = item;
      var p = itemPoint(item);
      if (scroll !== false) {
        if (p.el) p.el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        else window.scrollTo({ top: Math.max(0, ((item.mouse && item.mouse.page_y) || 0) - window.innerHeight / 2), behavior: 'smooth' });
        if (!p.el) toast('Element niet meer gevonden; de oorspronkelijke klikpositie wordt getoond.');
      }
      updateTrack();
    }

    var raf = null;
    function onScroll() {
      if (raf) return;
      raf = requestAnimationFrame(function () {
        raf = null;
        if (state.draft && state.draft._el) show(sel, state.draft._el.getBoundingClientRect());
        updateTrack();
        renderPins();
      });
    }
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', onScroll);

    /* ---------- element aanwijzen ---------- */
    var picking = false;
    var cursorStyle = null;

    function isOwn(t) { return t === host || host.contains(t); }

    function startPick() {
      picking = true;
      closePanel();
      wrap.classList.add('picking');
      cursorStyle = document.createElement('style');
      cursorStyle.textContent = '*{cursor:crosshair!important}';
      document.head.appendChild(cursorStyle);
      window.addEventListener('mousemove', onMove, true);
      window.addEventListener('click', onPickClick, true);
      ['mousedown', 'mouseup', 'pointerdown', 'pointerup', 'touchstart'].forEach(function (ev) {
        window.addEventListener(ev, swallow, true);
      });
    }

    function stopPick() {
      picking = false;
      wrap.classList.remove('picking');
      hide(hl);
      if (cursorStyle) { cursorStyle.remove(); cursorStyle = null; }
      window.removeEventListener('mousemove', onMove, true);
      window.removeEventListener('click', onPickClick, true);
      ['mousedown', 'mouseup', 'pointerdown', 'pointerup', 'touchstart'].forEach(function (ev) {
        window.removeEventListener(ev, swallow, true);
      });
    }

    function swallow(e) {
      if (isOwn(e.target)) return;
      e.preventDefault();
      e.stopPropagation();
    }

    function onMove(e) {
      var t = e.target;
      if (isOwn(t) || t === document.documentElement) { hide(hl); return; }
      show(hl, t.getBoundingClientRect());
      hl.firstChild.textContent = t.tagName.toLowerCase() + (t.id ? '#' + t.id : '') +
        (typeof t.className === 'string' && t.className.trim() ? '.' + t.className.trim().split(/\s+/).slice(0, 2).join('.') : '');
    }

    function onPickClick(e) {
      if (isOwn(e.target)) return;
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
      stopPick();
      select(e.target, e);
    }

    function select(el, e) {
      var rect = el.getBoundingClientRect();
      var ox = e.clientX - rect.left, oy = e.clientY - rect.top;
      var draft = {
        url: location.href,
        page_title: document.title,
        selector: getSelector(el),
        element: describe(el),
        mouse: {
          client_x: e.clientX, client_y: e.clientY,
          page_x: e.pageX, page_y: e.pageY,
          offset_x: ox, offset_y: oy,
          pct_x: rect.width ? ox / rect.width * 100 : 0,
          pct_y: rect.height ? oy / rect.height * 100 : 0
        },
        viewport: {
          width: window.innerWidth, height: window.innerHeight,
          dpr: window.devicePixelRatio || 1,
          scroll_x: window.scrollX, scroll_y: window.scrollY
        },
        user_agent: navigator.userAgent,
        screenshot: null
      };
      Object.defineProperty(draft, '_el', { value: el, enumerable: false });

      state.draft = draft;
      state.track = null;
      show(sel, rect);

      state.shotPromise = takeScreenshot(draft, rect).then(function (dataUrl) {
        if (state.draft === draft) { draft.screenshot = dataUrl; setShot(dataUrl); }
      }, function (err) {
        console.warn('[Site Feedback] screenshot mislukt', err);
        if (state.draft === draft) setShot(null, err && err.message);
      });

      openPanel();
      renderForm();
    }

    function roundRect(ctx, x, y, w, h, r) {
      r = Math.min(r, w / 2, h / 2);
      ctx.moveTo(x + r, y);
      ctx.arcTo(x + w, y, x + w, y + h, r);
      ctx.arcTo(x + w, y + h, x, y + h, r);
      ctx.arcTo(x, y + h, x, y, r);
      ctx.arcTo(x, y, x + w, y, r);
      ctx.closePath();
    }

    function labelFor(el) {
      var cls = el.classes ? '.' + el.classes.split(/\s+/)[0] : '';
      var label = el.tag + (el.id ? '#' + el.id : cls);
      return label.length > 40 ? label.slice(0, 39) + '…' : label;
    }

    /**
     * Tekent op de screenshot: gedimde omgeving en een omlijning + label om het element.
     * Coördinaten zijn viewport-pixels (CSS px); s = canvas-pixels per CSS px.
     */
    function annotate(canvas, s, vw, vh, rect, draft) {
      var ctx = canvas.getContext('2d');
      var pad = 4;
      var x1 = Math.max(2, rect.left - pad), y1 = Math.max(2, rect.top - pad);
      var x2 = Math.min(vw - 2, rect.right + pad), y2 = Math.min(vh - 2, rect.bottom + pad);
      var hasBox = x2 > x1 && y2 > y1;

      ctx.save();
      // html2canvas laat zijn eigen transformatie (translate(-x, -y) van de uitsnede) op het canvas staan.
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.scale(s, s);

      // 1. Rest van de pagina dimmen, zodat het element eruit springt.
      ctx.beginPath();
      ctx.rect(0, 0, vw, vh);
      if (hasBox) roundRect(ctx, x1, y1, x2 - x1, y2 - y1, 6);
      ctx.fillStyle = 'rgba(15,15,25,.42)';
      ctx.fill('evenodd');

      if (hasBox) {
        // 2. Omlijning: witte rand onder een gekleurde lijn (zichtbaar op elke achtergrond).
        ctx.beginPath();
        roundRect(ctx, x1, y1, x2 - x1, y2 - y1, 6);
        ctx.lineWidth = 7; ctx.strokeStyle = '#fff'; ctx.stroke();
        ctx.lineWidth = 3.5; ctx.strokeStyle = MARK; ctx.stroke();

        // 3. Label met de elementnaam, boven het element (of eronder als er geen ruimte is).
        var label = labelFor(draft.element);
        ctx.font = '600 12px ui-monospace, Menlo, Consolas, monospace';
        var tw = ctx.measureText(label).width + 14, th = 22;
        var lx = Math.min(Math.max(2, x1), vw - tw - 2);
        var ly = y1 - th - 5;
        if (ly < 2) ly = y2 + 5 + th < vh ? y2 + 5 : y1 + 5;
        ctx.beginPath();
        roundRect(ctx, lx, ly, tw, th, 5);
        ctx.fillStyle = MARK; ctx.fill();
        ctx.fillStyle = '#fff';
        ctx.textBaseline = 'middle';
        ctx.fillText(label, lx + 7, ly + th / 2 + 1);
      }

      ctx.restore();
    }

    /**
     * html2canvas tekent een kopie van de pagina in een verborgen iframe. Thema-CSS zoals
     * `iframe { height: auto }` (veel voorkomend op mobiel) maakt dat iframe 150px hoog, waardoor de kopie
     * een andere opbouw krijgt en de screenshot een verkeerd stuk van de pagina toont. Daarom zetten we
     * de maat van dat iframe vast zolang de screenshot loopt.
     */
    function lockCloneFrameSize(width, height) {
      var style = document.createElement('style');
      style.id = 'sfb-h2c-size';
      style.textContent = 'iframe.html2canvas-container{' +
        'width:' + width + 'px!important;height:' + height + 'px!important;' +
        'min-width:0!important;max-width:none!important;min-height:0!important;max-height:none!important;' +
        'margin:0!important;padding:0!important;border:0!important;transform:none!important}';
      (document.head || document.documentElement).appendChild(style);
      return function () { if (style.parentNode) style.parentNode.removeChild(style); };
    }

    /**
     * CSS die via JavaScript is toegevoegd met `document.adoptedStyleSheets` (bijv. door Cookiebot, web components
     * of moderne thema's) neemt html2canvas niet mee naar de kopie. Onderdelen die daarmee zijn opgemaakt,
     * klappen dan in of rekken uit, waardoor de kopie een andere lengte krijgt. Daarom zetten we die CSS
     * als gewone <style> in de kopie. Adopted sheets gelden na de gewone CSS, dus achteraan.
     */
    function copyAdoptedStyles(doc) {
      var sheets = document.adoptedStyleSheets;
      if (!sheets || !sheets.length) return;
      var css = '';
      for (var i = 0; i < sheets.length; i++) {
        try {
          for (var j = 0; j < sheets[i].cssRules.length; j++) css += sheets[i].cssRules[j].cssText + '\n';
        } catch (e) { /* niet leesbaar: overslaan */ }
      }
      if (!css) return;
      var style = doc.createElement('style');
      style.textContent = css;
      (doc.head || doc.documentElement).appendChild(style);
    }

    function takeScreenshot(draft, rect) {
      var v = draft.viewport;
      var width = document.documentElement.clientWidth || v.width;
      var el = draft._el;
      var unlock = function () {};
      var cleanup = function () {
        unlock();
        if (el && el.removeAttribute) el.removeAttribute('data-sfb-target');
      };
      return loadHtml2Canvas().then(function (h2c) {
        unlock = lockCloneFrameSize(width, v.height);
        if (el && el.setAttribute) el.setAttribute('data-sfb-target', '');
        // html2canvas rekent met de scrollpositie op het moment van aanroepen.
        var wx = window.scrollX, wy = window.scrollY;
        var opts = {
          x: v.scroll_x, y: v.scroll_y,
          width: width, height: v.height,
          windowWidth: width, windowHeight: v.height,
          scale: 1,
          useCORS: true,
          logging: false,
          ignoreElements: function (n) { return n === host || n.id === 'sfb-root' || n.id === 'sfb-h2c-size'; },
          onclone: function (doc) {
            copyAdoptedStyles(doc);

            // Bij `scroll-behavior: smooth` scrolt de kopie geanimeerd en staat hij nog bovenaan als er getekend wordt.
            doc.documentElement.style.setProperty('scroll-behavior', 'auto', 'important');
            if (doc.body) doc.body.style.setProperty('scroll-behavior', 'auto', 'important');
            if (doc.defaultView) doc.defaultView.scrollTo(v.scroll_x, v.scroll_y);

            // Vangnet: wijkt de kopie toch af van de echte pagina (andere scrollpositie of opbouw), dan leggen we
            // de uitsnede zo dat het aangeklikte element op dezelfde plek staat als waar de gebruiker het zag.
            // html2canvas leest opts.x/y pas na onclone.
            var target = doc.querySelector('[data-sfb-target]');
            if (!target) return;
            var r = target.getBoundingClientRect();
            if (!r.width && !r.height) return;
            opts.x = Math.max(0, r.left + wx - rect.left);
            opts.y = Math.max(0, r.top + wy - rect.top);
          }
        };
        return h2c(document.documentElement, opts);
      }).then(function (canvas) {
        cleanup();
        return canvas;
      }, function (err) {
        cleanup();
        throw err;
      }).then(function (canvas) {
        annotate(canvas, canvas.width / width, width, v.height, rect, draft);

        // Maximaal 1600px breed, als JPEG
        var out = canvas;
        if (canvas.width > 1600) {
          out = document.createElement('canvas');
          out.width = 1600;
          out.height = Math.round(canvas.height * 1600 / canvas.width);
          out.getContext('2d').drawImage(canvas, 0, 0, out.width, out.height);
        }
        return out.toDataURL('image/jpeg', 0.82);
      });
    }

    function submit() {
      var ta = body.querySelector('textarea');
      var btn = body.querySelector('[data-action="submit"]');
      var comment = ta.value.trim();
      if (!comment) { ta.classList.add('err'); ta.focus(); return; }

      var draft = state.draft;
      btn.disabled = true;
      btn.textContent = draft.screenshot ? 'Opslaan…' : 'Screenshot afronden…';

      (state.shotPromise || Promise.resolve()).then(function () {
        btn.textContent = 'Opslaan…';
        var payload = JSON.parse(JSON.stringify(draft));
        payload.comment = comment;
        return api('POST', '/feedback', payload);
      }).then(function (item) {
        if (item && item.warning) toast(item.warning, true);
        else toast('Feedback toegevoegd' + (item && item.asana && item.asana.gid ? ' en naar Asana gestuurd' : '') + ' ✓');
        state.scope = 'page';
        state.showResolved = false;
        render('list');
      }).catch(function (err) {
        btn.disabled = false;
        btn.textContent = 'Voeg feedback toe';
        toast(err.message, true);
      });
    }

    /* ---------- acties ---------- */
    function openItem(id) {
      var it = state.items.filter(function (x) { return String(x.id) === String(id); })[0];
      if (!it) return;
      state.current = it;
      if (!state.open) openPanel();
      render('detail');
      refreshFromAsana(it);
    }

    /** Haalt de laatste stand uit Asana op en ververst de detailweergave (tenzij je net aan het typen bent). */
    function refreshFromAsana(it) {
      if (!it.asana || !it.asana.gid) return;
      api('GET', '/feedback/' + it.id, null, { sync: 1 }).then(function (fresh) {
        replaceItem(fresh);
        var reply = body.querySelector('.reply');
        if (state.view === 'detail' && state.current.id === fresh.id && !(reply && reply.value)) render('detail');
        if (it.status !== fresh.status) {
          toast(fresh.status === 'resolved' ? 'In Asana afgerond → feedback opgelost ✓' : 'In Asana heropend → feedback weer open');
          loadPageCount();
        }
      }).catch(function (err) {
        if (err.status !== 404 || !state.current || state.current.id !== it.id) return;
        // De taak is in Asana verwijderd, dus de feedback staat nu in de prullenbak.
        toast('Deze feedback is in Asana verwijderd en staat nu in de prullenbak');
        state.items = state.items.filter(function (x) { return x.id !== it.id; });
        state.track = null; hide(sel); hide(dot);
        loadPageCount();
        render('list');
      });
    }

    function sendReply() {
      var ta = body.querySelector('.reply');
      var btn = body.querySelector('[data-action="reply"]');
      var text = ta.value.trim();
      if (!text) { ta.classList.add('err'); ta.focus(); return; }
      btn.disabled = true;
      btn.textContent = 'Plaatsen…';
      api('POST', '/feedback/' + state.current.id + '/comment', { text: text })
        .then(function (it) { replaceItem(it); toast('Reactie geplaatst in Asana ✓'); render('detail'); })
        .catch(function (err) { btn.disabled = false; btn.textContent = 'Plaats reactie'; toast(err.message, true); });
    }

    function replaceItem(updated) {
      state.items = state.items.map(function (x) { return x.id === updated.id ? updated : x; });
      state.current = updated;
    }

    function handle(action, el) {
      switch (action) {
        case 'toggle':
          if (state.open) closePanel(); else { openPanel(); render('home'); }
          break;
        case 'close': closePanel(); break;
        case 'start': startPick(); break;
        case 'cancel-pick': stopPick(); openPanel(); render('home'); break;
        case 'cancel-form': render('home'); break;
        case 'submit': submit(); break;
        case 'list': render('list'); break;
        case 'scope': state.scope = el.getAttribute('data-scope'); render('list'); break;
        case 'resolved': state.showResolved = el.checked; render('list'); break;
        case 'open': openItem(el.getAttribute('data-id')); break;
        case 'zoom':
          $('.lightbox img').src = el.src;
          $('.lightbox').classList.add('on');
          break;
        case 'close-lightbox': $('.lightbox').classList.remove('on'); break;
        case 'goto':
          if (isThisPage(state.current)) highlight(state.current, true);
          else location.href = state.current.url.split('#')[0] + '#sfb-' + state.current.id;
          break;
        case 'status':
          el.disabled = true;
          api('POST', '/feedback/' + state.current.id, { status: state.current.status === 'resolved' ? 'open' : 'resolved' })
            .then(function (it) {
              replaceItem(it);
              if (it.warning) toast(it.warning, true);
              else toast(it.status === 'resolved'
                ? 'Gemarkeerd als opgelost' + (it.asana.gid ? ' (taak voltooid in Asana)' : '')
                : 'Feedback heropend' + (it.asana.gid ? ' (ook in Asana)' : ''));
              loadPageCount();
              render('detail');
            })
            .catch(function (err) { el.disabled = false; toast(err.message, true); });
          break;
        case 'asana':
          el.disabled = true;
          el.textContent = 'Taak aanmaken…';
          api('POST', '/feedback/' + state.current.id + '/asana')
            .then(function (it) { replaceItem(it); toast('Asana-taak aangemaakt ✓'); render('detail'); })
            .catch(function (err) { el.disabled = false; el.textContent = '+ Asana-taak'; toast(err.message, true); });
          break;
        case 'reply':
          sendReply();
          break;
        case 'delete':
          if (!window.confirm('Deze feedback verwijderen?\n\nHij gaat naar de prullenbak in WordPress' +
            (state.current.asana && state.current.asana.gid ? ' en de taak in Asana wordt ook verwijderd' : '') +
            '. Beide zijn 30 dagen terug te halen.')) return;
          api('DELETE', '/feedback/' + state.current.id)
            .then(function (res) {
              if (res && res.warning) toast(res.warning, true);
              else toast('Feedback verwijderd' + (res && res.asana_deleted ? ', ook in Asana' : ''));
              state.track = null; hide(sel); hide(dot);
              loadPageCount();
              render('list');
            })
            .catch(function (err) { toast(err.message, true); });
          break;
      }
    }

    root.addEventListener('click', function (e) {
      var el = e.target.closest('[data-action]');
      if (!el) return;
      var action = el.getAttribute('data-action');
      if (action === 'resolved') return; // afgehandeld via change
      if (el.tagName === 'A') return;
      e.preventDefault();
      handle(action, el);
    });
    root.addEventListener('change', function (e) {
      if (e.target.getAttribute('data-action') === 'resolved') handle('resolved', e.target);
    });

    // Toetsenbord: niet laten doorlekken naar het thema, en sneltoetsen afhandelen.
    root.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && state.view === 'form') { e.preventDefault(); submit(); }
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && state.view === 'detail' && e.composedPath()[0].classList.contains('reply')) { e.preventDefault(); sendReply(); }
      if (e.key === 'Escape') onEscape();
      e.stopPropagation();
    });
    ['keyup', 'keypress'].forEach(function (ev) { root.addEventListener(ev, function (e) { e.stopPropagation(); }); });
    window.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !isOwn(e.target)) onEscape();
    }, true);

    function onEscape() {
      if ($('.lightbox').classList.contains('on')) { $('.lightbox').classList.remove('on'); return; }
      if (picking) { stopPick(); openPanel(); render('home'); return; }
      if (state.open) closePanel();
    }

    /* ---------- start ---------- */
    loadPageCount();

    // Terug naar dit tabblad/venster (bijv. na een taak op "done" te zetten in Asana)? Direct verversen.
    var lastRefresh = Date.now();
    function onReturn() {
      if (document.visibilityState !== 'visible' || picking || Date.now() - lastRefresh < 3000) return;
      lastRefresh = Date.now();
      if (state.open && state.view === 'detail' && state.current) refreshFromAsana(state.current);
      else if (state.open && state.view === 'list') render('list');
      else loadPageCount();
    }
    document.addEventListener('visibilitychange', onReturn);
    window.addEventListener('focus', onReturn);

    // Link als https://site.nl/pagina#sfb-123 opent direct dat feedback-item.
    var m = location.hash.match(/^#sfb-(\d+)$/);
    if (m) {
      history.replaceState(history.state, '', location.pathname + location.search);
      api('GET', '/feedback/' + m[1]).then(function (it) {
        state.items = [it];
        state.current = it;
        openPanel();
        render('detail');
        refreshFromAsana(it);
        var go =function () { setTimeout(function () { highlight(it, true); }, 300); };
        if (document.readyState === 'complete') go(); else window.addEventListener('load', go);
      }).catch(function (err) { toast(err.message, true); });
    }
  }

  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  ready(init);
})();
