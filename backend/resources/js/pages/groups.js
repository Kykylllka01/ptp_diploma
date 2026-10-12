/* Группы курса: ссылки-приглашения, открыть/закрыть приём, перевод студентов между группами */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var groups = M.groups || [];
  var ungrouped = M.ungrouped || [];

  function find(id) { return groups.filter(function (g) { return g.id === id; })[0]; }

  // Выбор группы для перевода студента: все группы курса и «без группы»
  function moveSelect(student, current) {
    var opts = groups.map(function (g) {
      return '<option value="' + g.id + '"' + (g.id === current ? ' selected' : '') + '>' + esc(g.name) + '</option>';
    }).join('') + '<option value=""' + (current == null ? ' selected' : '') + '>Без группы</option>';
    return '<select class="select select--sm" data-move="' + student.id + '" aria-label="Группа студента ' + esc(student.name) + '">' + opts + '</select>';
  }

  function studentRow(s, groupId) {
    return '<div class="list-row" style="padding: 10px 0"><div class="list-row__main"><div class="strong small">' + esc(s.name) + '</div>' +
      '<div class="list-row__meta xsmall">' + esc(s.email) + (s.since ? ' · с ' + esc(s.since) : '') + '</div></div>' +
      '<div style="width: 190px">' + moveSelect(s, groupId) + '</div></div>';
  }

  function card(g) {
    var n = g.students.length;
    var canDelete = !n && !g.tests;
    var why = n ? 'В группе есть студенты' : (g.tests ? 'Группе назначены тесты' : '');
    return '<section class="card card--pad group-card" data-group="' + g.id + '">' +
      '<div class="between" style="align-items: flex-start">' +
        '<div><h2 class="card-title card-title--sm">' + esc(g.name) + '</h2>' +
        '<div class="muted small" style="margin-top: 4px">' + n + ' ' + A.plural(n, 'студент', 'студента', 'студентов') +
          (g.tests ? ' · ' + g.tests + ' ' + A.plural(g.tests, 'тест', 'теста', 'тестов') : '') + '</div></div>' +
        A.chip(g.open ? 'Приём по ссылке открыт' : 'Приём закрыт', g.open ? 'green' : 'gray') +
      '</div>' +
      '<div class="invite' + (g.open ? '' : ' is-off') + '">' +
        '<label class="lbl" for="inv-' + g.id + '">Ссылка для регистрации</label>' +
        '<div class="invite__row"><input class="input mono" id="inv-' + g.id + '" value="' + esc(g.url) + '" readonly>' +
        '<button type="button" class="btn btn--primary" data-copy="' + g.id + '"' + (g.open ? '' : ' disabled') + '>Копировать</button></div>' +
        '<div class="muted xsmall">Код для ввода вручную: <span class="mono strong">' + esc(g.code) + '</span></div>' +
      '</div>' +
      '<div class="row-wrap" style="gap: 8px">' +
        '<button type="button" class="btn btn--xs" data-toggle="' + g.id + '">' + (g.open ? 'Закрыть приём' : 'Открыть приём') + '</button>' +
        '<button type="button" class="btn btn--xs" data-regen="' + g.id + '">Новая ссылка</button>' +
        '<button type="button" class="btn btn--xs" data-rename="' + g.id + '">Переименовать</button>' +
        '<button type="button" class="btn btn--xs" data-del="' + g.id + '"' + (canDelete ? '' : ' disabled title="' + why + '"') + '>Удалить</button>' +
      '</div>' +
      (n ? '<details class="group-card__list"' + (n <= 8 ? ' open' : '') + '><summary>Студенты группы</summary>' +
        g.students.map(function (s) { return studentRow(s, g.id); }).join('') + '</details>'
        : '<div class="muted small">Пока никто не зарегистрировался.</div>') +
      '</section>';
  }

  function render() {
    A.$('#groups').innerHTML = groups.map(card).join('') ||
      '<div class="card card--pad empty">Групп пока нет. Создайте первую — и отправьте студентам ссылку.</div>';
    A.$('#ungrouped-card').hidden = !ungrouped.length;
    A.$('#ungrouped').innerHTML = ungrouped.map(function (s) { return studentRow(s, null); }).join('');
  }

  function replace(fresh) {
    groups = groups.map(function (g) { return g.id === fresh.id ? fresh : g; });
    render();
  }

  A.$('#new-group').addEventListener('submit', function (e) {
    e.preventDefault();
    var input = A.$('#new-group-name'), name = input.value.trim();
    if (!name) { input.focus(); A.toast('Введите название группы'); return; }
    A.api('POST', 'groups', { name: name }).then(function (g) {
      groups.push(g);
      groups.sort(function (a, b) { return a.name.localeCompare(b.name, 'ru'); });
      input.value = '';
      render();
      A.toast('Группа «' + g.name + '» создана — скопируйте ссылку и отправьте студентам');
    });
  });

  function copy(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    // http://ptp.localhost не «безопасный контекст» в некоторых браузерах — копируем через выделение
    var t = document.createElement('textarea');
    t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
    document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); } finally { document.body.removeChild(t); }
    return Promise.resolve();
  }

  A.$('#groups').addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b || b.disabled) return;
    var id = +(b.getAttribute('data-copy') || b.getAttribute('data-toggle') || b.getAttribute('data-regen') || b.getAttribute('data-rename') || b.getAttribute('data-del'));
    var g = find(id);
    if (!g) return;
    if (b.hasAttribute('data-copy')) {
      copy(g.url).then(function () { A.toast('Ссылка скопирована'); });
    } else if (b.hasAttribute('data-toggle')) {
      A.api('PATCH', 'groups/' + id, { invite_open: !g.open }).then(function (fresh) {
        replace(fresh);
        A.toast(fresh.open ? 'Приём по ссылке открыт' : 'Приём закрыт: по ссылке больше не зарегистрироваться');
      });
    } else if (b.hasAttribute('data-regen')) {
      if (!confirm('Выпустить новую ссылку для группы «' + g.name + '»? Старая перестанет работать.')) return;
      A.api('POST', 'groups/' + id + '/invite').then(function (fresh) { replace(fresh); A.toast('Новая ссылка готова'); });
    } else if (b.hasAttribute('data-rename')) {
      var name = prompt('Новое название группы', g.name);
      if (!name || !name.trim() || name.trim() === g.name) return;
      A.api('PATCH', 'groups/' + id, { name: name.trim() }).then(function (fresh) { replace(fresh); A.toast('Группа переименована'); });
    } else if (b.hasAttribute('data-del')) {
      if (!confirm('Удалить группу «' + g.name + '»?')) return;
      A.api('DELETE', 'groups/' + id).then(function () {
        groups = groups.filter(function (x) { return x.id !== id; });
        render();
        A.toast('Группа удалена');
      });
    }
  });

  // Перевод студента: переносим строку в другую карточку без перезагрузки
  document.addEventListener('change', function (e) {
    var sel = e.target.closest('[data-move]');
    if (!sel) return;
    var sid = +sel.getAttribute('data-move'), to = sel.value ? +sel.value : null;
    A.api('PATCH', 'students/' + sid + '/group', { group_id: to }).then(function () {
      var student = null;
      groups.forEach(function (g) {
        g.students = g.students.filter(function (s) { if (s.id === sid) { student = s; return false; } return true; });
      });
      ungrouped = ungrouped.filter(function (s) { if (s.id === sid) { student = s; return false; } return true; });
      if (student) {
        var target = to == null ? null : find(to);
        (target ? target.students : ungrouped).push(student);
        (target ? target.students : ungrouped).sort(function (a, b) { return a.name.localeCompare(b.name, 'ru'); });
      }
      render();
      A.toast(student ? student.name + (to == null ? ' — без группы' : ' → ' + find(to).name) : 'Готово');
    }, function () { render(); });
  });

  render();
})();
