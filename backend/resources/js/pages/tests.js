/* Тесты преподавателя: список, пробное прохождение, открыть/закрыть приём, удаление */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var tone = { running: 'blue', training: 'blue', draft: 'gray', closed: 'gray' };

  function actions(t) {
    var a = [];
    if (t.count) a.push('<a href="preview?test=' + encodeURIComponent(t.id) + '" class="btn btn--xs">Пройти самому</a>');
    if (t.status === 'draft') a.push('<a href="bank?status=approved" class="btn btn--xs">Добавить вопросы</a>');
    if (t.status !== 'draft') a.push('<a href="analytics?test=' + encodeURIComponent(t.id) + '" class="btn btn--xs">Результаты</a>');
    if (t.graded) a.push('<button type="button" class="btn btn--xs" data-weight="' + esc(t.id) + '">' + (t.weight != null ? 'Баллы: ' + String(t.weight).replace('.', ',') : 'Задать баллы') + '</button>');
    if (t.status === 'running') a.push('<button type="button" class="btn btn--xs" data-status="closed" data-id="' + esc(t.id) + '">Закрыть приём</button>');
    if (t.status === 'closed' || (t.status === 'draft' && t.count)) a.push('<button type="button" class="btn btn--xs" data-status="running" data-id="' + esc(t.id) + '">Открыть приём</button>');
    a.push('<button type="button" class="btn btn--xs" data-rename="' + esc(t.id) + '">Переименовать</button>');
    a.push('<button type="button" class="btn btn--xs" data-delete="' + esc(t.id) + '" aria-label="Удалить тест «' + esc(t.title) + '»">Удалить</button>');
    return '<div class="row-wrap" style="justify-content: flex-end; gap: 6px">' + a.join('') + '</div>';
  }

  function render() {
    var list = M.tests.slice().reverse();
    A.$('#tests').innerHTML = list.map(function (t) {
      var meta = [t.status === 'training' ? 'тренировка без оценки' : (t.minutes ? t.minutes + ' мин' : 'без лимита времени'), t.group || 'все студенты курса',
        t.individual ? 'свой вариант каждому · в пуле ' + t.pool : ''].filter(Boolean).join(' · ');
      return '<tr><td><div class="strong">' + esc(t.title) + '</div><div class="muted xsmall">' + esc(meta) + '</div></td>' +
        '<td>' + t.count + '</td>' +
        '<td>' + A.chip(t.statusLabel, tone[t.status]) + '</td>' +
        '<td class="small">' + esc(t.deadline || '—') + '</td>' +
        '<td>' + t.passed + '</td><td>' + t.avg + '</td>' +
        '<td class="right">' + actions(t) + '</td></tr>';
    }).join('') || '<tr><td colspan="7" class="empty">Тестов пока нет. <a href="test-builder">Соберите первый</a> из одобренных вопросов банка.</td></tr>';
  }

  A.$('#tests').addEventListener('click', function (e) {
    var rn = e.target.closest('[data-rename]');
    if (rn) {
      var tr = M.tests.filter(function (x) { return x.id === rn.getAttribute('data-rename'); })[0];
      var name = prompt('Новое название теста', tr.title);
      if (!name || !name.trim() || name.trim() === tr.title) return;
      A.api('PATCH', 'tests/' + encodeURIComponent(tr.id), { title: name.trim() }).then(function (fresh) {
        M.tests = M.tests.map(function (t) { return t.id === tr.id ? fresh : t; });
        render();
        A.toast('Тест переименован');
      });
      return;
    }
    var w = e.target.closest('[data-weight]');
    if (w) {
      var tw = M.tests.filter(function (x) { return x.id === w.getAttribute('data-weight'); })[0];
      var v = prompt('Сколько баллов в Модеус даёт тест «' + tw.title + '» за 100 %? Пусто — без веса (в журнале проценты).', tw.weight != null ? String(tw.weight).replace('.', ',') : '');
      if (v === null) return;
      v = v.trim().replace(',', '.');
      if (v !== '' && (isNaN(+v) || +v < 0 || +v > 100)) { A.toast('Нужно число от 0 до 100', true); return; }
      A.api('PATCH', 'tests/' + encodeURIComponent(tw.id), { weight: v === '' ? null : +v }).then(function (fresh) {
        M.tests = M.tests.map(function (t) { return t.id === tw.id ? fresh : t; });
        render();
        A.toast(fresh.weight != null ? 'Тест стоит ' + String(fresh.weight).replace('.', ',') + ' баллов' : 'Вес снят');
      });
      return;
    }
    var s = e.target.closest('[data-status]');
    if (s) {
      var id = s.getAttribute('data-id'), status = s.getAttribute('data-status');
      A.api('PATCH', 'tests/' + encodeURIComponent(id), { status: status }).then(function (fresh) {
        M.tests = M.tests.map(function (t) { return t.id === id ? fresh : t; });
        render();
        A.toast(status === 'closed' ? 'Приём ответов закрыт' : 'Тест открыт для студентов');
      });
      return;
    }
    var d = e.target.closest('[data-delete]');
    if (d) {
      var t = M.tests.filter(function (x) { return x.id === d.getAttribute('data-delete'); })[0];
      var warn = t.submitted ? '\nВместе с тестом удалятся ' + t.submitted + ' ' + A.plural(t.submitted, 'попытка', 'попытки', 'попыток') + ' студентов.' : '';
      if (!confirm('Удалить тест «' + t.title + '»?' + warn)) return;
      A.api('DELETE', 'tests/' + encodeURIComponent(t.id)).then(function () {
        M.tests = M.tests.filter(function (x) { return x.id !== t.id; });
        render();
        A.toast('Тест удалён');
      });
    }
  });

  render();
})();
