/* Экран 4. Банк вопросов: вкладки по статусу, фильтры, поиск, массовые действия.
   На бэкенде фильтры станут параметрами запроса GET /api/questions?status=&type[]=&... */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var PAGE = 5;

  var f = { status: A.param('status') || 'pending', types: Object.keys(M.types), diff: [], topics: [], source: '', q: '', sort: 'flag', limit: PAGE };
  var selected = {};

  var TABS = [['all', 'Все'], ['pending', 'На проверке'], ['approved', 'Одобрены'], ['rejected', 'Отклонены']];

  function filtered(ignoreStatus) {
    var q = f.q.trim().toLowerCase();
    var list = A.questions().filter(function (x) {
      return (ignoreStatus || f.status === 'all' || x.status === f.status) &&
        f.types.indexOf(x.type) >= 0 &&
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
    var all = A.questions();
    A.$('#tabs').innerHTML = TABS.map(function (t) {
      var n = t[0] === 'all' ? all.length : all.filter(function (q) { return q.status === t[0]; }).length;
      var badge = t[0] === 'pending' && n ? '<span class="chip chip--amber chip--sm">' + n + '</span>' : '<span class="muted">' + n + '</span>';
      return '<button type="button" role="tab" class="tab' + (f.status === t[0] ? ' is-on' : '') + '" aria-selected="' + (f.status === t[0]) + '" data-tab="' + t[0] + '">' + t[1] + ' ' + badge + '</button>';
    }).join('');
  }

  function renderFilters() {
    var all = A.questions();
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
    return q.flag ? A.chip(q.flag.text, q.flag.level) : A.chip('На проверке', 'amber');
  }

  function renderList() {
    var list = filtered();
    var page = list.slice(0, f.limit);
    A.$('#list').innerHTML = page.map(function (q) {
      return '<div class="q-row">' +
        '<input type="checkbox" class="check" style="margin-top: 3px" data-select="' + q.id + '"' + (selected[q.id] ? ' checked' : '') + ' aria-label="Выбрать вопрос ' + q.id + '">' +
        '<div style="flex: 1; min-width: 0">' +
          '<a class="q-row__text" href="question?id=' + q.id + '">' + esc(q.text) + '</a>' +
          '<div class="row-wrap" style="margin-top: 10px">' + A.chip(M.types[q.type]) + A.chip(M.difficulties[q.difficulty]) + A.chip(q.topic) +
          '<span class="muted xsmall">' + esc(q.source) + '</span></div>' +
        '</div>' +
        '<div class="q-row__side">' + statusChip(q) + '<div class="q-row__id">' + q.id + '</div></div>' +
      '</div>';
    }).join('') || '<div class="empty">Ничего не найдено. Измените фильтры или поиск.</div>';

    A.$('#shown').textContent = list.length ? 'Показано ' + page.length + ' из ' + list.length : '';
    A.$('#more').hidden = page.length >= list.length;

    updateBulk();
  }

  function updateBulk() {
    var n = Object.keys(selected).length;
    A.$('#bulk').hidden = !n;
    A.$('#bulk-count').textContent = 'Выбрано ' + n;
  }

  function renderAll() { renderTabs(); renderFilters(); renderList(); }

  A.$('#search-icon').outerHTML = A.icons.search;

  A.$('#tabs').addEventListener('click', function (e) {
    var t = e.target.closest('[data-tab]');
    if (!t) return;
    f.status = t.getAttribute('data-tab'); f.limit = PAGE; selected = {};
    renderTabs(); renderList();
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
    renderFilters(); renderList();
  });

  A.$('#search').addEventListener('input', function (e) { f.q = e.target.value; f.limit = PAGE; renderList(); });
  A.$('#more').addEventListener('click', function () { f.limit += PAGE; renderList(); });
  A.$('#f-reset').addEventListener('click', function () {
    f.types = Object.keys(M.types); f.diff = []; f.topics = []; f.source = ''; f.q = ''; A.$('#search').value = '';
    renderFilters(); renderList();
  });

  // Быстрое одобрение: отметить все показанные вопросы на проверке, к которым у критика нет замечаний
  A.$('#select-clean').addEventListener('click', function () {
    var clean = filtered().filter(function (q) { return q.status === 'pending' && !q.flag; });
    if (!clean.length) { A.toast('Вопросов на проверке без замечаний нет'); return; }
    clean.forEach(function (q) { selected[q.id] = true; });
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
      location.href = '/api/questions-export?' + ids.map(function (id) { return 'ids[]=' + encodeURIComponent(id); }).join('&');
    } else if (act === 'regen') {
      // По одному: каждый вопрос — запрос к Составителю и Критику, это до минуты
      A.toast('Составитель переписывает ' + n + ' ' + word + '. Это до минуты на вопрос — не закрывайте страницу');
      var done = 0;
      ids.reduce(function (chain, id) {
        return chain.then(function () {
          return A.api('POST', 'questions/' + encodeURIComponent(id) + '/regenerate').then(function (fresh) {
            Object.assign(A.questions().filter(function (q) { return q.id === id; })[0], fresh);
            done++;
            A.$('#bulk-count').textContent = 'Переписано ' + done + ' из ' + n;
          });
        });
      }, Promise.resolve()).then(function () {
        A.toast('Составитель переписал ' + n + ' ' + word + ', критик проверил их заново');
        selected = {};
        renderAll();
      });
    } else {
      // Черновик теста, куда складываются вопросы из банка
      var draft = (M.tests || []).filter(function (t) { return t.status === 'draft'; }).pop();
      if (!draft) { A.toast('Нет черновика теста. Создайте его на странице «Тесты»'); return; }
      A.api('POST', 'tests/' + encodeURIComponent(draft.id) + '/questions', { ids: ids }).then(function (r) {
        A.toast(r.added + ' ' + A.plural(r.added, 'вопрос', 'вопроса', 'вопросов') + ' добавлено в тест «' + draft.title + '»' +
          (r.skipped ? ' · пропущено неодобренных: ' + r.skipped : ''));
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
