/* Тесты преподавателя: поиск, фильтр по статусу и группе, сортировка; у строки — главное действие и меню «⋯» */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var tone = { running: 'blue', training: 'blue', draft: 'gray', closed: 'gray' };
  var STATUS_ORDER = { running: 0, training: 1, draft: 2, closed: 3 };
  var FILTERS = [['all', 'Все'], ['running', 'Идут'], ['draft', 'Черновики'], ['closed', 'Приём закрыт'], ['training', 'Тренировки']];

  var state = A.recall('tests', { filter: 'all', group: '', sort: { key: 'created', dir: 'desc' } });
  var query = '';

  function byId(id) { return M.tests.filter(function (t) { return t.id === id; })[0]; }
  function weightLabel(w) { return A.num(w) + ' ' + A.plural(Math.ceil(w) === w ? w : 2, 'балл', 'балла', 'баллов'); }

  // Главное действие зависит от состояния теста; всё остальное — в меню
  function primary(t) {
    var q = encodeURIComponent(t.id);
    if (t.status === 'draft' && !t.count) return '<a href="bank?status=approved" class="btn btn--xs">Добавить вопросы</a>';
    if (t.status === 'draft' || (t.status === 'closed' && !t.submitted)) return '<button type="button" class="btn btn--xs" data-status="running" data-id="' + esc(t.id) + '">Открыть приём</button>';
    if (t.submitted) return '<a href="analytics?test=' + q + '" class="btn btn--xs">Результаты</a>';
    return '<a href="preview?test=' + q + '" class="btn btn--xs">Пройти самому</a>';
  }
  function menuFor(t) {
    var q = encodeURIComponent(t.id), id = esc(t.id), main = primary(t);
    var items = [
      t.count && main.indexOf('preview?') < 0 ? { label: 'Пройти самому', href: 'preview?test=' + q } : null,
      t.status !== 'draft' && main.indexOf('analytics?') < 0 ? { label: 'Результаты и аналитика', href: 'analytics?test=' + q } : null,
      { label: 'Срок и группы', attrs: 'data-settings="' + id + '"' },
      t.graded ? { label: t.weight != null ? 'Баллы в Модеус: ' + A.num(t.weight) : 'Задать баллы в Модеус', attrs: 'data-weight="' + id + '"' } : null,
      t.status === 'running' ? { label: 'Закрыть приём', attrs: 'data-status="closed" data-id="' + id + '"' } : null,
      t.status === 'closed' && main.indexOf('data-status') < 0 ? { label: 'Открыть приём снова', attrs: 'data-status="running" data-id="' + id + '"' } : null,
      { label: 'Переименовать', attrs: 'data-rename="' + id + '"' },
      '-',
      { label: 'Удалить', attrs: 'data-delete="' + id + '"', danger: true }
    ];
    return A.menu('Действия с тестом «' + t.title + '»', items);
  }

  function passed(t) {
    if (t.status === 'draft') return '<span class="muted">—</span>';
    if (t.status === 'training' || !t.students) return '<span class="strong">' + t.submitted + '</span>';
    var total = Math.max(t.students, t.submitted), p = Math.round(t.submitted / total * 100);
    return '<div class="strong">' + t.submitted + ' <span class="muted" style="font-weight: 400">/ ' + total + '</span></div>' +
      '<div class="progress" style="width: 72px; margin-top: 6px"><div style="width:' + p + '%"></div></div>';
  }

  function row(t) {
    var meta = [t.status === 'training' ? 'тренировка без оценки' : (t.minutes ? t.minutes + ' мин' : 'без лимита времени'), t.group || 'все студенты курса',
      t.individual ? 'свой вариант каждому' : '', t.weight != null ? weightLabel(t.weight) : ''].filter(Boolean).join(' · ');
    var title = t.status !== 'draft'
      ? '<a href="analytics?test=' + encodeURIComponent(t.id) + '" class="table__title">' + esc(t.title) + '</a>'
      : '<span class="table__title">' + esc(t.title) + '</span>';
    return '<tr><td style="min-width: 260px">' + title + '<div class="muted xsmall" style="margin-top: 2px">' + esc(meta) + '</div></td>' +
      '<td class="num-col" data-label="Вопросов">' + t.count + '</td>' +
      '<td data-label="Статус">' + A.chip(t.statusLabel, tone[t.status]) + '</td>' +
      '<td class="small" style="min-width: 150px" data-label="Срок">' + esc(t.deadline || '—') + '</td>' +
      '<td data-label="Сдали">' + passed(t) + '</td>' +
      '<td class="num-col strong" data-label="Средний">' + (t.avgPercent == null ? '<span class="muted" style="font-weight: 400">—</span>' : t.avgPercent + '%') + '</td>' +
      '<td class="td-actions"><div class="row-actions">' + primary(t) + menuFor(t) + '</div></td></tr>';
  }

  var GET = {
    title: function (t) { return t.title; },
    count: function (t) { return t.count; },
    status: function (t) { return STATUS_ORDER[t.status]; },
    deadline: function (t) { return t.deadlineTs; },
    passed: function (t) { return t.status === 'draft' ? null : t.submitted; },
    avg: function (t) { return t.avgPercent; },
    created: function (t) { return t.createdTs; }
  };

  function visible() {
    var q = query.toLowerCase();
    return M.tests.filter(function (t) {
      return (state.filter === 'all' || t.status === state.filter) &&
        (!state.group || (state.group === 'all' ? !t.groups.length : t.groups.indexOf(+state.group) >= 0 || !t.groups.length)) &&
        (!q || t.title.toLowerCase().indexOf(q) >= 0);
    });
  }

  function render() {
    A.remember('tests', state);
    var counts = {};
    M.tests.forEach(function (t) { counts[t.status] = (counts[t.status] || 0) + 1; });
    A.$('#filters').innerHTML = A.filterTabs(FILTERS.filter(function (f) { return f[0] === 'all' || counts[f[0]] || state.filter === f[0]; })
      .map(function (f) { return [f[0], f[1], f[0] === 'all' ? M.tests.length : (counts[f[0]] || 0)]; }), state.filter, 'Статус теста');

    var s = state.sort;
    A.$('#head').innerHTML = '<tr>' + A.sortTh('title', 'Название', s) + A.sortTh('count', 'Вопросов', s, 'num-col') + A.sortTh('status', 'Статус', s) +
      A.sortTh('deadline', 'Срок', s) + A.sortTh('passed', 'Сдали', s) + A.sortTh('avg', 'Средний', s, 'num-col') + '<th class="right"><span class="sr-only">Действия</span></th></tr>';

    var list = A.sortBy(visible(), GET[s.key] || GET.created, s.dir);
    A.$('#tests').innerHTML = list.map(row).join('') || '<tr><td colspan="7" class="empty">' + (M.tests.length
      ? 'Ничего не нашлось. <button type="button" class="link-btn" id="reset">Сбросить фильтры</button>'
      : 'Тестов пока нет. <a href="test-builder">Соберите первый</a> из одобренных вопросов банка.') + '</td></tr>';
    A.$('#result-line').textContent = list.length !== M.tests.length ? 'Показано ' + list.length + ' из ' + M.tests.length : '';
  }

  // Фильтр по группе — только если групп больше одной
  var groups = M.groups || [];
  if (groups.length > 1) {
    var gs = A.$('#group-filter');
    gs.innerHTML = '<option value="">Все группы</option>' + groups.map(function (g) { return '<option value="' + g.id + '">' + esc(g.name) + '</option>'; }).join('') +
      '<option value="all">Только «для всех»</option>';
    gs.value = state.group || '';
    gs.hidden = false;
    gs.addEventListener('change', function () { state.group = gs.value; render(); });
  } else state.group = '';

  A.$('#search').addEventListener('input', function (e) { query = e.target.value.trim(); render(); });
  A.$('#filters').addEventListener('click', function (e) {
    var b = e.target.closest('[data-filter]');
    if (b) { state.filter = b.getAttribute('data-filter'); A.keepFocus(render); }
  });
  A.$('#head').addEventListener('click', function (e) {
    var b = e.target.closest('[data-sort]');
    if (!b) return;
    var key = b.getAttribute('data-sort');
    // Числа и даты сначала по убыванию: свежие и лучшие сверху
    state.sort = A.nextSort(state.sort, key, key === 'title' || key === 'status' || key === 'deadline' ? 'asc' : 'desc');
    render();
    var again = A.$('#head [data-sort="' + key + '"]');
    if (again) again.focus();
  });

  function replace(fresh) {
    M.tests = M.tests.map(function (t) { return t.id === fresh.id ? fresh : t; });
    A.keepFocus(render);
  }

  // Срок сдачи и группы-участники после публикации
  function settings(t) {
    var html = '<label class="field">Срок сдачи<input class="input" type="datetime-local" id="set-deadline" value="' + esc(t.deadlineLocal || '') + '">' +
        '<span class="field__hint">Пусто — без срока. После срока начатые попытки досдаются автоматически.</span></label>' +
      (groups.length ? '<div class="field"><span>Кто проходит <span class="field__hint">ни одной группы — все студенты курса</span></span><div class="pick" id="set-groups">' +
        groups.map(function (g) { var on = t.groups.indexOf(g.id) >= 0; return '<button type="button" aria-pressed="' + on + '" data-g="' + g.id + '">' + esc(g.name) + ' <small>' + g.students + '</small></button>'; }).join('') +
        '</div></div>' : '');
    A.form({ title: 'Срок и группы', text: '«' + t.title + '»', html: html, ok: 'Сохранить', collect: function (d) {
      var v = d.querySelector('#set-deadline').value;
      return { value: { deadline_at: v || null, groups: A.$$('#set-groups [aria-pressed="true"]', d).map(function (b) { return +b.getAttribute('data-g'); }) } };
    } }).then(function (data) {
      if (!data) return;
      if (!groups.length) delete data.groups;
      A.api('PATCH', 'tests/' + encodeURIComponent(t.id), data).then(function (fresh) { replace(fresh); A.toast('Настройки теста сохранены'); });
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('#set-groups button');
    if (b) b.setAttribute('aria-pressed', b.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
  });

  A.$('#tests').addEventListener('click', function (e) {
    if (e.target.closest('#reset')) {
      state.filter = 'all'; state.group = ''; query = '';
      A.$('#search').value = '';
      if (groups.length > 1) A.$('#group-filter').value = '';
      render();
      return;
    }
    var rn = e.target.closest('[data-rename]');
    if (rn) {
      var tr = byId(rn.getAttribute('data-rename'));
      A.ask({ title: 'Переименовать тест', label: 'Название теста', value: tr.title, ok: 'Сохранить' }).then(function (name) {
        if (!name || name === tr.title) return;
        A.api('PATCH', 'tests/' + encodeURIComponent(tr.id), { title: name }).then(function (fresh) { replace(fresh); A.toast('Тест переименован'); });
      });
      return;
    }
    var st = e.target.closest('[data-settings]');
    if (st) { settings(byId(st.getAttribute('data-settings'))); return; }
    var w = e.target.closest('[data-weight]');
    if (w) {
      var tw = byId(w.getAttribute('data-weight'));
      A.ask({
        title: 'Баллы в Модеус', label: 'Сколько баллов даёт тест при 100 %', value: tw.weight != null ? A.num(tw.weight) : '',
        hint: 'Студент получит долю от этого числа по своему лучшему результату. Оставьте пустым — в журнале будут проценты.',
        placeholder: 'Например, 20', inputmode: 'decimal', maxlength: 6, required: false, ok: 'Сохранить',
        validate: function (v) { var n = +v.replace(',', '.'); return v && (isNaN(n) || n < 0 || n > 100) ? 'Нужно число от 0 до 100' : ''; }
      }).then(function (v) {
        if (v === null) return;
        A.api('PATCH', 'tests/' + encodeURIComponent(tw.id), { weight: v === '' ? null : +v.replace(',', '.') }).then(function (fresh) {
          replace(fresh);
          A.toast(fresh.weight != null ? 'Тест стоит ' + weightLabel(fresh.weight) : 'Баллы сняты — в журнале будут проценты');
        });
      });
      return;
    }
    var s = e.target.closest('[data-status]');
    if (s) {
      var id = s.getAttribute('data-id'), status = s.getAttribute('data-status'), t = byId(id);
      var go = function () {
        A.api('PATCH', 'tests/' + encodeURIComponent(id), { status: status }).then(function (fresh) {
          replace(fresh);
          A.toast(status === 'closed' ? 'Приём ответов закрыт' : 'Тест открыт для студентов' + (fresh.deadline ? '. ' + fresh.deadline : ''));
        });
      };
      if (status === 'closed') {
        A.confirm({ title: 'Закрыть приём ответов?', text: 'Студенты больше не смогут начать «' + t.title + '». Начатые попытки будут сданы с теми ответами, которые студенты успели сохранить. Приём можно открыть снова.', ok: 'Закрыть приём' })
          .then(function (yes) { if (yes) go(); });
      } else go();
      return;
    }
    var d = e.target.closest('[data-delete]');
    if (d) {
      var td = byId(d.getAttribute('data-delete'));
      A.confirm({
        title: 'Удалить тест?', ok: 'Удалить', danger: true,
        text: '«' + td.title + '» будет удалён без возможности восстановления.' +
          (td.submitted ? '\nВместе с ним удалятся попытки студентов: ' + td.submitted + '.' : '')
      }).then(function (yes) {
        if (!yes) return;
        A.api('DELETE', 'tests/' + encodeURIComponent(td.id)).then(function () {
          M.tests = M.tests.filter(function (x) { return x.id !== td.id; });
          render();
          A.toast('Тест удалён');
        });
      });
    }
  });

  render();
})();
