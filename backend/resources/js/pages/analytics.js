/* Экран 7. Результаты и аналитика по тесту */
(function () {
  var A = window.App, M = A.M, esc = A.esc, D = M.analytics;
  if (!D) {
    A.$('#title').textContent = 'Тестов пока нет';
    A.$('#test-status').textContent = 'Аналитика';
    // Пустое состояние вместо пустых графиков
    var title = A.$('#title').closest('div');
    Array.prototype.slice.call(A.$('main').children).forEach(function (el) { if (!el.contains(title)) el.hidden = true; });
    A.$('main').insertAdjacentHTML('beforeend', '<section class="card card--pad empty">Здесь появятся результаты, когда студенты сдадут первый тест. <a href="test-builder">Собрать тест</a></section>');
    return;
  }
  var test = M.tests.filter(function (t) { return t.id === D.testId; })[0];
  var waiting = D.pending || 0;   // ответы этого теста, ждущие подтверждения

  A.$('#title').textContent = test.title;

  // Переключение между тестами курса
  var others = M.tests.filter(function (t) { return t.status !== 'draft'; });
  if (others.length > 1) {
    var sw = A.$('#test-switch');
    sw.hidden = false;
    sw.innerHTML = others.map(function (t) { return '<option value="' + esc(t.id) + '"' + (t.id === D.testId ? ' selected' : '') + '>' + esc(t.title) + '</option>'; }).join('');
    sw.addEventListener('change', function () { location.href = 'analytics?test=' + encodeURIComponent(sw.value); });
  }
  A.$('#test-status').textContent = 'Тест · ' + test.statusLabel.toLowerCase();
  // Кнопка проверки — только когда есть что проверять
  if (waiting) {
    A.$('#to-review').hidden = false;
    A.$('#to-review').href = 'review?test=' + encodeURIComponent(D.testId);
    A.$('#to-review').textContent = 'Проверить ' + waiting + ' ' + A.plural(waiting, 'ответ', 'ответа', 'ответов');
  }

  A.$('#stats').innerHTML =
    '<div class="card stat"><div class="lbl">Сдали</div><div class="stat__value">' + D.submitted + ' <small>/ ' + D.total + '</small></div></div>' +
    '<div class="card stat"><div class="lbl">Средний балл</div><div class="stat__value">' + (D.avg === null ? '—' : D.avg + '%') + '</div></div>' +
    '<div class="card stat"><div class="lbl">Медианное время</div><div class="stat__value">' + (D.medianMinutes === null ? '—' : D.medianMinutes + ' мин') + '</div></div>' +
    '<div class="card stat' + (waiting ? ' card--warn' : '') + '"><div class="lbl">Ждут подтверждения</div><div class="stat__value">' + waiting + '</div></div>';

  // ---------- Студенты: лучшая попытка каждого, включая тех, кто не сдавал ----------
  var R = M.results || { students: [], counts: {} };
  var st = A.recall('analytics-students', { filter: 'all', sort: { key: 'name', dir: 'asc' } });
  var stQuery = '';
  var ST = { final: ['Итог', 'green'], pending: ['Предварительно', 'amber'], in_progress: ['Проходит сейчас', 'blue'], none: ['Не сдавал', 'gray'] };
  var ST_ORDER = { final: 0, pending: 1, in_progress: 2, none: 3 };
  var num = function (v) { return v == null ? '—' : A.num(v); };
  A.$('#weight-line').innerHTML = R.weight != null
    ? 'Тест стоит <b class="strong">' + num(R.weight) + '</b> ' + A.plural(Math.ceil(R.weight) === R.weight ? R.weight : 2, 'балл', 'балла', 'баллов') + ': баллы = процент лучшей попытки × ' + num(R.weight) + ' / 100.'
    : 'Вес теста в баллах не задан — задайте его на странице <a href="tests">«Тесты»</a> (меню «⋯» у теста), чтобы видеть баллы для Модеуса.';
  var GET = {
    name: function (s) { return s.name; },
    group: function (s) { return s.group; },
    attempts: function (s) { return s.attempts || null; },
    percent: function (s) { return s.percent; },
    points: function (s) { return s.points != null ? s.points : s.percent; },
    status: function (s) { return ST_ORDER[s.status]; },
    submitted: function (s) { return s.submitted ? s.submitted.replace(/^(\d\d)\.(\d\d)\.(\d{4}) (.*)$/, '$3$2$1 $4') : null; }
  };
  function renderStudents() {
    A.remember('analytics-students', st);
    var c = R.counts;
    A.$('#st-filters').innerHTML = A.filterTabs([['all', 'Все', c.total], ['final', 'Итог', c.final], ['pending', 'Предварительно', c.pending], ['none', 'Не сдавали', c.none]], st.filter, 'Какие студенты');
    var s = st.sort;
    A.$('#st-head').innerHTML = '<tr>' + A.sortTh('name', 'Студент', s) + A.sortTh('group', 'Группа', s) + A.sortTh('attempts', 'Попыток', s, 'num-col') +
      A.sortTh('percent', 'Лучший результат', s) + A.sortTh('points', R.weight != null ? 'Баллы в Модеус' : 'Баллы', s, 'num-col') + A.sortTh('status', 'Статус', s) +
      A.sortTh('submitted', 'Сдан', s) + '<th><span class="sr-only">Ответы</span></th></tr>';
    var q = stQuery.toLowerCase();
    var list = A.sortBy(R.students.filter(function (x) {
      return (st.filter === 'all' || x.status === st.filter || (st.filter === 'none' && x.status === 'in_progress')) &&
        (!q || (x.name + ' ' + x.email + ' ' + (x.group || '')).toLowerCase().indexOf(q) >= 0);
    }), GET[s.key] || GET.name, s.dir);
    A.$('#students').innerHTML = list.map(function (x) {
      var label = ST[x.status];
      return '<tr' + (x.attemptId ? ' class="is-clickable" data-attempt="' + x.attemptId + '"' : '') + '>' +
        '<td><div class="strong">' + esc(x.name) + '</div><div class="muted xsmall">' + esc(x.email) + '</div></td>' +
        '<td class="small" data-label="Группа">' + esc(x.group || '—') + '</td>' +
        '<td class="num-col" data-label="Попыток">' + (x.attempts || '—') + '</td>' +
        '<td data-label="Лучший результат">' + (x.percent == null ? '<span class="muted">—</span>' : '<div class="pc"><div class="progress progress--md" style="max-width: 120px"><div class="' + (x.percent < 50 ? 'is-weak' : '') + '" style="width:' + x.percent + '%"></div></div><span class="strong">' + x.percent + '%</span></div><div class="muted xsmall">' + num(x.score) + ' из ' + num(x.max) + '</div>') + '</td>' +
        '<td class="num-col strong" data-label="Баллы">' + (x.points != null ? num(x.points) + (R.weight != null ? ' <span class="muted xsmall" style="font-weight: 400">/ ' + num(R.weight) + '</span>' : '') : (x.percent != null && R.weight == null ? x.percent + '%' : '<span class="muted" style="font-weight: 400">—</span>')) + '</td>' +
        '<td data-label="Статус">' + A.chip(label[0], label[1]) + '</td>' +
        '<td class="small" data-label="Сдан">' + esc(x.submitted || '—') + '</td>' +
        '<td class="right td-actions">' + (x.attemptId ? '<a href="attempt?id=' + x.attemptId + '" class="btn btn--xs">Ответы</a>' : '') + '</td></tr>';
    }).join('') || '<tr><td colspan="8" class="empty">Никого в этом списке.</td></tr>';
  }
  A.$('#st-filters').addEventListener('click', function (e) {
    var b = e.target.closest('[data-filter]');
    if (b) { st.filter = b.getAttribute('data-filter'); A.keepFocus(renderStudents); }
  });
  A.$('#st-head').addEventListener('click', function (e) {
    var b = e.target.closest('[data-sort]');
    if (!b) return;
    var key = b.getAttribute('data-sort');
    st.sort = A.nextSort(st.sort, key, key === 'name' || key === 'group' || key === 'status' ? 'asc' : 'desc');
    renderStudents();
    var again = A.$('#st-head [data-sort="' + key + '"]');
    if (again) again.focus();
  });
  A.$('#st-search').addEventListener('input', function (e) { stQuery = e.target.value.trim(); renderStudents(); });
  A.$('#students').addEventListener('click', function (e) {
    var tr = e.target.closest('[data-attempt]');
    if (tr && !e.target.closest('a')) location.href = 'attempt?id=' + tr.getAttribute('data-attempt');
  });
  renderStudents();

  var max = Math.max.apply(null, D.histogram) || 1;
  A.$('#hist').setAttribute('aria-label', 'Число студентов по диапазонам баллов: ' + D.histogram.join(', '));
  A.$('#hist').innerHTML = D.histogram.map(function (n) {
    return '<div class="bar"><span>' + n + '</span><div style="height:' + Math.round(n / max * 160) + 'px' + (n ? '' : '; background: #C3C8D3') + '"></div></div>';
  }).join('');
  A.$('#hist-axis').innerHTML = D.histogramLabels.map(function (l) { return '<span>' + l + '</span>'; }).join('');

  A.$('#topics').innerHTML = D.topics.map(function (t) {
    var weak = t[1] < 60;
    return '<div><div class="between"><span>' + esc(t[0]) + '</span><b class="strong' + (weak ? ' t-orange' : '') + '">' + t[1] + '%</b></div>' +
      '<div class="progress progress--md" style="margin-top: 6px"><div class="' + (weak ? 'is-weak' : '') + '" style="width:' + t[1] + '%"></div></div></div>';
  }).join('');
  if (!D.topics.length) A.$('#topics').innerHTML = '<span class="muted">Появится после первых сдач</span>';
  A.$('#insight').textContent = D.insight;
  // Тренировку по слабой теме предлагаем, только когда сдач хватает для вывода
  A.$('#insight-training').hidden = !D.insightReady;
  // Тренировка — по самой слабой теме теста
  var weakest = D.topics.length ? D.topics[D.topics.length - 1][0] : '';
  if (weakest) A.$('#insight-training a').href = 'test-builder?training=1&topic=' + encodeURIComponent(weakest);

  A.$('#problems').innerHTML = D.problems.map(function (p) {
    var weak = p.correct < 50;
    return '<tr><td style="min-width: 320px"><div class="clamp-2">' + esc(p.text) + '</div><div class="muted xsmall mono" style="margin-top: 2px">' + esc(p.id) + '</div></td><td class="small">' + esc(M.types[p.type]) + '</td>' +
      '<td><div class="pc"><div class="progress progress--md"><div class="' + (weak ? 'is-weak' : '') + '" style="width:' + p.correct + '%"></div></div><span>' + p.correct + '%</span></div></td>' +
      '<td>' + (p.signal ? A.chip(p.signal[0], p.signal[1]) : '<span class="muted">—</span>') + '</td>' +
      '<td class="right"><a href="question?id=' + encodeURIComponent(p.id) + '" class="btn btn--xs">Открыть</a></td></tr>';
  }).join('') || '<tr><td colspan="5" class="empty">Появится, когда тест сдадут: здесь будет видно, на какие вопросы чаще отвечают неверно.</td></tr>';

  // Экспорт в CSV для Excel: строка на студента с баллами по каждому вопросу
  A.$('#export').addEventListener('click', function () {
    if (!D.students.length) { A.toast('Пока никто не сдал тест'); return; }
    var head = ['Студент', 'Группа', 'Сдан', 'Минут', 'Балл', 'Максимум', '%', 'Статус'].concat(D.questionCodes);
    var lines = [head].concat(D.students.map(function (s) {
      return [s.name, s.group || '', s.submitted || '', s.minutes, s.score, s.max, s.percent, s.status].concat(s.byQuestion);
    }));
    var cell = function (c) {
      var s = typeof c === 'number' ? String(c).replace('.', ',') : String(c);
      if (typeof c !== 'number' && /^[=+\-@\t\r]/.test(s)) s = "'" + s;   // не формула для Excel
      return '"' + s.replace(/"/g, '""') + '"';
    };
    var csv = '﻿' + lines.map(function (l) { return l.map(cell).join(';'); }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    a.download = test.title + ' — результаты.csv';
    document.body.appendChild(a);
    a.click();
    // Firefox отменяет скачивание, если ссылку отозвать сразу
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  });
})();
