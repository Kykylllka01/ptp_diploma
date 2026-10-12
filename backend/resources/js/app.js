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

  /* ---------- Выпадающие списки в стиле сайта ----------
     Каждый <select class="select"> получает свою кнопку и список. Сам select остаётся в DOM (скрытым):
     формы отправляют его значение, а код страниц по-прежнему читает .value и слушает change.
     Списки, которые страницы дорисовывают позже (innerHTML), подхватываются наблюдателем. */
  var CHEVRON = '<svg class="cselect__chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
  var openSelect = null;
  var uid = 0;

  function enhanceSelect(sel) {
    if (sel.__cs || sel.multiple || sel.hasAttribute('data-native')) return;
    var wrap = document.createElement('div');
    wrap.className = 'cselect' + (sel.classList.contains('select--sm') ? ' cselect--sm' : '');
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'cselect__btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    var list = document.createElement('ul');
    list.className = 'cselect__list';
    list.setAttribute('role', 'listbox');
    list.id = 'cs-' + (++uid);
    list.hidden = true;
    btn.setAttribute('aria-controls', list.id);
    wrap.appendChild(btn);
    wrap.appendChild(list);
    // Внешний вид, заданный прямо у select (ширина, отступ, «пилюля»), переносим на новую кнопку
    var style = sel.getAttribute('style') || '';
    if (style) btn.setAttribute('style', style);
    if (/width:\s*auto/.test(style)) wrap.classList.add('cselect--auto');
    sel.parentNode.insertBefore(wrap, sel);
    sel.classList.add('cselect__native');
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');

    // Подпись: aria-label select или <label for>, иначе текст обёртки-label
    var label = sel.getAttribute('aria-label');
    if (label) btn.setAttribute('aria-label', label);
    else if (sel.id && document.querySelector('label[for="' + sel.id + '"]')) {
      var lab = document.querySelector('label[for="' + sel.id + '"]');
      if (!lab.id) lab.id = 'cs-lbl-' + uid;
      btn.setAttribute('aria-labelledby', lab.id + ' ' + list.id);
      lab.addEventListener('click', function (e) { e.preventDefault(); btn.focus(); });
    }

    var active = -1;
    function options() { return Array.prototype.slice.call(sel.options); }
    function sync() {
      var o = sel.options[sel.selectedIndex];
      btn.innerHTML = '<span class="cselect__text">' + esc(o ? o.text : '') + '</span>' + CHEVRON;
      btn.disabled = sel.disabled;
      wrap.hidden = sel.hidden;
    }
    function build() {
      list.innerHTML = options().map(function (o, i) {
        return '<li role="option" id="' + list.id + '-' + i + '" data-i="' + i + '" aria-selected="' + (i === sel.selectedIndex) + '"' +
          (o.disabled ? ' aria-disabled="true"' : '') + '>' + esc(o.text) + '</li>';
      }).join('');
      sync();
    }
    function highlight(i) {
      var items = list.children;
      if (!items.length) return;
      active = Math.max(0, Math.min(items.length - 1, i));
      Array.prototype.forEach.call(items, function (li, k) { li.classList.toggle('is-active', k === active); });
      btn.setAttribute('aria-activedescendant', items[active].id);
      var li = items[active];
      if (li.offsetTop < list.scrollTop) list.scrollTop = li.offsetTop;
      else if (li.offsetTop + li.offsetHeight > list.scrollTop + list.clientHeight) list.scrollTop = li.offsetTop + li.offsetHeight - list.clientHeight;
    }
    function open() {
      if (sel.disabled) return;
      if (openSelect && openSelect !== api) openSelect.close();
      build();
      list.hidden = false;
      wrap.classList.add('is-open');
      btn.setAttribute('aria-expanded', 'true');
      // Не помещается снизу — раскрываем вверх
      var r = btn.getBoundingClientRect();
      wrap.classList.toggle('is-up', window.innerHeight - r.bottom < Math.min(list.scrollHeight, 280) + 12 && r.top > window.innerHeight - r.bottom);
      openSelect = api;
      highlight(sel.selectedIndex);
    }
    function close(focus) {
      list.hidden = true;
      wrap.classList.remove('is-open');
      btn.setAttribute('aria-expanded', 'false');
      btn.removeAttribute('aria-activedescendant');
      if (openSelect === api) openSelect = null;
      if (focus) btn.focus();
    }
    function choose(i) {
      var o = sel.options[i];
      if (!o || o.disabled) return;
      var changed = sel.selectedIndex !== i;
      sel.selectedIndex = i;
      sync();
      close(true);
      if (changed) {
        sel.dispatchEvent(new Event('input', { bubbles: true }));
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
    var api = { close: close, wrap: wrap };

    btn.addEventListener('click', function () { list.hidden ? open() : close(); });
    list.addEventListener('mousedown', function (e) { e.preventDefault(); });
    list.addEventListener('click', function (e) {
      // Список внутри <label class="field">: без этого клик по пункту «нажал» бы подпись и снова открыл список
      e.preventDefault();
      var li = e.target.closest('li');
      if (li) choose(+li.getAttribute('data-i'));
    });
    list.addEventListener('mousemove', function (e) {
      var li = e.target.closest('li');
      if (li && +li.getAttribute('data-i') !== active) highlight(+li.getAttribute('data-i'));
    });
    var typed = '', typedAt = 0;
    btn.addEventListener('keydown', function (e) {
      var k = e.key;
      if (list.hidden) {
        if (k === 'ArrowDown' || k === 'ArrowUp' || k === 'Enter' || k === ' ') { e.preventDefault(); open(); }
        return;
      }
      if (k === 'ArrowDown') { e.preventDefault(); highlight(active + 1); }
      else if (k === 'ArrowUp') { e.preventDefault(); highlight(active - 1); }
      else if (k === 'Home') { e.preventDefault(); highlight(0); }
      else if (k === 'End') { e.preventDefault(); highlight(list.children.length - 1); }
      else if (k === 'Enter' || k === ' ') { e.preventDefault(); choose(active); }
      else if (k === 'Escape') { e.preventDefault(); close(true); }
      else if (k === 'Tab') close();
      else if (k.length === 1) {
        // Поиск по первым буквам, как у обычного списка
        typed = (Date.now() - typedAt > 700 ? '' : typed) + k.toLowerCase();
        typedAt = Date.now();
        var hit = options().findIndex(function (o) { return o.text.toLowerCase().indexOf(typed) === 0; });
        if (hit >= 0) highlight(hit);
      }
    });

    // Код страницы меняет select.value, innerHTML, hidden или disabled — кнопка и список следуют за ним
    var proto = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
    Object.defineProperty(sel, 'value', {
      configurable: true,
      get: function () { return proto.get.call(sel); },
      set: function (v) { proto.set.call(sel, v); sync(); }
    });
    var idx = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'selectedIndex');
    Object.defineProperty(sel, 'selectedIndex', {
      configurable: true,
      get: function () { return idx.get.call(sel); },
      set: function (v) { idx.set.call(sel, v); sync(); }
    });
    new MutationObserver(function () { list.hidden ? sync() : build(); })
      .observe(sel, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['hidden', 'disabled'] });
    sel.addEventListener('change', sync);
    sel.__cs = api;
    sync();
  }

  function enhanceAll(root) {
    if (root.matches && root.matches('select.select')) enhanceSelect(root);
    if (root.querySelectorAll) Array.prototype.forEach.call(root.querySelectorAll('select.select'), enhanceSelect);
  }
  document.addEventListener('click', function (e) {
    if (openSelect && !openSelect.wrap.contains(e.target)) openSelect.close();
  });
  // На телефоне resize приходит и при прокрутке (прячется адресная строка) — закрываем только при смене ширины
  var lastWidth = window.innerWidth;
  window.addEventListener('resize', function () {
    if (window.innerWidth !== lastWidth && openSelect) openSelect.close();
    lastWidth = window.innerWidth;
  });
  function startSelects() {
    enhanceAll(document);
    new MutationObserver(function (records) {
      records.forEach(function (r) { Array.prototype.forEach.call(r.addedNodes, function (n) { if (n.nodeType === 1) enhanceAll(n); }); });
    }).observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startSelects);
  else startSelects();

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
