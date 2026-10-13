/* Группы курса: ссылки-приглашения, открыть/закрыть приём, перевод студентов между группами */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var groups = M.groups || [];
  var ungrouped = M.ungrouped || [];
  // Какие списки студентов раскрыты: после перевода студента карточки перерисовываются, а список не должен сворачиваться
  var openLists = {};

  function find(id) { return groups.filter(function (g) { return g.id === id; })[0]; }

  // Выбор группы для перевода студента: все группы курса и «без группы»
  function moveSelect(student, current) {
    var opts = groups.map(function (g) {
      return '<option value="' + g.id + '"' + (g.id === current ? ' selected' : '') + '>' + esc(g.name) + '</option>';
    }).join('') + '<option value=""' + (current == null ? ' selected' : '') + '>Без группы</option>';
    return '<select class="select select--sm" data-move="' + student.id + '" aria-label="Группа студента ' + esc(student.name) + '">' + opts + '</select>';
  }

  function studentRow(s, groupId) {
    var ava = s.avatar ? '<img class="avatar avatar--sm avatar--img" src="' + esc(s.avatar) + '" alt="">' : '<span class="avatar avatar--sm avatar--gray">' + esc(s.initials || '') + '</span>';
    return '<div class="list-row" style="padding: 10px 0; gap: 12px">' + ava + '<div class="list-row__main"><div class="strong small">' + esc(s.name) + '</div>' +
      '<div class="list-row__meta xsmall">' + esc(s.email) + (s.since ? ' · зарегистрирован ' + esc(s.since) : '') + '</div></div>' +
      '<div style="width: 190px">' + moveSelect(s, groupId) + '</div>' +
      A.menu('Действия со студентом ' + s.name, [{ label: 'Выдать новый пароль', attrs: 'data-password="' + s.id + '"' }], 'student-' + s.id) + '</div>';
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
        A.chip(g.open ? 'Регистрация открыта' : 'Регистрация закрыта', g.open ? 'green' : 'gray') +
      '</div>' +
      '<div class="invite' + (g.open ? '' : ' is-off') + '">' +
        '<label class="lbl" for="inv-' + g.id + '">Ссылка для регистрации</label>' +
        '<div class="invite__row"><input class="input mono" id="inv-' + g.id + '" value="' + esc(g.url) + '" readonly>' +
        '<button type="button" class="btn btn--primary" data-copy="' + g.id + '"' + (g.open ? '' : ' disabled') + '>Копировать</button></div>' +
        '<div class="muted xsmall">Код для ввода вручную: <span class="mono strong">' + esc(g.code) + '</span></div>' +
      '</div>' +
      '<div class="row-wrap" style="gap: 8px">' +
        '<button type="button" class="btn btn--xs" data-toggle="' + g.id + '">' + (g.open ? 'Закрыть регистрацию' : 'Открыть регистрацию') + '</button>' +
        '<button type="button" class="btn btn--xs" data-regen="' + g.id + '">Новая ссылка</button>' +
        '<button type="button" class="btn btn--xs" data-rename="' + g.id + '">Переименовать</button>' +
        '<button type="button" class="btn btn--xs" data-del="' + g.id + '" data-why="' + esc(canDelete ? '' : why) + '"' + (canDelete ? '' : ' aria-disabled="true"') + '>Удалить</button>' +
      '</div>' +
      (n ? '<details class="group-card__list" data-list="' + g.id + '"' + ((openLists[g.id] != null ? openLists[g.id] : n <= 8) ? ' open' : '') + '><summary>Студенты группы</summary>' +
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
    A.keepFocus(render);
  }
  A.$('#groups').addEventListener('toggle', function (e) {
    var d = e.target.closest && e.target.closest('[data-list]');
    if (d) openLists[d.getAttribute('data-list')] = d.open;
  }, true);

  A.$('#new-group').addEventListener('submit', function (e) {
    e.preventDefault();
    var input = A.$('#new-group-name'), name = input.value.trim();
    if (!name) { input.focus(); A.toast('Введите название группы', true); return; }
    A.api('POST', 'groups', { name: name }).then(function (g) {
      groups.push(g);
      groups.sort(function (a, b) { return a.name.localeCompare(b.name, 'ru'); });
      input.value = '';
      render();
      A.toast('Группа «' + g.name + '» создана — скопируйте ссылку и отправьте студентам');
    });
  });

  var copy = A.copyText;

  A.$('#groups').addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b || b.disabled || b.hasAttribute('data-menu') || b.hasAttribute('data-password')) return;
    var id = +(b.getAttribute('data-copy') || b.getAttribute('data-toggle') || b.getAttribute('data-regen') || b.getAttribute('data-rename') || b.getAttribute('data-del'));
    var g = find(id);
    if (!g) return;
    if (b.hasAttribute('data-copy')) {
      copy(g.url).then(function () { A.toast('Ссылка скопирована'); }, function () { A.$('#inv-' + id).select(); A.toast('Браузер не дал скопировать — ссылка выделена, нажмите Ctrl+C', true); });
    } else if (b.hasAttribute('data-toggle')) {
      A.api('PATCH', 'groups/' + id, { invite_open: !g.open }).then(function (fresh) {
        replace(fresh);
        A.toast(fresh.open ? 'Регистрация по ссылке открыта' : 'Регистрация закрыта: по ссылке больше не зарегистрироваться');
      });
    } else if (b.hasAttribute('data-regen')) {
      A.confirm({ title: 'Выпустить новую ссылку?', text: 'Старая ссылка группы «' + g.name + '» перестанет работать. Уже зарегистрированные студенты останутся в группе.', ok: 'Выпустить' }).then(function (yes) {
        if (yes) A.api('POST', 'groups/' + id + '/invite').then(function (fresh) { replace(fresh); A.toast('Новая ссылка готова — отправьте её студентам'); });
      });
    } else if (b.hasAttribute('data-rename')) {
      A.ask({ title: 'Переименовать группу', label: 'Название группы', value: g.name, maxlength: 100 }).then(function (name) {
        if (name && name !== g.name) A.api('PATCH', 'groups/' + id, { name: name }).then(function (fresh) { replace(fresh); A.toast('Группа переименована'); });
      });
    } else if (b.hasAttribute('data-del')) {
      // Удалить нельзя — объясняем почему, а не прячем причину в подсказку недоступной кнопки
      if (b.getAttribute('data-why')) { A.toast(b.getAttribute('data-why') + ' — такую группу удалить нельзя', true); return; }
      A.confirm({ title: 'Удалить группу?', text: 'Группа «' + g.name + '» будет удалена, её ссылка перестанет работать.', ok: 'Удалить', danger: true }).then(function (yes) {
        if (!yes) return;
        A.api('DELETE', 'groups/' + id).then(function () {
          groups = groups.filter(function (x) { return x.id !== id; });
          render();
          A.toast('Группа удалена');
        });
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
      A.keepFocus(render);
      A.toast(student ? student.name + (to == null ? ' — без группы' : ' → ' + find(to).name) : 'Готово');
    }, function () { render(); });
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest('#copy-password')) return;
    var input = A.$('#new-password');
    copy(input.value).then(function () { A.toast('Пароль скопирован'); }, function () { input.select(); A.toast('Браузер не дал скопировать — пароль выделен, нажмите Ctrl+C', true); });
  });

  // Забытый пароль: преподаватель выдаёт временный и передаёт студенту лично
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-password]');
    if (!b) return;
    var sid = +b.getAttribute('data-password'), st = null;
    groups.concat([{ students: ungrouped }]).forEach(function (g) { g.students.forEach(function (x) { if (x.id === sid) st = x; }); });
    A.confirm({ title: 'Выдать новый пароль?', text: 'Старый пароль ' + st.name + ' перестанет работать. Новый временный пароль покажем один раз — передайте его студенту лично.', ok: 'Выдать пароль' })
      .then(function (yes) {
        if (!yes) return;
        A.api('POST', 'students/' + sid + '/password').then(function (r) {
          A.form({ title: 'Новый пароль', persist: true, ok: 'Готово',
            text: st.name + ' (' + st.email + '). Передайте пароль студенту лично — больше он не покажется. Сменить его можно в личном кабинете.',
            html: '<div class="invite__row"><input class="input mono" id="new-password" value="' + esc(r.password) + '" readonly aria-label="Временный пароль"><button type="button" class="btn" id="copy-password">Копировать</button></div>',
            collect: function () { return { value: true }; } });
          setTimeout(function () {
            var input = A.$('#new-password');
            if (input) { input.focus(); input.select(); }
          }, 0);
        });
      });
  });

  render();
})();
