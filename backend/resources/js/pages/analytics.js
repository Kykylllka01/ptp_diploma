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
  A.$('#to-review').textContent = waiting ? 'Проверить ' + waiting + ' ' + A.plural(waiting, 'ответ', 'ответа', 'ответов') : 'Все ответы проверены';

  A.$('#stats').innerHTML =
    '<div class="card stat"><div class="lbl">Сдали</div><div class="stat__value">' + D.submitted + ' <small>/ ' + D.total + '</small></div></div>' +
    '<div class="card stat"><div class="lbl">Средний балл</div><div class="stat__value">' + (D.avg === null ? '—' : D.avg + '%') + '</div></div>' +
    '<div class="card stat"><div class="lbl">Медианное время</div><div class="stat__value">' + (D.medianMinutes === null ? '—' : D.medianMinutes + ' мин') + '</div></div>' +
    '<div class="card stat' + (waiting ? ' card--warn' : '') + '"><div class="lbl">Ждут подтверждения</div><div class="stat__value">' + waiting + '</div></div>';

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

  A.$('#problems').innerHTML = D.problems.map(function (p) {
    var weak = p.correct < 50;
    return '<tr><td style="padding-right: 16px">' + p.id + ' ' + esc(p.text) + '</td><td>' + M.types[p.type] + '</td>' +
      '<td><div class="pc"><div class="progress progress--md"><div class="' + (weak ? 'is-weak' : '') + '" style="width:' + p.correct + '%"></div></div><span>' + p.correct + '%</span></div></td>' +
      '<td>' + (p.signal ? A.chip(p.signal[0], p.signal[1]) : '<span class="muted">—</span>') + '</td>' +
      '<td class="right"><a href="question?id=' + p.id + '">Открыть</a></td></tr>';
  }).join('');

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
