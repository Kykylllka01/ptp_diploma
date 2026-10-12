/* Журнал: группа × тесты, баллы лучшей попытки, копирование столбца для Модеуса и ведомость */
(function () {
  var A = window.App, M = A.M, esc = A.esc, J = M.journal;
  var groupKey = J.group ? J.group.id : 0;

  function num(v) { return v == null ? '' : String(v).replace('.', ','); }
  // Что идёт в Модеус: баллы, если у теста задан вес, иначе процент
  function value(t, c) { return c.points != null ? c.points : (c.percent != null && t.weight == null ? c.percent : null); }

  // Группа: список групп курса, «без группы» — если есть такие студенты
  var sel = A.$('#group');
  sel.innerHTML = (M.groups || []).map(function (g) {
    return '<option value="' + g.id + '"' + (g.id === groupKey ? ' selected' : '') + '>' + esc(g.name) + '</option>';
  }).join('') + (M.hasUngrouped || !groupKey ? '<option value="0"' + (!groupKey ? ' selected' : '') + '>Без группы</option>' : '');
  sel.addEventListener('change', function () { location.href = 'gradebook?group=' + encodeURIComponent(sel.value); });

  A.$('#legend').innerHTML = A.chip('Итог', 'green') + A.chip('Предварительно — есть неподтверждённые ответы', 'amber') +
    A.chip('Проходит сейчас', 'blue') + '<span class="muted small">— не сдавал</span>';

  var maxTotal = J.tests.reduce(function (s, t) { return s + (t.weight || 0); }, 0);

  function render() {
    if (!J.students.length) {
      A.$('#journal').innerHTML = '<tbody><tr><td class="empty">В группе пока нет студентов. Отправьте им ссылку со страницы <a href="groups">«Группы»</a>.</td></tr></tbody>';
      return;
    }
    if (!J.tests.length) {
      A.$('#journal').innerHTML = '<tbody><tr><td class="empty">Группе ещё не назначен ни один тест с оценкой. <a href="test-builder">Собрать тест</a></td></tr></tbody>';
      return;
    }
    var head = '<thead><tr><th class="journal__n">№</th><th class="journal__name">Студент</th>' + J.tests.map(function (t) {
      var pending = J.students.filter(function (s) { return s.cells[t.id].status === 'pending'; }).length;
      return '<th class="journal__test"><div class="journal__title" title="' + esc(t.title) + '">' + esc(t.title) + '</div>' +
        '<div class="muted xsmall">' + (t.weight != null ? 'из ' + num(t.weight) + ' баллов' : 'в процентах · <a href="tests">задать баллы</a>') + '</div>' +
        '<div class="journal__tools">' +
          '<button type="button" class="btn btn--pill" data-copy="' + esc(t.id) + '" title="Скопировать баллы по одному на строку — для вставки в Модеус">Копировать столбец</button>' +
          '<label class="journal__done"><input type="checkbox" class="check" data-done="' + esc(t.id) + '"' + (t.transferred ? ' checked' : '') + '>' +
            (t.transferred ? 'Перенесено ' + esc(t.transferred) : 'Перенесено') + '</label>' +
          (pending ? '<span class="t-amber xsmall">' + pending + ' предв.</span>' : '') +
        '</div></th>';
    }).join('') + (maxTotal ? '<th class="journal__total">Итого<div class="muted xsmall">из ' + num(maxTotal) + '</div></th>' : '') + '</tr></thead>';

    var body = '<tbody>' + J.students.map(function (s, i) {
      return '<tr><td class="journal__n muted">' + (i + 1) + '</td><td class="journal__name"><div class="strong">' + esc(s.name) + '</div><div class="muted xsmall">' + esc(s.email) + '</div></td>' +
        J.tests.map(function (t) {
          var c = s.cells[t.id], v = value(t, c);
          var cls = c.status === 'final' ? 'is-final' : c.status === 'pending' ? 'is-pending' : c.status === 'in_progress' ? 'is-progress' : 'is-none';
          var text = v == null ? (c.status === 'in_progress' ? 'идёт' : '—') : num(v) + (t.weight == null ? '%' : '');
          var title = c.percent != null ? c.percent + '% · попыток ' + c.attempts + (c.status === 'pending' ? ' · ждёт подтверждения' : '') : '';
          return '<td class="journal__cell ' + cls + '">' + (c.attemptId
            ? '<a href="attempt?id=' + c.attemptId + '" title="' + esc(title) + '">' + text + '</a>' : '<span>' + text + '</span>') + '</td>';
        }).join('') +
        (maxTotal ? '<td class="journal__total strong">' + num(s.total) + '</td>' : '') + '</tr>';
    }).join('') + '</tbody>';
    A.$('#journal').innerHTML = head + body;
  }

  function copy(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    var t = document.createElement('textarea');
    t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
    document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); } finally { document.body.removeChild(t); }
    return Promise.resolve();
  }

  A.$('#journal').addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]');
    if (!b) return;
    var t = J.tests.filter(function (x) { return x.id === b.getAttribute('data-copy'); })[0];
    // Несдавшие — пустая строка: порядок строк должен совпасть со списком группы
    var lines = J.students.map(function (s) { return num(value(t, s.cells[t.id])); });
    var pending = J.students.filter(function (s) { return s.cells[t.id].status === 'pending'; }).length;
    copy(lines.join('\r\n')).then(function () {
      A.toast('Скопировано ' + lines.length + ' ' + A.plural(lines.length, 'строка', 'строки', 'строк') +
        (pending ? '. Внимание: ' + pending + ' ' + A.plural(pending, 'балл предварительный', 'балла предварительных', 'баллов предварительных') : ''), !!pending);
    });
  });

  A.$('#journal').addEventListener('change', function (e) {
    var box = e.target.closest('[data-done]');
    if (!box) return;
    var t = J.tests.filter(function (x) { return x.id === box.getAttribute('data-done'); })[0];
    A.api('POST', 'tests/' + encodeURIComponent(t.id) + '/transferred', { group: groupKey, done: box.checked }).then(function (r) {
      t.transferred = r.transferred[String(groupKey)] || null;
      render();
      A.toast(t.transferred ? 'Отмечено: перенесено в Модеус' : 'Отметка снята');
    }, function () { box.checked = !box.checked; });
  });

  // Ведомость: № ; студент ; почта ; баллы по тестам ; итого — CSV для Excel (точка с запятой, BOM)
  A.$('#export').addEventListener('click', function () {
    if (!J.students.length) { A.toast('В группе нет студентов'); return; }
    var head = ['№', 'Студент', 'Почта'].concat(J.tests.map(function (t) { return t.title + (t.weight != null ? ' (из ' + num(t.weight) + ')' : ' (%)'); }));
    if (maxTotal) head.push('Итого (из ' + num(maxTotal) + ')');
    var rows = J.students.map(function (s, i) {
      var r = [i + 1, s.name, s.email].concat(J.tests.map(function (t) {
        var c = s.cells[t.id], v = value(t, c);
        return v == null ? '' : num(v) + (c.status === 'pending' ? ' (предв.)' : '');
      }));
      if (maxTotal) r.push(num(s.total));
      return r;
    });
    var cell = function (c) {
      var x = String(c);
      if (typeof c !== 'number' && /^[=+\-@\t\r]/.test(x)) x = "'" + x;   // не формула для Excel
      return '"' + x.replace(/"/g, '""') + '"';
    };
    var csv = '﻿' + [head].concat(rows).map(function (l) { return l.map(cell).join(';'); }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    a.download = 'Журнал — ' + (J.group ? J.group.name : 'без группы') + '.csv';
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  });

  render();
})();
