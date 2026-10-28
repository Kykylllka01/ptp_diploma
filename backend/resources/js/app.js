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
    x: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#5A6070" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    more: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.9"/><circle cx="12" cy="12" r="1.9"/><circle cx="19" cy="12" r="1.9"/></svg>',
    copy: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></svg>',
    external: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>',
    download: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M4 20h16"/></svg>',
    chevron: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>'
  };
  // Число по-русски: 12,5 вместо 12.5; null — пусто
  function num(v) { return v == null ? '' : String(v).replace('.', ','); }

  /* ---------- Тост ---------- */
  var toastEl, toastTimer, liveStatus, liveAlert;
  function live(role) {
    var el = document.createElement('div');
    el.className = 'sr-only';
    el.setAttribute('role', role);
    el.setAttribute('aria-atomic', 'true');
    document.body.appendChild(el);
    return el;
  }
  function toast(text, isError) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'toast';
      toastEl.setAttribute('aria-hidden', 'true');   // зачитывают живые области ниже
      liveStatus = live('status');
      liveAlert = live('alert');
    }
    // Открыто модальное окно — тост кладём в него, иначе окно (верхний слой) закроет тост
    var host = document.querySelector('dialog[open]') || document.body;
    if (toastEl.parentNode !== host) host.appendChild(toastEl);
    toastEl.textContent = text;
    toastEl.classList.add('is-show');
    clearTimeout(toastTimer);
    toastEl.classList.toggle('is-error', !!isError);
    // Сначала очищаем: одинаковое сообщение подряд иначе не зачитается
    var region = isError ? liveAlert : liveStatus;
    liveStatus.textContent = liveAlert.textContent = '';
    setTimeout(function () { region.textContent = text; }, 60);
    toastTimer = setTimeout(function () {
      toastEl.classList.remove('is-show');
      liveStatus.textContent = liveAlert.textContent = '';
    }, isError ? 7000 : 2600);
  }

  /* ---------- Запросы к API ----------
     api('PATCH', 'questions/Q-121', {...}) → Promise с JSON ответа.
     body может быть FormData (загрузка файлов). Ошибки показываются тостом. */
  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  function csrf() { return csrfMeta ? csrfMeta.getAttribute('content') : ''; }

  // Сессия закончилась: отправляем на вход
  function authFailed(status) {
    if (status === 401) { location.href = '/login'; return true; }
    if (status === 419) { toast('Сессия устарела — обновите страницу (F5) и повторите действие', true); return true; }
    return false;
  }

  // quiet — без всплывающего сообщения: экран сам показывает состояние (автосохранение теста)
  function api(method, path, body, quiet) {
    var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() };
    var opts = { method: method, headers: headers, credentials: 'same-origin' };
    if (body instanceof FormData) opts.body = body;
    else if (body !== undefined) { headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    return fetch('/api/' + path, opts).catch(function (e) {
      // Запрос не дошёл до сервера: пропала сеть или сервер выключен — говорим об этом, а не молчим
      if (!quiet) toast('Нет связи с сервером. Проверьте интернет и повторите действие', true);
      e.network = true;
      throw e;
    }).then(function (r) {
      if (r.status === 204) return null;
      if (authFailed(r.status)) throw new Error('auth');
      return r.json().catch(function () { return {}; }).then(function (data) {
        if (!r.ok) {
          // Laravel кладёт подробности проверки в errors: первая ошибка понятнее общего «The given data…»
          var first = data && data.errors ? Object.keys(data.errors).map(function (k) { return data.errors[k][0]; })[0] : null;
          var msg = r.status === 429 ? 'Слишком много запросов подряд — подождите несколько секунд и повторите'
            : first || (r.status >= 500 && (!data || !data.message || data.message === 'Server Error')
              ? 'Сервер временно не отвечает — повторите через минуту'
              : (data && data.message) || ('Ошибка сервера: ' + r.status));
          if (!quiet) toast(msg, true);
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
    btn.setAttribute('role', 'combobox');
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    var list = document.createElement('ul');
    list.className = 'cselect__list';
    list.setAttribute('role', 'listbox');
    list.id = 'cs-' + (++uid);
    btn.id = list.id + '-btn';
    list.tabIndex = -1;
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

    // Подпись: aria-label select, <label for> или текст <label>, внутри которого стоит список
    var label = sel.getAttribute('aria-label');
    var lab = sel.id ? document.querySelector('label[for="' + sel.id + '"]') : null;
    var outer = wrap.parentNode.closest ? wrap.parentNode.closest('label') : null;
    if (label) btn.setAttribute('aria-label', label);
    else if (lab) {
      if (!lab.id) lab.id = 'cs-lbl-' + uid;
      btn.setAttribute('aria-labelledby', lab.id);
      lab.addEventListener('click', function (e) { e.preventDefault(); btn.focus(); });
    } else if (outer) {
      var text = Array.prototype.filter.call(outer.childNodes, function (n) { return n !== wrap && n !== sel; })
        .map(function (n) { return n.textContent; }).join(' ').replace(/\s+/g, ' ').trim();
      if (text) btn.setAttribute('aria-label', text);
    }
    list.setAttribute('aria-label', btn.getAttribute('aria-label') || (lab ? lab.textContent.trim() : 'Варианты'));

    var active = -1;
    function options() { return Array.prototype.slice.call(sel.options); }
    function sync() {
      var o = sel.options[sel.selectedIndex], text = o ? o.text : '';
      // Содержимое кнопки не пересоздаём: при открытии sync() вызывается прямо во время клика,
      // и новый span на месте нажатого выглядел бы для закрывающего обработчика кликом «снаружи»
      var span = btn.firstChild;
      if (!span || span.className !== 'cselect__text') {
        btn.innerHTML = '<span class="cselect__text"></span>' + CHEVRON;
        span = btn.firstChild;
      }
      if (span.textContent !== text) span.textContent = text;
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
    function highlight(i, step) {
      var items = list.children;
      if (!items.length) return;
      i = Math.max(0, Math.min(items.length - 1, i));
      // Стрелки перешагивают недоступные пункты
      while (step && items[i] && items[i].getAttribute('aria-disabled') === 'true') i += step;
      if (!items[i] || items[i].getAttribute('aria-disabled') === 'true') return;
      active = i;
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
      if (k === 'ArrowDown') { e.preventDefault(); highlight(active + 1, 1); }
      else if (k === 'ArrowUp') { e.preventDefault(); highlight(active - 1, -1); }
      else if (k === 'Home') { e.preventDefault(); highlight(0, 1); }
      else if (k === 'End') { e.preventDefault(); highlight(list.children.length - 1, -1); }
      else if (k === 'Enter' || k === ' ') { e.preventDefault(); choose(active); }
      else if (k === 'Escape') { e.preventDefault(); close(true); }
      else if (k === 'Tab') close();
      else if (k.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
        // Поиск по первым буквам, как у обычного списка
        typed = (Date.now() - typedAt > 700 ? '' : typed) + k.toLowerCase();
        typedAt = Date.now();
        var hit = options().findIndex(function (o) { return o.text.toLowerCase().indexOf(typed) === 0; });
        if (hit >= 0) highlight(hit);
      }
    });

    // Firefox нажимает кнопку пробелом на keyup — после выбора пробелом список открылся бы снова
    btn.addEventListener('keyup', function (e) { if (e.key === ' ') e.preventDefault(); });
    // Фокус ушёл из списка (Tab, Alt+Tab, программный переход) — закрываем
    wrap.addEventListener('focusout', function (e) {
      if (!list.hidden && (!e.relatedTarget || !wrap.contains(e.relatedTarget))) close();
    });
    if (sel.form) sel.form.addEventListener('reset', function () { setTimeout(sync); });

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
    // Путь события, а не только target: элемент под курсором мог успеть перерисоваться и выпасть из DOM
    var path = e.composedPath ? e.composedPath() : [e.target];
    if (openSelect && path.indexOf(openSelect.wrap) < 0 && !openSelect.wrap.contains(e.target)) openSelect.close();
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
      records.forEach(function (r) {
        // Пункты наших же списков пропускаем: их пересобирают при каждом открытии
        if (r.target.closest && r.target.closest('.cselect')) return;
        Array.prototype.forEach.call(r.addedNodes, function (n) { if (n.nodeType === 1) enhanceAll(n); });
      });
    }).observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startSelects);
  else startSelects();

  /* ---------- Целые числа в сводках «набегают» от нуля при открытии страницы ---------- */
  function countUp() {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    Array.prototype.forEach.call(document.querySelectorAll('.stat__value, .tile__value'), function (el) {
      var node = el.firstChild, m = node && node.nodeType === 3 && /^(\s*)(\d{1,6})(\s*)$/.exec(node.nodeValue);
      if (!m || +m[2] < 2) return;
      var to = +m[2], start = null;
      function step(t) {
        if (start === null) start = t;
        var k = Math.min(1, (t - start) / 700);
        node.nodeValue = m[1] + Math.round(to * (1 - Math.pow(1 - k, 3))) + m[3];
        if (k < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', countUp);
  else countUp();

  /* ---------- Глазок у пароля: показать введённое, чтобы проверить опечатку ---------- */
  var EYE = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.7 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-2.4 3.3M6.6 6.6C3.9 8.4 2 12 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2M2 2l20 20"/></svg>';
  function passwordToggles() {
    Array.prototype.forEach.call(document.querySelectorAll('input[type=password]'), function (input) {
      if (input.parentNode.classList.contains('pw-wrap')) return;
      var wrap = document.createElement('span');
      wrap.className = 'pw-wrap';
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pw-toggle';
      btn.setAttribute('aria-pressed', 'false');
      btn.setAttribute('aria-label', 'Показать пароль');
      btn.innerHTML = EYE;
      wrap.appendChild(btn);
      btn.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', String(show));
        btn.setAttribute('aria-label', show ? 'Скрыть пароль' : 'Показать пароль');
        btn.innerHTML = show ? EYE_OFF : EYE;
        input.focus();
      });
      // Перед отправкой пароль снова скрыт: браузер не запомнит его как обычный текст
      if (input.form) input.form.addEventListener('submit', function () { input.type = 'password'; });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', passwordToggles);
  else passwordToggles();

  /* ---------- Окна вместо prompt() и confirm() ----------
     ask({ title, label, value, hint, placeholder, ok, required, validate, multiline, inputmode, maxlength }) → Promise<строка | null>
     confirm({ title, text, ok, danger }) → Promise<true | false>
     validate(значение) возвращает текст ошибки или ничего. */
  function dialog(opts, withInput) {
    return new Promise(function (resolve) {
      var id = 'dlg-' + (++uid), opener = document.activeElement, done = false;
      var fromMenu = opener && opener.closest && opener.closest('.menu');
      if (fromMenu) opener = fromMenu.querySelector('[data-menu]');
      var d = document.createElement('dialog');
      d.className = 'modal modal--sm';
      d.setAttribute('aria-labelledby', id + '-t');
      if (opts.text) d.setAttribute('aria-describedby', id + '-d');
      var attrs = ' id="' + id + '-in" aria-describedby="' + id + '-hint ' + id + '-err"' +
        (opts.placeholder ? ' placeholder="' + esc(opts.placeholder) + '"' : '') +
        (opts.inputmode ? ' inputmode="' + esc(opts.inputmode) + '"' : '') +
        ' maxlength="' + (opts.maxlength || 255) + '" autocomplete="off"';
      d.innerHTML = '<form class="modal__form" novalidate>' +
        '<div class="modal__head"><h2 class="card-title" id="' + id + '-t">' + esc(opts.title) + '</h2></div>' +
        '<div class="modal__body">' +
          (opts.text ? '<p class="modal__text" id="' + id + '-d">' + esc(opts.text) + '</p>' : '') +
          (opts.html ? opts.html + '<span class="field__error" id="' + id + '-err" role="alert"></span>' :
          opts.options ? '<fieldset class="choose" id="' + id + '-in" aria-describedby="' + id + '-err"><legend class="sr-only">' + esc(opts.label || opts.title) + '</legend>' +
            opts.options.map(function (o, i) {
              return '<label class="opt"><input type="radio" name="' + id + '-r" value="' + esc(o.value) + '"' + (String(o.value) === String(opts.value) || (opts.value == null && i === 0) ? ' checked' : '') + '>' +
                '<span><span class="strong">' + esc(o.label) + '</span>' + (o.hint ? '<span class="opt__desc" style="display: block">' + esc(o.hint) + '</span>' : '') + '</span></label>';
            }).join('') + '<span class="field__error" id="' + id + '-err" role="alert"></span></fieldset>' :
          withInput ? '<div class="field"><label for="' + id + '-in">' + esc(opts.label || '') + '</label>' +
            (opts.multiline ? '<textarea class="textarea" rows="4"' + attrs + '></textarea>' : '<input class="input"' + attrs + '>') +
            '<span class="field__hint" id="' + id + '-hint">' + esc(opts.hint || '') + '</span>' +
            '<span class="field__error" id="' + id + '-err" role="alert"></span></div>' : '') +
        '</div>' +
        '<div class="modal__foot"><button type="button" class="btn" data-cancel>' + esc(opts.cancel || 'Отмена') + '</button>' +
          '<button type="submit" class="btn ' + (opts.danger ? 'btn--danger' : 'btn--primary') + '">' + esc(opts.ok || 'Сохранить') + '</button></div>' +
      '</form>';
      document.body.appendChild(d);
      var input = d.querySelector('#' + id + '-in'), err = d.querySelector('#' + id + '-err');
      var none = withInput ? null : false;
      function finish(v) {
        if (done) return;
        done = true;
        d.close();
        d.remove();
        if (opener && opener.isConnected && opener.focus) opener.focus();
        resolve(v);
      }
      d.querySelector('form').addEventListener('submit', function (e) {
        e.preventDefault();
        if (!withInput) { finish(true); return; }
        if (opts.html) {
          var res = opts.collect(d);
          if (res && res.error) err.textContent = res.error; else finish(res ? res.value : true);
          return;
        }
        if (opts.options) {
          var picked = d.querySelector('input[type=radio]:checked');
          if (picked) finish(picked.value); else err.textContent = 'Выберите вариант';
          return;
        }
        var v = input.value.trim();
        var problem = opts.validate ? opts.validate(v) : (opts.required !== false && !v ? 'Заполните поле' : '');
        if (problem) {
          err.textContent = problem;
          input.setAttribute('aria-invalid', 'true');
          input.focus();
          return;
        }
        finish(v);
      });
      if (input && !opts.options && !opts.html) input.addEventListener('input', function () { err.textContent = ''; input.removeAttribute('aria-invalid'); });
      d.querySelector('[data-cancel]').addEventListener('click', function () { finish(none); });
      d.addEventListener('cancel', function (e) { e.preventDefault(); if (!opts.persist) finish(none); });
      // Клик по фону закрывает окно — но не выделение текста, отпущенное над фоном
      var downOnBackdrop = false;
      d.addEventListener('mousedown', function (e) { downOnBackdrop = e.target === d; });
      d.addEventListener('click', function (e) { if (e.target === d && downOnBackdrop && !opts.persist) finish(none); });
      d.showModal();
      if (opts.html) { var first = d.querySelector('.modal__body input, .modal__body select, .modal__body button'); if (first) first.focus(); }
      else if (opts.options) (d.querySelector('input[type=radio]:checked') || d.querySelector('input[type=radio]')).focus();
      else if (input) { input.value = opts.value == null ? '' : opts.value; input.focus(); input.select(); }
      // Опасное действие: фокус на «Отмене», чтобы случайный Enter ничего не удалил
      else d.querySelector(opts.danger ? '[data-cancel]' : '[type=submit]').focus();
    });
  }
  function ask(opts) { return dialog(opts, true); }
  // Выбор одного варианта: options [{ value, label, hint }] → Promise<value | null>
  function choose(opts) { return dialog(opts, true); }
  // Окно с произвольной формой: html — поля, collect(окно) → { value } или { error: 'текст' }
  function form(opts) { return dialog(opts, true); }
  function confirmBox(opts) { return dialog(Object.assign({ ok: 'Да' }, opts), false); }

  /* ---------- Меню «⋯» ----------
     <div class="menu"><button type="button" class="btn btn--xs btn--more" data-menu aria-haspopup="menu" aria-expanded="false" aria-label="…">…</button>
       <div class="menu__list" role="menu" hidden><button type="button" role="menuitem" …>…</button></div></div>
     Пункт меню — обычная кнопка или ссылка: страница ловит её клик своим делегированием, как раньше.
     Список позиционируется fixed — его не обрезает прокрутка таблицы. */
  var openMenu = null;
  function closeMenu(focus) {
    if (!openMenu) return;
    var m = openMenu;
    openMenu = null;
    m.list.hidden = true;
    m.wrap.classList.remove('is-open');
    m.btn.setAttribute('aria-expanded', 'false');
    if (focus && m.btn.isConnected && !document.querySelector('dialog[open]')) m.btn.focus();
  }
  function menuItems(list) { return $$('[role="menuitem"]:not([disabled])', list); }
  function placeMenu(m) {
    var r = m.btn.getBoundingClientRect(), w = m.list.offsetWidth, h = m.list.offsetHeight;
    var left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8));
    var below = window.innerHeight - r.bottom - 8;
    m.list.style.left = left + 'px';
    m.list.style.top = (below < h + 6 && r.top > h + 14 ? r.top - h - 6 : r.bottom + 6) + 'px';
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-menu]');
    if (btn) {
      var same = openMenu && openMenu.btn === btn;
      closeMenu();
      if (same) return;
      var wrap = btn.closest('.menu'), list = wrap.querySelector('.menu__list');
      openMenu = { wrap: wrap, btn: btn, list: list };
      list.hidden = false;
      wrap.classList.add('is-open');
      btn.setAttribute('aria-expanded', 'true');
      placeMenu(openMenu);
      // Открыли с клавиатуры (Enter/пробел дают click с detail 0) — фокус на первый пункт
      if (e.detail === 0) { var first = menuItems(list)[0]; if (first) first.focus(); }
      return;
    }
    if (openMenu && e.target.closest('[role="menuitem"]') && openMenu.wrap.contains(e.target)) closeMenu(true);
    else if (openMenu && !openMenu.wrap.contains(e.target)) closeMenu();
  });
  document.addEventListener('keydown', function (e) {
    if (!openMenu) return;
    var items = menuItems(openMenu.list), i = items.indexOf(document.activeElement);
    if (e.key === 'Escape') { e.preventDefault(); closeMenu(true); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); (items[i + 1] || items[0]).focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); (items[i - 1] || items[items.length - 1]).focus(); }
    else if (e.key === 'Home') { e.preventDefault(); items[0].focus(); }
    else if (e.key === 'End') { e.preventDefault(); items[items.length - 1].focus(); }
    else if (e.key === 'Tab') closeMenu();
  });
  // Прокрутка страницы или таблицы сдвигает кнопку — fixed-список закрываем, а не оставляем висеть
  window.addEventListener('scroll', function (e) {
    if (openMenu && !openMenu.list.contains(e.target)) closeMenu();
  }, true);
  var menuWidth = window.innerWidth;
  window.addEventListener('resize', function () {
    if (window.innerWidth !== menuWidth) closeMenu();
    menuWidth = window.innerWidth;
  });

  /* keepFocus(render): перерисовать часть страницы, не выбросив фокус клавиатуры в начало страницы.
     Элемент в фокусе узнаётся по data-атрибуту (data-sort, data-filter, …) или id и после перерисовки получает фокус снова. */
  var FOCUS_KEYS = ['data-sort', 'data-filter', 'data-tab', 'data-diff', 'data-done', 'data-copy', 'data-select', 'data-go', 'data-owner', 'id'];
  function keepFocus(render) {
    var el = document.activeElement, sel = null;
    if (el && el !== document.body && window.CSS && CSS.escape) {
      for (var i = 0; i < FOCUS_KEYS.length && !sel; i++) {
        var k = FOCUS_KEYS[i], v = el.getAttribute(k);
        if (v) sel = k === 'id' ? '#' + CSS.escape(v) : (el.closest('.menu') ? null : '[' + k + '="' + CSS.escape(v) + '"]');
      }
      // Пункт меню: возвращаемся на кнопку «⋯» той же строки
      if (!sel && el.closest('.menu')) {
        var b = el.closest('.menu').querySelector('[data-menu]');
        if (b && b.getAttribute('data-menu-key')) sel = '[data-menu-key="' + CSS.escape(b.getAttribute('data-menu-key')) + '"]';
        else if (b && b.getAttribute('aria-label')) sel = '[data-menu][aria-label="' + CSS.escape(b.getAttribute('aria-label')) + '"]';
      }
    }
    var result = render();
    if (sel && (!document.activeElement || document.activeElement === document.body || !document.activeElement.isConnected)) {
      var again = document.querySelector(sel);
      if (again) again.focus();
    }
    return result;
  }

  // Пункты меню: [подпись, атрибуты] → разметка; null пропускается, '-' — разделитель
  // key — постоянный ключ строки (код теста, id): по нему «⋯» находится снова после перерисовки и переименования
  function menu(label, items, key) {
    return '<div class="menu"><button type="button" class="btn btn--xs btn--more" data-menu' + (key != null ? ' data-menu-key="' + esc(key) + '"' : '') + ' aria-haspopup="menu" aria-expanded="false" aria-label="' + esc(label) + '" title="' + esc(label) + '">' + icons.more + '</button>' +
      '<div class="menu__list" role="menu" hidden>' + items.filter(Boolean).map(function (it) {
        if (it === '-') return '<div class="menu__sep" role="separator"></div>';
        return it.href
          ? '<a role="menuitem" class="menu__item" href="' + esc(it.href) + '">' + esc(it.label) + '</a>'
          : '<button type="button" role="menuitem" class="menu__item' + (it.danger ? ' menu__item--danger' : '') + '" ' + it.attrs + '>' + esc(it.label) + '</button>';
      }).join('') + '</div></div>';
  }

  /* ---------- Сортировка таблиц ----------
     sortBy(список, значение(x), 'asc'|'desc') — по-русски и с числами, пустые значения всегда внизу.
     sortTh(ключ, подпись, состояние) — заголовок-кнопка с aria-sort. */
  var collator = new Intl.Collator('ru', { numeric: true, sensitivity: 'base' });
  function sortBy(list, get, dir) {
    var k = dir === 'desc' ? -1 : 1;
    return list.map(function (x, i) { return [x, get(x), i]; }).sort(function (a, b) {
      var an = a[1] == null || a[1] === '', bn = b[1] == null || b[1] === '';
      if (an || bn) return an === bn ? a[2] - b[2] : (an ? 1 : -1);
      var c = typeof a[1] === 'number' && typeof b[1] === 'number' ? a[1] - b[1] : collator.compare(String(a[1]), String(b[1]));
      return c ? c * k : a[2] - b[2];
    }).map(function (x) { return x[0]; });
  }
  var SORT_ICON = '<svg class="th-sort__icon" width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M6 1.5 9 5H3zM6 10.5 3 7h6z" fill="currentColor"/></svg>';
  function sortTh(key, label, state, cls) {
    var on = state.key === key;
    return '<th' + (cls ? ' class="' + cls + '"' : '') + (on ? ' aria-sort="' + (state.dir === 'desc' ? 'descending' : 'ascending') + '"' : '') + '>' +
      '<button type="button" class="th-sort' + (on ? ' is-on is-' + state.dir : '') + '" data-sort="' + esc(key) + '">' + label + SORT_ICON + '</button></th>';
  }
  // Повторный клик по тому же столбцу меняет направление; новый столбец — со своего направления
  function nextSort(state, key, firstDir) {
    return state.key === key ? { key: key, dir: state.dir === 'asc' ? 'desc' : 'asc' } : { key: key, dir: firstDir || 'asc' };
  }
  // Кнопки-фильтры с числами: [[значение, подпись, число], …]
  function filterTabs(options, value, label) {
    return '<div class="ftabs" role="group" aria-label="' + esc(label) + '">' + options.map(function (o) {
      return '<button type="button" class="ftabs__btn' + (o[0] === value ? ' is-on' : '') + '" aria-pressed="' + (o[0] === value) + '" data-filter="' + esc(o[0]) + '">' +
        esc(o[1]) + (o[2] != null ? '<span class="ftabs__n">' + o[2] + '</span>' : '') + '</button>';
    }).join('') + '</div>';
  }
  /* ---------- Несколько преподавателей: «Мои / Все преподаватели» ----------
     Курс общий, но по умолчанию каждый видит своё. Один преподаватель — переключателя нет, видно всё.
     owner('tests', render): .match(запись) для фильтра, .html(записи) — сам переключатель (вставить в
     элемент [data-owner-box]), .by(запись) — фамилия автора чужой записи. Выбор помнится для страницы. */
  function owner(key, onChange) {
    var multi = (M.teacherCount || 1) > 1;
    var value = multi ? recall('owner.' + key, 'mine') : 'all';
    if (value !== 'mine' && value !== 'all') value = 'mine';
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-owner]');
      if (!b || b.getAttribute('data-owner') === value) return;
      value = b.getAttribute('data-owner');
      remember('owner.' + key, value);
      keepFocus(onChange);
    });
    return {
      multi: multi,
      value: function () { return value; },
      match: function (item) { return value === 'all' || !!item.mine; },
      html: function (items) {
        if (!multi) return '';
        var mine = items.filter(function (x) { return x.mine; }).length;
        return '<div class="ftabs" role="group" aria-label="Чьи записи показать">' + [['mine', 'Мои', mine], ['all', 'Все преподаватели', items.length]].map(function (o) {
          return '<button type="button" class="ftabs__btn' + (o[0] === value ? ' is-on' : '') + '" aria-pressed="' + (o[0] === value) + '" data-owner="' + o[0] + '">' +
            o[1] + '<span class="ftabs__n">' + o[2] + '</span></button>';
        }).join('') + '</div>';
      },
      by: function (item) { return multi && !item.mine && item.author ? item.author : ''; }
    };
  }

  // Запомнить выбор на странице (сортировку, фильтр) — только в этом браузере; без хранилища всё работает
  function recall(key, fallback) {
    try { var v = JSON.parse(localStorage.getItem('ptp:' + key)); return v == null ? fallback : v; } catch (e) { return fallback; }
  }
  function remember(key, value) {
    try { localStorage.setItem('ptp:' + key, JSON.stringify(value)); } catch (e) { /* приватный режим */ }
  }

  /* ---------- Иконка файла ----------
     Лист с загнутым углом и цветной плашкой типа: PDF красная, DOCX синяя, PPTX оранжевая… */
  var FILE_TONES = { PDF: '#D93025', DOCX: '#2B579A', DOC: '#2B579A', ODT: '#2B579A', RTF: '#2B579A', PPT: '#C4471F', ODP: '#C4471F', MD: '#5A6070', PPTX: '#C4471F', TXT: '#5A6070', MP4: '#7B3FC4', URL: '#0072BC' };
  function fileIcon(kind, size) {
    var k = String(kind || 'FILE').toUpperCase().slice(0, 4), c = FILE_TONES[k] || '#5A6070', s = size || 40;
    var w = k.length > 3 ? 25 : 20;
    return '<svg class="ficon" width="' + s + '" height="' + s + '" viewBox="0 0 40 40" aria-hidden="true" focusable="false">' +
      '<path d="M11 3h13.5L33 11.5V35a2 2 0 0 1-2 2H11a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z" fill="#fff" stroke="#C9CED8" stroke-width="1.4"/>' +
      '<path d="M24.5 3v6.5a2 2 0 0 0 2 2H33" fill="none" stroke="#C9CED8" stroke-width="1.4" stroke-linejoin="round"/>' +
      '<path d="M13 9.5h7M13 14h14M13 32.5h14" stroke="#E1E4EA" stroke-width="1.6" stroke-linecap="round"/>' +
      '<rect x="' + (k.length > 3 ? 3 : 4) + '" y="18" width="' + w + '" height="11" rx="2.5" fill="' + c + '"/>' +
      '<text x="' + ((k.length > 3 ? 3 : 4) + w / 2) + '" y="26" text-anchor="middle" font-size="' + (k.length > 3 ? 6.6 : 7) + '" font-weight="700" fill="#fff" font-family="Onest, system-ui, sans-serif" letter-spacing=".2">' + esc(k) + '</text>' +
    '</svg>';
  }

  /* Копирование в буфер. На http:// (не «безопасный контекст») — через выделение текста;
     execCommand может вернуть false без исключения — тогда промис отклоняется, а не делает вид, что скопировал. */
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    var t = document.createElement('textarea');
    t.value = text;
    t.setAttribute('readonly', '');
    t.style.position = 'fixed'; t.style.opacity = '0';
    (document.querySelector('dialog[open]') || document.body).appendChild(t);
    t.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    t.remove();
    return ok ? Promise.resolve() : Promise.reject(new Error('copy'));
  }

  // Исходные данные вопроса (схема отношения, зависимости, строки таблицы) — над формулировкой
  // Строки «KodS | Name | Dol» подряд (от двух) показываются таблицей, первая — шапка; остальное — как написано
  function context(text) {
    if (!text) return '';
    var isRow = function (l) { return /\S\s*\|\s*\S/.test(l); };
    var isRule = function (l) { return /\|/.test(l) && /^[\s|:\-]+$/.test(l) && /-{2,}/.test(l); };   // «---|---» из Markdown
    var cells = function (l) { return l.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(function (c) { return c.trim(); }); };
    var blocks = [], lines = String(text).replace(/\r/g, '').split('\n');
    for (var i = 0; i < lines.length;) {
      if (isRow(lines[i])) {
        var rows = [];
        while (i < lines.length && (isRow(lines[i]) || isRule(lines[i]))) { if (!isRule(lines[i])) rows.push(cells(lines[i])); i++; }
        blocks.push(rows.length >= 2 ? { table: rows } : { text: rows[0].join(' | ') });
        continue;
      }
      var plain = [];
      while (i < lines.length && !isRow(lines[i])) plain.push(lines[i++]);
      if (plain.join('').trim()) blocks.push({ text: plain.join('\n').replace(/^\n+|\n+$/g, '') });
    }
    return blocks.map(function (b) {
      if (b.text != null) return '<pre class="q-context">' + esc(b.text) + '</pre>';
      var width = Math.max.apply(null, b.table.map(function (r) { return r.length; }));
      var row = function (r, tag) {
        var out = '';
        for (var k = 0; k < width; k++) out += '<' + tag + '>' + esc(r[k] || '') + '</' + tag + '>';
        return '<tr>' + out + '</tr>';
      };
      return '<div class="q-context q-context--table"><table><thead>' + row(b.table[0], 'th') + '</thead><tbody>' +
        b.table.slice(1).map(function (r) { return row(r, 'td'); }).join('') + '</tbody></table></div>';
    }).join('');
  }

  // Строки сданных попыток студента (M.studentDone): «Мои тесты» и «Результаты»
  function doneRows(list) {
    return list.map(function (d) {
      return '<a href="result?attempt=' + d.attemptId + '" class="done-row"><div style="flex: 1; min-width: 220px"><div class="strong">' + esc(d.title) + '</div>' +
        '<div class="muted small" style="margin-top: 2px">' + esc(d.meta) + '</div></div>' + chip(d.status[0], d.status[1]) +
        '<span class="done-row__score">' + esc(d.score) + '</span></a>';
    }).join('');
  }

  window.App = {
    M: M, esc: esc, context: context, $: $, $$: $$, chip: chip, plural: plural, param: param, url: url,
    icons: icons, toast: toast, api: api, csrf: csrf, authFailed: authFailed,
    questions: questions, saveQuestion: saveQuestion, reviews: reviews, saveReview: saveReview,
    ask: ask, choose: choose, form: form, confirm: confirmBox, keepFocus: keepFocus, menu: menu, closeMenu: closeMenu,
    sortBy: sortBy, sortTh: sortTh, nextSort: nextSort, filterTabs: filterTabs, recall: recall, remember: remember,
    fileIcon: fileIcon, num: num, copyText: copyText, doneRows: doneRows, owner: owner
  };
})();
