/* ==========================================================================
   Общий код: шапка (будущий Blade-layout), хранилище демо-состояния,
   мелкие помощники. Подключается на каждой странице после mock-data.js.
   ========================================================================== */
(function () {
  'use strict';

  var M = window.MOCK;
  var root = document.body.getAttribute('data-root') || '';

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
  function url(path) { return root + path; }

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

  /* ---------- Хранилище демо-состояния ----------
     В реальном приложении это данные БД; здесь — localStorage,
     чтобы действия на одном экране были видны на другом. */
  var KEY = 'tsu-demo-v1';
  var state = {};
  try { state = JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { state = {}; }
  var store = {
    get: function (k, def) { return state[k] !== undefined ? state[k] : def; },
    set: function (k, v) { state[k] = v; try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {} },
    reset: function () { state = {}; try { localStorage.removeItem(KEY); } catch (e) {} }
  };

  // Вопросы банка с учётом правок преподавателя
  function questions() {
    var edits = store.get('questions', {});
    return M.questions.map(function (q) {
      var e = edits[q.id];
      return e ? Object.assign({}, q, e) : q;
    });
  }
  function saveQuestion(id, patch) {
    var edits = store.get('questions', {});
    edits[id] = Object.assign({}, edits[id] || {}, patch);
    store.set('questions', edits);
  }
  function reviews() {
    var edits = store.get('reviews', {});
    return M.reviews.map(function (r) { return edits[r.id] ? Object.assign({}, r, edits[r.id]) : r; });
  }
  function saveReview(id, patch) {
    var edits = store.get('reviews', {});
    edits[id] = Object.assign({}, edits[id] || {}, patch);
    store.set('reviews', edits);
  }

  /* ---------- Шапка ---------- */
  var NAV = {
    teacher: [
      ['dashboard', 'Обзор', 'teacher/dashboard.html'],
      ['materials', 'Материалы', 'teacher/upload.html'],
      ['bank', 'Банк вопросов', 'teacher/bank.html'],
      ['tests', 'Тесты', 'teacher/test-builder.html'],
      ['analytics', 'Аналитика', 'teacher/analytics.html']
    ],
    student: [
      ['tests', 'Мои тесты', 'student/tests.html'],
      ['training', 'Тренировка', 'student/tests.html#training'],
      ['results', 'Результаты', 'student/result.html']
    ]
  };

  function renderHeader(el) {
    var layout = el.getAttribute('data-layout');
    var active = el.getAttribute('data-active');
    el.classList.add('site-header');

    if (layout === 'test') {
      el.classList.add('test-header');
      el.innerHTML =
        '<div class="site-header__inner">' +
          '<img src="' + url('assets/img/logo.png') + '" alt="ТюмГУ">' +
          '<span class="test-header__title">' + esc(el.getAttribute('data-title') || '') + '</span>' +
          '<span class="timer" id="timer" role="timer" aria-label="Осталось времени">' + icons.clock + '<span>--:--</span></span>' +
          '<button class="btn" type="button" id="finish">Завершить тест</button>' +
        '</div>' +
        '<div class="test-progress"><div id="test-progress" style="width:0%"></div></div>';
      return;
    }

    var isTeacher = layout === 'teacher';
    var home = isTeacher ? 'teacher/dashboard.html' : 'student/tests.html';
    var links = NAV[layout].map(function (n) {
      return '<a href="' + url(n[2]) + '"' + (n[0] === active ? ' class="on" aria-current="page"' : '') + '>' + n[1] + '</a>';
    }).join('');
    var who = isTeacher
      ? chip('Преподаватель', 'blue') + '<span class="user__name">' + esc(M.teacher.name) + '</span><span class="avatar">' + M.teacher.initials + '</span>'
      : chip('Студент', 'green') + '<span class="user__name">' + esc(M.student.name) + '</span><span class="avatar avatar--gray">' + M.student.initials + '</span>';

    el.innerHTML =
      '<div class="site-header__inner">' +
        '<a href="' + url(home) + '" class="brand" aria-label="ТюмГУ — на главную"><img src="' + url('assets/img/logo.png') + '" alt=""><span>ТюмГУ</span></a>' +
        '<nav class="nav" aria-label="Основное меню">' + links + '</nav>' +
        '<div class="user">' + who + '</div>' +
      '</div>';
  }

  /* ---------- Тост ---------- */
  var toastEl, toastTimer;
  function toast(text) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'toast';
      toastEl.setAttribute('role', 'status');
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = text;
    toastEl.classList.add('is-show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('is-show'); }, 2600);
  }

  // Переключатели-«свитчи»
  document.addEventListener('click', function (e) {
    var sw = e.target.closest('.sw');
    if (sw) sw.setAttribute('aria-pressed', sw.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
  });

  $$('header[data-layout]').forEach(renderHeader);

  window.App = {
    M: M, esc: esc, $: $, $$: $$, chip: chip, plural: plural, param: param, url: url,
    icons: icons, store: store, toast: toast,
    questions: questions, saveQuestion: saveQuestion, reviews: reviews, saveReview: saveReview
  };
})();
