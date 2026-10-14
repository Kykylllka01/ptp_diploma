/* Админ-панель: ссылки-приглашения преподавателей и их аккаунты (новый пароль, закрыть доступ) */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var invites = M.invites || [], teachers = M.teachers || [];
  var ST = { active: ['Действует', 'green'], used: ['Использована', 'gray'], expired: ['Истекла', 'gray'], revoked: ['Отозвана', 'red'] };
  var filter = A.recall('admin.invites', 'active');
  if (filter !== 'active' && filter !== 'all') filter = 'active';
  var query = '';

  function copyLink(url, input) {
    A.copyText(url).then(function () { A.toast('Ссылка скопирована — отправьте её преподавателю'); },
      function () { if (input) input.select(); A.toast('Браузер не дал скопировать — ссылка выделена, нажмите Ctrl+C', true); });
  }

  function renderStats() {
    var s = M.stats || {};
    var tile = function (label, value, hint) {
      return '<div class="card stat"><div class="lbl">' + label + '</div><div class="stat__value">' + value + '</div><div class="stat__hint">' + hint + '</div></div>';
    };
    var active = invites.filter(function (i) { return i.status === 'active'; }).length;
    var off = teachers.filter(function (t) { return t.disabled; }).length;
    A.$('#stats').innerHTML =
      tile('Преподавателей', teachers.length, off ? 'из них без доступа: ' + off : 'у всех есть доступ') +
      tile('Действующих ссылок', active, active ? 'по ним ещё можно зарегистрироваться' : 'создайте ссылку ниже') +
      tile('Студентов', s.students || 0, (s.groups || 0) + ' ' + A.plural(s.groups || 0, 'группа', 'группы', 'групп')) +
      tile('Опубликовано тестов', s.tests || 0, 'у всех преподавателей');
  }

  // Расход на нейросеть: всего, за 30 дней и по агентам, в рублях
  function renderLlm() {
    var L = M.llm || { calls: 0, tokens: 0, rub: 0, rub30: 0, agents: [], rate: {} };
    var int = function (n) { return Number(n || 0).toLocaleString('ru-RU'); };
    var rub = function (n) { return Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₽'; };
    var r = L.rate || {};
    A.$('#llm-rate').textContent = r.rate
      ? (r.source === 'cbr' ? 'Курс ЦБ' + (r.date ? ' на ' + r.date.split('-').reverse().join('.') : '') : 'Курс из настроек (сайт ЦБ недоступен)') + ': ' + String(r.rate).replace('.', ',') + ' ₽ за $'
      : '';
    var tile = function (label, value, hint) {
      return '<div class="card stat"><div class="lbl">' + label + '</div><div class="stat__value">' + value + '</div><div class="stat__hint">' + hint + '</div></div>';
    };
    A.$('#llm-stats').innerHTML =
      tile('Потрачено всего', rub(L.rub), 'с первого обращения') +
      tile('За 30 дней', rub(L.rub30), 'последний месяц') +
      tile('Обращений', int(L.calls), 'к нейросети') +
      tile('Токенов', int(L.tokens), 'вход и ответ вместе');
    A.$('#llm-agents').innerHTML = L.agents.map(function (a) {
      return '<tr><td data-label="Агент" class="strong">' + esc(a.agent) + '</td><td class="num-col" data-label="Обращений">' + int(a.calls) +
        '</td><td class="num-col" data-label="Токенов">' + int(a.tokens) + '</td><td class="num-col" data-label="Стоимость">' + rub(a.rub) + '</td></tr>';
    }).join('') || '<tr><td colspan="4" class="empty">Агенты ещё не обращались к нейросети</td></tr>';
  }

  function renderInvites() {
    A.remember('admin.invites', filter);
    var active = invites.filter(function (i) { return i.status === 'active'; });
    A.$('#inv-filters').innerHTML = A.filterTabs([['active', 'Действующие', active.length], ['all', 'Все', invites.length]], filter, 'Какие ссылки показать');
    var list = filter === 'all' ? invites : active;
    A.$('#invites').innerHTML = list.map(function (i) {
      var st = ST[i.status] || ['', 'gray'];
      var who = i.teachers.length ? '<div class="muted xsmall" style="margin-top: 2px">Зарегистрировались: ' + esc(i.teachers.join(', ')) + '</div>' : '';
      var actions = i.status === 'active'
        ? '<button type="button" class="btn btn--xs btn--primary" data-copy="' + i.id + '">Копировать ссылку</button>' +
          A.menu('Действия со ссылкой', [{ label: 'Отозвать ссылку', attrs: 'data-revoke="' + i.id + '"', danger: true }], 'invite-' + i.id)
        : '';
      return '<tr><td><div class="strong">' + esc(i.note || 'Без пометки') + '</div><div class="muted xsmall">создана ' + esc(i.created) + '</div>' + who + '</td>' +
        '<td data-label="Регистрации">' + i.uses + ' из ' + i.max + '</td>' +
        '<td data-label="Действует до">' + esc(i.expires || '—') + '</td>' +
        '<td data-label="Статус">' + A.chip(st[0], st[1]) + '</td>' +
        '<td class="td-actions"><div class="row-actions">' + actions + '</div></td></tr>';
    }).join('') || '<tr><td colspan="5" class="empty">' + (invites.length
      ? 'Действующих ссылок нет. <button type="button" class="link-btn" data-filter="all">Показать все</button>'
      : 'Ссылок пока нет — создайте первую в форме выше.') + '</td></tr>';
  }

  function renderTeachers() {
    A.$('#t-search-box').hidden = teachers.length < 8;
    var q = query.toLowerCase();
    var list = teachers.filter(function (t) { return !q || (t.name + ' ' + t.email).toLowerCase().indexOf(q) >= 0; });
    A.$('#teachers').innerHTML = list.map(function (t) {
      var items = [
        { label: 'Выдать новый пароль', attrs: 'data-password="' + t.id + '"' },
        '-',
        t.disabled ? { label: 'Вернуть доступ', attrs: 'data-access="' + t.id + '" data-to="0"' }
          : { label: 'Закрыть доступ', attrs: 'data-access="' + t.id + '" data-to="1"', danger: true }
      ];
      return '<tr><td><div class="strong">' + esc(t.name) + '</div><div class="muted xsmall">' + esc(t.email) + '</div></td>' +
        '<td class="small" data-label="Как появился">' + esc(t.invite ? 'по ссылке «' + t.invite + '»' : 'заведён из консоли') + '<div class="muted xsmall">' + esc(t.since || '') + '</div></td>' +
        '<td class="num-col" data-label="Материалов">' + t.materials + '</td>' +
        '<td class="num-col" data-label="Вопросов">' + t.questions + '</td>' +
        '<td class="num-col" data-label="Тестов">' + t.tests + '</td>' +
        '<td data-label="Доступ">' + (t.disabled ? A.chip('Закрыт', 'red') : A.chip('Открыт', 'green')) + '</td>' +
        '<td class="td-actions">' + A.menu('Действия с аккаунтом ' + t.name, items, 'teacher-' + t.id) + '</td></tr>';
    }).join('') || '<tr><td colspan="7" class="empty">' + (teachers.length ? 'Никого не нашлось' : 'Преподавателей пока нет — отправьте кому-нибудь ссылку-приглашение.') + '</td></tr>';
  }

  function render() { renderStats(); renderLlm(); renderInvites(); renderTeachers(); }

  // Новая ссылка: сразу показываем и копируем
  A.$('#invite-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = A.$('#inv-create');
    btn.disabled = true;
    A.api('POST', 'admin/invites', { note: A.$('#inv-note').value.trim(), max_uses: +A.$('#inv-uses').value, days: +A.$('#inv-days').value })
      .then(function (inv) {
        invites.unshift(inv);
        A.$('#inv-note').value = '';
        var box = A.$('#inv-new');
        box.hidden = false;
        box.innerHTML = '<div class="invite"><label class="lbl" for="new-invite">Ссылка готова — отправьте её преподавателю' + (inv.note ? ' (' + esc(inv.note) + ')' : '') + '</label>' +
          '<div class="invite__row"><input class="input mono" id="new-invite" value="' + esc(inv.url) + '" readonly>' +
          '<button type="button" class="btn btn--primary" data-copy="' + inv.id + '">Копировать</button></div>' +
          '<div class="muted xsmall">' + (inv.max > 1 ? 'По ней могут зарегистрироваться до ' + inv.max + ' человек' : 'Одноразовая: после регистрации перестанет работать') + ', действует до ' + esc(inv.expires) + '.</div></div>';
        render();
        copyLink(inv.url, A.$('#new-invite'));
      })
      .then(function () { btn.disabled = false; }, function () { btn.disabled = false; });
  });

  document.addEventListener('click', function (e) {
    var b;
    if ((b = e.target.closest('[data-copy]'))) {
      var inv = invites.filter(function (i) { return i.id === +b.getAttribute('data-copy'); })[0];
      if (inv) copyLink(inv.url, b.closest('.invite') ? A.$('#new-invite') : null);
    } else if ((b = e.target.closest('#inv-filters [data-filter], #invites [data-filter]'))) {
      filter = b.getAttribute('data-filter');
      A.keepFocus(renderInvites);
    } else if ((b = e.target.closest('[data-revoke]'))) {
      var id = +b.getAttribute('data-revoke');
      A.confirm({ title: 'Отозвать ссылку?', text: 'По ней больше нельзя будет зарегистрироваться. Уже зарегистрированные преподаватели останутся.', ok: 'Отозвать', danger: true })
        .then(function (yes) {
          if (!yes) return;
          A.api('DELETE', 'admin/invites/' + id).then(function (fresh) {
            invites = invites.map(function (i) { return i.id === fresh.id ? fresh : i; });
            render();
            A.toast('Ссылка отозвана');
          });
        });
    } else if ((b = e.target.closest('[data-password]'))) {
      var t = teachers.filter(function (x) { return x.id === +b.getAttribute('data-password'); })[0];
      A.confirm({ title: 'Выдать новый пароль?', text: 'Старый пароль ' + t.name + ' перестанет работать, открытые сессии закончатся. Новый временный пароль покажем один раз.', ok: 'Выдать пароль' })
        .then(function (yes) {
          if (!yes) return;
          A.api('POST', 'admin/teachers/' + t.id + '/password').then(function (r) {
            A.form({ title: 'Новый пароль', persist: true, ok: 'Готово',
              text: t.name + ' (' + t.email + '). Передайте пароль лично — больше он не покажется. Сменить его можно в личном кабинете.',
              html: '<div class="invite__row"><input class="input mono" id="new-password" value="' + esc(r.password) + '" readonly aria-label="Временный пароль"><button type="button" class="btn" id="copy-password">Копировать</button></div>',
              collect: function () { return { value: true }; } });
            setTimeout(function () { var input = A.$('#new-password'); if (input) { input.focus(); input.select(); } }, 0);
          });
        });
    } else if ((b = e.target.closest('#copy-password'))) {
      A.copyText(A.$('#new-password').value).then(function () { A.toast('Пароль скопирован'); }, function () { A.$('#new-password').select(); });
    } else if ((b = e.target.closest('[data-access]'))) {
      var tc = teachers.filter(function (x) { return x.id === +b.getAttribute('data-access'); })[0];
      var close = b.getAttribute('data-to') === '1';
      var go = function () {
        A.api('PATCH', 'admin/teachers/' + tc.id, { disabled: close }).then(function (fresh) {
          teachers = teachers.map(function (x) { return x.id === fresh.id ? fresh : x; });
          render();
          A.toast(close ? 'Доступ закрыт: ' + fresh.name + ' больше не войдёт' : 'Доступ возвращён');
        });
      };
      if (!close) { go(); return; }
      A.confirm({ title: 'Закрыть доступ?', text: tc.name + ' не сможет войти, открытые сессии закончатся. Материалы, вопросы и тесты останутся на курсе. Доступ можно вернуть.', ok: 'Закрыть доступ', danger: true })
        .then(function (yes) { if (yes) go(); });
    }
  });
  A.$('#t-search').addEventListener('input', function (e) { query = e.target.value.trim(); renderTeachers(); });

  render();
})();
