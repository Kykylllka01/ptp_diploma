/* ==========================================================================
   Общий код всех экранов: помощники, иконки, тосты и запросы к API.
   Данные страницы приходят от контроллера в window.APP_DATA (та же форма,
   что была у window.MOCK в статическом фронте), изменения уходят в /api/...
   Шапка рисуется Blade-шаблоном (resources/views/partials/header-*.blade.php).
   ========================================================================== */
(function () {
  'use strict';

  var M = window.APP_DATA || {};

  /* ---------- Помощники ---------- */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function chip(text, tone, extra) {
    return '<span class="chip' + (tone && tone !== 'gray' ? ' chip--' + tone : '') + (extra ? ' ' + extra : '') + '">' + esc(text) + '</span>';
  }
  function plural(n, one, few, many) {
    var m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return one;
    if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return few;
    return many;
  }
  function param(name) { return new URLSearchParams(location.search).get(name); }
  function url(path) { return '/' + String(path).replace(/^\/+/, ''); }

  var icons = {
    upload: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M4 20h16"/></svg>',
    plus: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
    check: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
    cross: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    warn: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#B86A00" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 8v5M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>',
    ok: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#1F7A4D" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
    info: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0072BC" stroke-width="2" stroke-linecap="round" aria-hidden="true" style="flex:none"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>',
    search: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#5A6070" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>',
    flag: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 21V4M5 4h12l-2 4 2 4H5"/></svg>',
    clock: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2M9 2h6"/></svg>',
    x: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#5A6070" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>'
  };

  /* ---------- Тост ---------- */
  var toastEl, toastTimer;
  function toast(text, isError) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'toast';
      toastEl.setAttribute('role', 'status');
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = text;
    toastEl.classList.add('is-show');
    clearTimeout(toastTimer);
    toastEl.classList.toggle('is-error', !!isError);
    toastTimer = setTimeout(function () { toastEl.classList.remove('is-show'); }, isError ? 7000 : 2600);
  }

  /* ---------- Запросы к API ----------
     api('PATCH', 'questions/Q-121', {...}) → Promise с JSON ответа.
     body может быть FormData (загрузка файлов). Ошибки показываются тостом. */
  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  function csrf() { return csrfMeta ? csrfMeta.getAttribute('content') : ''; }

  // Сессия закончилась: отправляем на вход
  function authFailed(status) {
    if (status === 401) { location.href = '/login'; return true; }
    if (status === 419) { toast('Сессия устарела — обновите страницу'); return true; }
    return false;
  }

  function api(method, path, body) {
    var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() };
    var opts = { method: method, headers: headers, credentials: 'same-origin' };
    if (body instanceof FormData) opts.body = body;
    else if (body !== undefined) { headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    return fetch('/api/' + path, opts).then(function (r) {
      if (r.status === 204) return null;
      if (authFailed(r.status)) throw new Error('auth');
      return r.json().catch(function () { return {}; }).then(function (data) {
        if (!r.ok) {
          // Laravel кладёт подробности проверки в errors: первая ошибка понятнее общего «The given data…»
          var first = data && data.errors ? Object.keys(data.errors).map(function (k) { return data.errors[k][0]; })[0] : null;
          var msg = first || (data && data.message) || ('Ошибка сервера: ' + r.status);
          toast(msg, true);
          var err = new Error(msg); err.status = r.status; err.data = data;
          throw err;
        }
        return data;
      });
    });
  }

  /* ---------- Данные банка и очереди проверки ----------
     Изменения сразу применяются к локальной копии (экран перерисовывается без ожидания),
     а затем уходят на сервер. */
  function questions() { return M.questions || []; }
  function findQuestion(id) { return questions().filter(function (q) { return q.id === id; })[0]; }
  // Правка видна сразу, но если сервер её не принял — экран возвращается к прежним данным
  function snapshot(obj, patch) {
    var before = {};
    Object.keys(patch).forEach(function (k) { before[k] = obj[k]; });
    return before;
  }
  function saveQuestion(id, patch) {
    var q = findQuestion(id), before = q ? snapshot(q, patch) : null;
    if (q) Object.assign(q, patch);
    return api('PATCH', 'questions/' + encodeURIComponent(id), patch).then(function (fresh) {
      if (q && fresh) Object.assign(q, fresh);
      return fresh;
    }, function (err) {
      if (q) Object.assign(q, before);
      throw err;
    });
  }
  function reviews() { return M.reviews || []; }
  function saveReview(id, patch) {
    var r = reviews().filter(function (x) { return x.id === id; })[0], before = r ? snapshot(r, patch) : null;
    if (r) Object.assign(r, patch);
    return api('PATCH', 'reviews/' + id, { scores: patch.scores, comment: patch.comment }).catch(function (err) {
      if (r) Object.assign(r, before);
      throw err;
    });
  }

  // Переключатели-«свитчи»
  document.addEventListener('click', function (e) {
    var sw = e.target.closest('.sw');
    if (sw) sw.setAttribute('aria-pressed', sw.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
  });

  // Исходные данные вопроса (схема отношения, зависимости, строки таблицы) — над формулировкой
  function context(text) {
    return text ? '<pre class="q-context">' + esc(text) + '</pre>' : '';
  }

  window.App = {
    M: M, esc: esc, context: context, $: $, $$: $$, chip: chip, plural: plural, param: param, url: url,
    icons: icons, toast: toast, api: api, csrf: csrf, authFailed: authFailed,
    questions: questions, saveQuestion: saveQuestion, reviews: reviews, saveReview: saveReview
  };
})();
