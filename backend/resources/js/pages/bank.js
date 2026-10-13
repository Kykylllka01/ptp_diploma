/* Экран 4. Банк вопросов: вкладки по статусу, фильтры, поиск, массовые действия.
   На бэкенде фильтры станут параметрами запроса GET /api/questions?status=&type[]=&... */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var PAGE = 20;

  var f = { status: A.param('status') || 'pending', types: [], diff: [], topics: [], source: A.param('source') || '', q: '', sort: 'flag', limit: PAGE };
  var selected = {};

  // Несколько преподавателей: банк общий, по умолчанию — свои вопросы; чужие можно взять в свой тест
  var own = A.owner('bank', function () { f.limit = PAGE; selected = {}; renderAll(); });
  function pool() { return A.questions().filter(own.match); }

  var TABS = [['all', 'Все'], ['pending', 'Ждут одобрения'], ['approved', 'Одобрены'], ['rejected', 'Отклонены']];

  function filtered(ignoreStatus) {
    var q = f.q.trim().toLowerCase();
    var list = pool().filter(function (x) {
      return (ignoreStatus || f.status === 'all' || x.status === f.status) &&
        (!f.types.length || f.types.indexOf(x.type) >= 0) &&
        (!f.diff.length || f.diff.indexOf(x.difficulty) >= 0) &&
        (!f.topics.length || f.topics.indexOf(x.topic) >= 0) &&
        (!f.source || String(x.materialId) === f.source) &&
        (!q || x.text.toLowerCase().indexOf(q) >= 0 || x.id.toLowerCase().indexOf(q) >= 0);
      // Фильтрация на клиенте: банк одного курса небольшой. Для большого банка
      // есть GET /api/questions с теми же параметрами.
    });
    var rank = { red: 0, amber: 1 };
    list.sort(function (a, b) {
      if (f.sort === 'topic') return a.topic.localeCompare(b.topic, 'ru');
      if (f.sort === 'new') return b.id.localeCompare(a.id);
      var ra = a.flag && a.status === 'pending' ? rank[a.flag.level] : 9, rb = b.flag && b.status === 'pending' ? rank[b.flag.level] : 9;
      return ra - rb || b.id.localeCompare(a.id);
    });
    return list;
  }

  function renderTabs() {
    var all = pool();
    A.$('#owner').innerHTML = own.html(A.questions());
    A.$('#tabs').innerHTML = TABS.map(function (t) {
      var n = t[0] === 'all' ? all.length : all.filter(function (q) { return q.status === t[0]; }).length;
      var badge = t[0] === 'pending' && n ? '<span class="chip chip--amber chip--sm">' + n + '</span>' : '<span class="muted">' + n + '</span>';
      return '<button type="button" class="tab' + (f.status === t[0] ? ' is-on' : '') + '" aria-pressed="' + (f.status === t[0]) + '" data-tab="' + t[0] + '">' + t[1] + ' ' + badge + '</button>';
    }).join('');
  }

  function renderFilters() {
    var all = pool();
    A.$('#f-types').innerHTML = Object.keys(M.types).map(function (k) {
      var n = all.filter(function (q) { return q.type === k; }).length;
      return '<label class="ck"><input type="checkbox" data-type="' + k + '"' + (f.types.indexOf(k) >= 0 ? ' checked' : '') + '>' + M.types[k] + ' <span class="ck__count">' + n + '</span></label>';
    }).join('');
    A.$('#f-diff').innerHTML = Object.keys(M.difficulties).map(function (k) {
      var on = f.diff.indexOf(k) >= 0;
      return '<button type="button" class="btn btn--xs' + (on ? ' is-on' : '') + '" aria-pressed="' + on + '" data-diff="' + k + '">' + M.difficulties[k] + '</button>';
    }).join('');
    A.$('#f-topics').innerHTML = M.topics.map(function (t) {
      return '<label class="ck"><input type="checkbox" data-topic="' + esc(t) + '"' + (f.topics.indexOf(t) >= 0 ? ' checked' : '') + '>' + esc(t) + '</label>';
    }).join('');
    A.$('#f-source').innerHTML = '<option value="">Все материалы</option>' + M.materials.map(function (m) {
      return '<option value="' + m.id + '"' + (f.source === String(m.id) ? ' selected' : '') + '>' + esc(m.title) + '</option>';
    }).join('');
  }

  function statusChip(q) {
    if (q.status === 'approved') return A.chip('Одобрен', 'green');
    if (q.status === 'rejected') return A.chip('Отклонён', 'red');
    return q.flag ? A.chip(q.flag.level === 'red' ? 'Нужна правка' : 'Есть замечание', q.flag.level) : A.chip('Ждёт одобрения', 'amber');
  }

  // Вопрос без замечаний: Критик его проверил и ни Критик, ни Каталогизатор ничего не пометили
  function isClean(q) { return !!(q.critic && q.critic.score != null) && !q.flag; }

  // Что сказал Критик — видно сразу, без открытия вопроса
  function criticBadge(q) {
    var c = q.critic;
    if (!c || c.score == null) {
      return q.flag && /Критик/.test(q.flag.text)
        ? '<span class="critic-badge critic-badge--none" title="Модель не ответила — проверьте вопрос сами">Критик не проверил</span>'
        : '<span class="critic-badge critic-badge--none" title="Вопрос создан или изменён вручную">Без проверки Критика</span>';
    }
    var score = ' · ' + c.score + '/10';
    return q.flag
      ? '<span class="critic-badge critic-badge--warn" title="Откройте вопрос: замечания Критика и как их исправить">Критик: есть замечания' + score + '</span>'
      : '<span class="critic-badge critic-badge--ok" title="Критик проверил корректность, однозначность и соответствие материалу">' + A.icons.check + 'Проверен Критиком' + score + '</span>';
  }

  function renderList() {
    var list = filtered();
    var page = list.slice(0, f.limit);
    A.$('#list').innerHTML = page.map(function (q) {
      return '<div class="q-row">' +
        '<input type="checkbox" class="check" style="margin-top: 3px" data-select="' + q.id + '"' + (selected[q.id] ? ' checked' : '') + ' aria-label="Выбрать вопрос ' + q.id + '">' +
        '<div style="flex: 1; min-width: 0">' +
          '<a class="q-row__text" href="question?id=' + q.id + '">' + esc(q.text) + '</a>' +
          (q.flag && q.status === 'pending' ? '<div class="q-row__flag q-row__flag--' + esc(q.flag.level) + '">' + A.icons.flag + '<span>' + esc(q.flag.text) + '</span></div>' : '') +
          '<div class="row-wrap" style="margin-top: 10px">' + criticBadge(q) + A.chip(M.types[q.type]) + A.chip(M.difficulties[q.difficulty]) + A.chip(q.topic) +
          '<span class="muted xsmall">' + esc([own.by(q), q.source].filter(Boolean).join(' · ')) + '</span></div>' +
        '</div>' +
        '<div class="q-row__side">' + statusChip(q) + '<div class="q-row__id">' + q.id + '</div></div>' +
      '</div>';
    }).join('') || '<div class="empty">' + emptyText() + '</div>';

    A.$('#shown').textContent = list.length ? 'Показано ' + page.length + ' из ' + list.length : '';
    var clean = list.filter(function (q) { return q.status === 'pending' && isClean(q); }).length;
    A.$('#clean-count').textContent = clean;
    A.$('#select-clean').disabled = !clean;
    A.$('#more').hidden = page.length >= list.length;

    updateBulk();
  }

  function emptyText() {
    var all = pool();
    if (!all.length && A.questions().length) return 'Своих вопросов у вас пока нет. <button type="button" class="link-btn" data-owner="all">Показать вопросы всех преподавателей</button>';
    if (!all.length) return 'Банк пуст. <a href="upload">Загрузите лекцию</a> — агенты составят по ней вопросы.';
    var filtersOn = f.types.length || f.diff.length || f.topics.length || f.source || f.q.trim();
    if (filtersOn) return 'Под фильтры ничего не подходит. <button type="button" class="link-btn" data-reset>Сбросить фильтры</button>';
    if (f.status === 'pending') return 'Все вопросы разобраны — новых на проверке нет. <button type="button" class="link-btn" data-tab="all">Показать все</button>';
    return 'В этой вкладке пока пусто. <button type="button" class="link-btn" data-tab="all">Показать все</button>';
  }

  function updateBulk() {
    var n = Object.keys(selected).length;
    A.$('#bulk').hidden = !n;
    A.$('#bulk-count').textContent = 'Выбрано ' + n;
  }

  function renderAll() { renderTabs(); renderFilters(); renderList(); }

  A.$('#search-icon').outerHTML = A.icons.search;

  A.$('#list').addEventListener('click', function (e) {
    if (e.target.closest('[data-reset]')) A.$('#f-reset').click();
    var t = e.target.closest('[data-tab]');
    if (t) { f.status = t.getAttribute('data-tab'); f.limit = PAGE; selected = {}; renderTabs(); renderList(); }
  });

  A.$('#tabs').addEventListener('click', function (e) {
    var t = e.target.closest('[data-tab]');
    if (!t) return;
    f.status = t.getAttribute('data-tab'); f.limit = PAGE; selected = {};
    A.keepFocus(function () { renderTabs(); renderList(); });
  });

  document.addEventListener('change', function (e) {
    var el = e.target;
    if (el.hasAttribute('data-type')) {
      var k = el.getAttribute('data-type');
      f.types = el.checked ? f.types.concat(k) : f.types.filter(function (x) { return x !== k; });
    } else if (el.hasAttribute('data-topic')) {
      var t = el.getAttribute('data-topic');
      f.topics = el.checked ? f.topics.concat(t) : f.topics.filter(function (x) { return x !== t; });
    } else if (el.id === 'f-source') {
      f.source = el.value;
    } else if (el.id === 'sort') {
      f.sort = el.value;
    } else if (el.hasAttribute('data-select')) {
      if (el.checked) selected[el.getAttribute('data-select')] = true;
      else delete selected[el.getAttribute('data-select')];
      updateBulk();
      return;
    } else return;
    // Смена фильтров снимает выбор: массовое действие не должно задеть уже скрытые вопросы
    selected = {};
    f.limit = PAGE;
    renderList();
  });

  A.$('#f-diff').addEventListener('click', function (e) {
    var b = e.target.closest('[data-diff]');
    if (!b) return;
    var k = b.getAttribute('data-diff');
    f.diff = f.diff.indexOf(k) >= 0 ? f.diff.filter(function (x) { return x !== k; }) : f.diff.concat(k);
    A.keepFocus(function () { renderFilters(); renderList(); });
  });

  A.$('#search').addEventListener('input', function (e) { f.q = e.target.value; f.limit = PAGE; renderList(); });
  A.$('#more').addEventListener('click', function () { f.limit += PAGE; renderList(); });
  A.$('#f-reset').addEventListener('click', function () {
    f.types = []; f.diff = []; f.topics = []; f.source = ''; f.q = ''; A.$('#search').value = '';
    renderFilters(); renderList();
  });

  // Быстрое одобрение: отметить все показанные вопросы на проверке, к которым у критика нет замечаний
  A.$('#select-clean').addEventListener('click', function () {
    var clean = filtered().filter(function (q) { return q.status === 'pending' && isClean(q); });
    if (!clean.length) { A.toast('Вопросов на проверке без замечаний нет'); return; }
    clean.forEach(function (q) { selected[q.id] = true; });
    // Одобрять можно только то, что видно: раскрываем список до последнего выбранного вопроса
    var list = filtered(), last = list.indexOf(clean[clean.length - 1]);
    if (last >= f.limit) f.limit = last + 1;
    renderList();
    A.toast('Выбрано ' + clean.length + ' ' + A.plural(clean.length, 'вопрос', 'вопроса', 'вопросов') + ' без замечаний критика');
  });

  A.$('#bulk').addEventListener('click', function (e) {
    var b = e.target.closest('[data-bulk]');
    if (!b) return;
    var ids = Object.keys(selected), n = ids.length, act = b.getAttribute('data-bulk');
    var word = A.plural(n, 'вопрос', 'вопроса', 'вопросов');
    if (act === 'approve' || act === 'reject') {
      var status = act === 'approve' ? 'approved' : 'rejected';
      A.api('POST', 'questions/bulk', { ids: ids, action: act }).then(function () {
        A.questions().forEach(function (q) { if (ids.indexOf(q.id) >= 0) { q.status = status; q.flag = null; } });
        A.toast((act === 'approve' ? 'Одобрено: ' : 'Отклонено: ') + n + ' ' + word);
        selected = {};
        renderAll();
      });
    } else if (act === 'export') {
      // Адрес с сотнями ids упрётся в лимит длины URL — выгружаем частями
      if (n > 150) { A.toast('За раз можно выгрузить до 150 вопросов — сузьте выбор фильтром', true); return; }
      location.href = '/api/questions-export?' + ids.map(function (id) { return 'ids[]=' + encodeURIComponent(id); }).join('&');
    } else if (act === 'regen') {
      // По одному: каждый вопрос — запрос к Составителю и Критику, это до минуты
      A.toast('Составитель переписывает ' + n + ' ' + word + '. Это до минуты на вопрос — не закрывайте страницу');
      var done = 0, failed = [];
      A.$$('#bulk button').forEach(function (x) { x.disabled = true; });
      b.setAttribute('aria-busy', 'true');
      A.$('#bulk-count').textContent = 'Переписано 0 из ' + n + '…';
      ids.reduce(function (chain, id) {
        return chain.then(function () {
          return A.api('POST', 'questions/' + encodeURIComponent(id) + '/regenerate').then(function (fresh) {
            Object.assign(A.questions().filter(function (q) { return q.id === id; })[0], fresh);
            done++;
          }, function () { failed.push(id); }).then(function () {
            A.$('#bulk-count').textContent = 'Переписано ' + done + ' из ' + n + '…';
          });
        });
      }, Promise.resolve()).then(function () {
        A.$$('#bulk button').forEach(function (x) { x.disabled = false; });
        b.removeAttribute('aria-busy');
        A.toast(failed.length ? 'Переписано ' + done + ' из ' + n + '. Не удалось: ' + failed.join(', ') + ' — попробуйте ещё раз'
          : 'Составитель переписал ' + n + ' ' + word + ', Критик проверил их заново', !!failed.length);
        selected = {};
        failed.forEach(function (id) { selected[id] = true; });
        renderAll();
      });
    } else {
      // Черновик теста, куда складываются вопросы из банка
      var drafts = (M.tests || []).filter(function (t) { return t.status === 'draft'; }).reverse();
      if (!drafts.length) { A.toast('Вопросы добавляются в черновик теста. Соберите тест в режиме «Вручную» — он сохранится черновиком', true); return; }
      A.choose({
        title: 'Добавить в тест', text: 'Выбрано ' + n + ' ' + word + '. Неодобренные вопросы в тест не попадут.', ok: 'Добавить',
        options: drafts.map(function (t) { return { value: t.id, label: t.title, hint: 'черновик · вопросов: ' + t.count }; })
      }).then(function (code) {
        if (!code) return;
        var draft = drafts.filter(function (t) { return t.id === code; })[0];
        A.api('POST', 'tests/' + encodeURIComponent(code) + '/questions', { ids: ids }).then(function (r) {
          draft.count += r.added;
          A.toast(r.added + ' ' + A.plural(r.added, 'вопрос', 'вопроса', 'вопросов') + ' добавлено в «' + draft.title + '»' +
            (r.skipped ? ' · пропущено неодобренных: ' + r.skipped : ''));
        });
      });
    }
  });

  A.$('#add-manual').addEventListener('click', function () {
    A.api('POST', 'questions', { type: 'single' }).then(function (q) { location.href = 'question?id=' + encodeURIComponent(q.id); });
  });

  A.$$('[data-toast]').forEach(function (b) {
    b.addEventListener('click', function () { A.toast(b.getAttribute('data-toast')); });
  });

  renderAll();
})();
