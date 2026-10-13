/* Журнал: группа × тесты, баллы лучшей попытки. Поиск, фильтры и сортировка — для просмотра;
   «Копировать столбец» и ведомость всегда идут в порядке Модеуса (по алфавиту) и по всей группе. */
(function () {
  var A = window.App, M = A.M, esc = A.esc, J = M.journal, num = A.num;
  var groupKey = J.group ? J.group.id : 0;
  var state = A.recall('journal', { sort: { key: 'name', dir: 'asc' }, filter: 'all' });
  var query = '';
  // Номер студента в алфавитном списке группы — как строка в Модеусе; не меняется при сортировке
  J.students.forEach(function (s, i) { s.n = i + 1; });
  if (state.sort.key !== 'name' && state.sort.key !== 'total' && !J.tests.some(function (t) { return t.id === state.sort.key; })) state.sort = { key: 'name', dir: 'asc' };

  // Что идёт в Модеус: баллы, если у теста задан вес, иначе процент
  function value(t, c) { return c.points != null ? c.points : (c.percent != null && t.weight == null ? c.percent : null); }
  function testById(id) { return J.tests.filter(function (x) { return x.id === id; })[0]; }
  function missing(s) { return J.tests.some(function (t) { var st = s.cells[t.id].status; return st === 'none' || st === 'in_progress'; }); }
  function pendingOf(s) { return J.tests.some(function (t) { return s.cells[t.id].status === 'pending'; }); }

  // Группа: список групп курса, «без группы» — если есть такие студенты
  var sel = A.$('#group');
  sel.innerHTML = (M.groups || []).map(function (g) {
    return '<option value="' + g.id + '"' + (g.id === groupKey ? ' selected' : '') + '>' + esc(g.name) + '</option>';
  }).join('') + (M.hasUngrouped || !groupKey ? '<option value="0"' + (!groupKey ? ' selected' : '') + '>Без группы</option>' : '');
  sel.addEventListener('change', function () { location.href = 'gradebook?group=' + encodeURIComponent(sel.value); });

  A.$('#legend').innerHTML =
    '<span class="jlegend"><i class="jlegend__final">12,5</i>итог</span>' +
    '<span class="jlegend"><i class="jlegend__pending">12,5</i>предварительно — есть неподтверждённые ответы</span>' +
    '<span class="jlegend"><i class="jlegend__progress">идёт</i>проходит сейчас</span>' +
    '<span class="jlegend"><i class="jlegend__none">—</i>не сдавал</span>';

  var maxTotal = J.tests.reduce(function (s, t) { return s + (t.weight || 0); }, 0);

  function headCell(t) {
    var pending = J.students.filter(function (s) { return s.cells[t.id].status === 'pending'; }).length;
    var on = state.sort.key === t.id;
    var badge = t.transferred
      ? '<span class="jt__badge jt__badge--done" title="Отмечено как перенесённое в Модеус">' + A.icons.check + 'в Модеусе ' + esc(t.transferred.slice(0, 5)) + '</span>'
      : pending ? '<span class="jt__badge jt__badge--pending">' + pending + ' предв.</span>' : '';
    var menu = A.menu('Действия со столбцом «' + t.title + '»', [
      { label: 'Копировать столбец для Модеуса', attrs: 'data-copy="' + esc(t.id) + '"' },
      { label: t.transferred ? 'Снять отметку «перенесено»' : 'Отметить: перенесено в Модеус', attrs: 'data-done="' + esc(t.id) + '"' },
      '-',
      { label: 'Аналитика теста', href: 'analytics?test=' + encodeURIComponent(t.id) },
      { label: t.weight != null ? 'Изменить баллы теста' : 'Задать баллы теста', href: 'tests' }
    ]);
    return '<th class="journal__test"' + (on ? ' aria-sort="' + (state.sort.dir === 'desc' ? 'descending' : 'ascending') + '"' : '') + '>' +
      '<button type="button" class="jt__title th-sort' + (on ? ' is-on is-' + state.sort.dir : '') + '" data-sort="' + esc(t.id) + '" title="' + esc(t.title) + ' — сортировать по баллам">' +
        '<span>' + esc(t.title) + '</span><svg class="th-sort__icon" width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M6 1.5 9 5H3zM6 10.5 3 7h6z" fill="currentColor"/></svg></button>' +
      '<div class="jt__meta">' + (t.weight != null ? 'из ' + num(t.weight) + ' б.' : 'в процентах') + badge + '</div>' +
      '<div class="jt__tools"><button type="button" class="btn btn--pill" data-copy="' + esc(t.id) + '" title="Скопировать баллы по одному на строку — для вставки в Модеус">' + A.icons.copy + 'Копировать</button>' + menu + '</div></th>';
  }

  function cell(t, c) {
    var v = value(t, c);
    var cls = { final: 'is-final', pending: 'is-pending', in_progress: 'is-progress' }[c.status] || 'is-none';
    var text = v == null ? (c.status === 'in_progress' ? 'идёт' : '—') : num(v) + (t.weight == null ? '%' : '');
    if (!c.attemptId) return '<td class="journal__cell ' + cls + '"><span>' + text + '</span></td>';
    var title = c.percent + '% · ' + c.attempts + ' ' + A.plural(c.attempts, 'попытка', 'попытки', 'попыток') + (c.status === 'pending' ? ' · ждёт подтверждения' : '') + ' — открыть ответы';
    return '<td class="journal__cell ' + cls + '"><a href="attempt?id=' + c.attemptId + '" title="' + esc(title) + '">' + text + '</a></td>';
  }

  function visible() {
    var q = query.toLowerCase();
    var list = J.students.filter(function (s) {
      return (!q || (s.name + ' ' + s.email).toLowerCase().indexOf(q) >= 0) &&
        (state.filter === 'all' || (state.filter === 'missing' ? missing(s) : pendingOf(s)));
    });
    var k = state.sort.key;
    if (k === 'name') return state.sort.dir === 'asc' ? list : list.slice().reverse();
    var t = testById(k);
    return A.sortBy(list, k === 'total' ? function (s) { return s.total; } : function (s) { return value(t, s.cells[k]); }, state.sort.dir);
  }

  function render() {
    A.remember('journal', state);
    if (!J.students.length || !J.tests.length) {
      A.$('#journal-tools').hidden = true;
      A.$('#journal').innerHTML = '<tbody><tr><td class="empty">' + (!J.students.length
        ? 'В группе пока нет студентов. Отправьте им ссылку-приглашение со страницы <a href="groups">«Группы»</a>.'
        : 'Группе ещё не назначен ни один тест с оценкой. <a href="test-builder">Собрать тест</a>') + '</td></tr></tbody>';
      return;
    }
    A.$('#filters').innerHTML = A.filterTabs([
      ['all', 'Все', J.students.length],
      ['missing', 'Не всё сдали', J.students.filter(missing).length],
      ['pending', 'Есть предварительные', J.students.filter(pendingOf).length]
    ], state.filter, 'Какие студенты');

    var list = visible(), s = state.sort;
    var head = '<thead><tr><th class="journal__n" title="Номер в списке группы">№</th>' +
      A.sortTh('name', 'Студент', s, 'journal__name') + J.tests.map(headCell).join('') + '<th class="journal__fill" aria-hidden="true"></th>' +
      (maxTotal ? A.sortTh('total', 'Итого <span class="muted xsmall" style="font-weight: 400">из ' + num(maxTotal) + '</span>', s, 'journal__total') : '') + '</tr></thead>';

    var body = '<tbody>' + (list.map(function (st) {
      return '<tr><td class="journal__n">' + st.n + '</td>' +
        '<td class="journal__name"><div class="strong">' + esc(st.name) + '</div><div class="muted xsmall">' + esc(st.email) + '</div></td>' +
        J.tests.map(function (t) { return cell(t, st.cells[t.id]); }).join('') + '<td class="journal__fill"></td>' +
        (maxTotal ? '<td class="journal__total">' + num(st.total) + '</td>' : '') + '</tr>';
    }).join('') || '<tr><td colspan="' + (J.tests.length + 4) + '" class="empty">Никого не нашлось. <button type="button" class="link-btn" id="reset">Показать всех</button></td></tr>') + '</tbody>';

    // Итоговая строка: средний балл сдавших и сколько сдали — по всей группе, без учёта фильтров
    var foot = '<tfoot><tr><td class="journal__n"></td><td class="journal__name"><div class="strong">Средний</div><div class="muted xsmall">у сдавших, по группе</div></td>' +
      J.tests.map(function (t) {
        var vals = J.students.map(function (st) { return value(t, st.cells[t.id]); }).filter(function (v) { return v != null; });
        var avg = vals.length ? Math.round(vals.reduce(function (a, b) { return a + b; }, 0) / vals.length * 10) / 10 : null;
        return '<td class="journal__cell"><div class="strong">' + (avg == null ? '—' : num(avg) + (t.weight == null ? '%' : '')) + '</div>' +
          '<div class="muted xsmall">сдали ' + vals.length + ' из ' + J.students.length + '</div></td>';
      }).join('') + '<td class="journal__fill"></td>' + (maxTotal ? '<td class="journal__total"></td>' : '') + '</tr></tfoot>';

    A.$('#journal').innerHTML = head + body + foot;
    var notes = [];
    if (list.length !== J.students.length) notes.push('Показано ' + list.length + ' из ' + J.students.length);
    if (s.key !== 'name' || s.dir !== 'asc') notes.push('Порядок не как в Модеусе — «Копировать» всё равно копирует всю группу по алфавиту');
    A.$('#result-line').textContent = notes.join('. ');
  }

  function copy(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    var t = document.createElement('textarea');
    t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
    document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); } finally { document.body.removeChild(t); }
    return Promise.resolve();
  }

  function copyColumn(t) {
    // Несдавшие — пустая строка: порядок строк должен совпасть со списком группы
    var lines = J.students.map(function (s) { return num(value(t, s.cells[t.id])); });
    var pending = J.students.filter(function (s) { return s.cells[t.id].status === 'pending'; }).length;
    copy(lines.join('\r\n')).then(function () {
      A.toast('Скопировано ' + lines.length + ' ' + A.plural(lines.length, 'строка', 'строки', 'строк') + ' по алфавиту' +
        (pending ? '. Внимание: ' + pending + ' ' + A.plural(pending, 'балл предварительный', 'балла предварительных', 'баллов предварительных') : ''), !!pending);
    }, function () { A.toast('Браузер не дал скопировать — выгрузите ведомость в Excel', true); });
  }

  function toggleDone(t) {
    A.api('POST', 'tests/' + encodeURIComponent(t.id) + '/transferred', { group: groupKey, done: !t.transferred }).then(function (r) {
      t.transferred = r.transferred[String(groupKey)] || null;
      render();
      A.toast(t.transferred ? 'Отмечено: баллы перенесены в Модеус' : 'Отметка снята');
    });
  }

  A.$('#journal-card').addEventListener('click', function (e) {
    if (e.target.closest('#reset')) { state.filter = 'all'; query = ''; A.$('#search').value = ''; render(); return; }
    var f = e.target.closest('[data-filter]');
    if (f) { state.filter = f.getAttribute('data-filter'); render(); return; }
    var so = e.target.closest('[data-sort]');
    if (so) {
      var key = so.getAttribute('data-sort');
      state.sort = A.nextSort(state.sort, key, key === 'name' ? 'asc' : 'desc');
      render();
      var again = A.$('#journal [data-sort="' + key + '"]');
      if (again) again.focus();
      return;
    }
    var c = e.target.closest('[data-copy]');
    if (c) { copyColumn(testById(c.getAttribute('data-copy'))); return; }
    var d = e.target.closest('[data-done]');
    if (d) toggleDone(testById(d.getAttribute('data-done')));
  });
  A.$('#search').addEventListener('input', function (e) { query = e.target.value.trim(); render(); });

  // Ведомость: № ; студент ; почта ; баллы по тестам ; итого — CSV для Excel (точка с запятой, BOM)
  A.$('#export').addEventListener('click', function () {
    if (!J.students.length) { A.toast('В группе нет студентов'); return; }
    var head = ['№', 'Студент', 'Почта'].concat(J.tests.map(function (t) { return t.title + (t.weight != null ? ' (из ' + num(t.weight) + ')' : ' (%)'); }));
    if (maxTotal) head.push('Итого (из ' + num(maxTotal) + ')');
    var rows = J.students.map(function (s) {
      var r = [s.n, s.name, s.email].concat(J.tests.map(function (t) {
        var c = s.cells[t.id], v = value(t, c);
        return v == null ? '' : num(v) + (c.status === 'pending' ? ' (предв.)' : '');
      }));
      if (maxTotal) r.push(num(s.total));
      return r;
    });
    var cellCsv = function (c) {
      var x = String(c);
      if (typeof c !== 'number' && /^[=+\-@\t\r]/.test(x)) x = "'" + x;   // не формула для Excel
      return '"' + x.replace(/"/g, '""') + '"';
    };
    var csv = '﻿' + [head].concat(rows).map(function (l) { return l.map(cellCsv).join(';'); }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    a.download = 'Журнал — ' + (J.group ? J.group.name : 'без группы') + '.csv';
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  });

  render();
})();
