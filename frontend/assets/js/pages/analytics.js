/* Экран 7. Результаты и аналитика по тесту */
(function () {
  var A = window.App, M = A.M, esc = A.esc, D = M.analytics;
  var test = M.tests.filter(function (t) { return t.id === D.testId; })[0];
  var waiting = A.reviews().filter(function (r) { return r.status === 'pending'; }).length;

  A.$('#title').textContent = test.title;
  A.$('#to-review').textContent = waiting ? 'Проверить ' + waiting + ' ' + A.plural(waiting, 'ответ', 'ответа', 'ответов') : 'Все ответы проверены';

  A.$('#stats').innerHTML =
    '<div class="card stat"><div class="lbl">Сдали</div><div class="stat__value">' + D.submitted + ' <small>/ ' + D.total + '</small></div></div>' +
    '<div class="card stat"><div class="lbl">Средний балл</div><div class="stat__value">' + D.avg + '%</div></div>' +
    '<div class="card stat"><div class="lbl">Медианное время</div><div class="stat__value">' + D.medianMinutes + ' мин</div></div>' +
    '<div class="card stat' + (waiting ? ' card--warn' : '') + '"><div class="lbl">Ждут подтверждения</div><div class="stat__value">' + waiting + '</div></div>';

  var max = Math.max.apply(null, D.histogram);
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
  A.$('#insight').textContent = D.insight;

  A.$('#problems').innerHTML = D.problems.map(function (p) {
    var weak = p.correct < 50;
    return '<tr><td style="padding-right: 16px">' + p.id + ' ' + esc(p.text) + '</td><td>' + M.types[p.type] + '</td>' +
      '<td><div class="pc"><div class="progress progress--md"><div class="' + (weak ? 'is-weak' : '') + '" style="width:' + p.correct + '%"></div></div><span>' + p.correct + '%</span></div></td>' +
      '<td>' + (p.signal ? A.chip(p.signal[0], p.signal[1]) : '<span class="muted">—</span>') + '</td>' +
      '<td class="right"><a href="question.html?id=' + p.id + '">Открыть</a></td></tr>';
  }).join('');

  // Экспорт: в демо выгружаем CSV, который открывается в Excel
  A.$('#export').addEventListener('click', function () {
    var lines = [['Вопрос', 'Тип', 'Верно ответили, %', 'Сигнал']].concat(D.problems.map(function (p) {
      return [p.id + ' ' + p.text, M.types[p.type], p.correct, p.signal ? p.signal[0] : ''];
    }));
    var csv = '﻿' + lines.map(function (l) { return l.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(';'); }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    a.download = 'Модуль 1 — результаты.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  });
})();
