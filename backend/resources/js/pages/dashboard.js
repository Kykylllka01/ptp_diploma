/* Экран 1. Обзор преподавателя */
(function () {
  var A = window.App, M = A.M, esc = A.esc;

  var qs = A.questions();
  var pending = qs.filter(function (q) { return q.status === 'pending'; });
  var openToReview = A.reviews().filter(function (r) { return r.status === 'pending'; }).length;
  var running = M.tests.filter(function (t) { return t.status === 'running' || t.status === 'training'; }).length;

  A.$('#btn-upload').insertAdjacentHTML('afterbegin', A.icons.upload);
  A.$('#btn-build').insertAdjacentHTML('afterbegin', A.icons.plus);
  A.$('#teacher-name').textContent = M.teacher.firstName;
  var lastMaterial = M.materials.filter(function (m) { return qs.some(function (q) { return q.materialId === m.id && q.status === 'pending'; }); })[0];
  A.$('#greeting-sub').textContent = pending.length
    ? (lastMaterial ? 'Агенты разобрали «' + lastMaterial.title + '»: ' : 'Агенты подготовили вопросы: ') + pending.length + ' ' +
      A.plural(pending.length, 'новый вопрос ждёт', 'новых вопроса ждут', 'новых вопросов ждут') + ' вашей проверки.'
    : qs.length ? 'Все новые вопросы проверены. Можно собирать тест.'
    : 'Банк вопросов пуст. Загрузите лекцию, и агенты составят по ней вопросы.';

  A.$('#stats').innerHTML =
    '<div class="card stat"><div class="lbl">Вопросов в банке</div><div class="stat__value">' + qs.length + '</div><div class="stat__hint">' + (M.materials.length ? 'по ' + M.materials.length + ' ' + A.plural(M.materials.length, 'материалу', 'материалам', 'материалам') : 'материалов пока нет') + '</div></div>' +
    '<a href="bank" class="card stat' + (pending.length ? ' card--warn' : '') + '"><div class="lbl">Ждут проверки</div><div class="stat__value">' + pending.length + '</div><div class="stat__hint">Открыть очередь →</div></a>' +
    '<div class="card stat"><div class="lbl">Активных тестов</div><div class="stat__value">' + running + '</div><div class="stat__hint">' + (M.participants ? M.participants + ' ' + A.plural(M.participants, 'участник', 'участника', 'участников') : 'участников пока нет') + '</div></div>' +
    '<a href="review" class="card stat"><div class="lbl">Открытые ответы</div><div class="stat__value">' + openToReview + '</div><div class="stat__hint">агент оценил, нужна ваша подпись</div></a>';

  A.$('#materials').innerHTML = M.materials.map(function (m) {
    var mine = qs.filter(function (q) { return q.materialId === m.id; });
    var p = mine.filter(function (q) { return q.status === 'pending'; }).length;
    // Статус материала, а не только вопросов: в обработке, ошибка, без вопросов
    var status = m.status === 'processing' ? A.chip('В обработке', 'blue')
      : m.status === 'error' ? A.chip('Ошибка чтения', 'red')
      : p ? A.chip(p + ' на проверке', 'amber')
      : mine.length ? A.chip('Готово', 'green') : A.chip('Вопросов нет');
    // Вся строка — кнопка: по клику открывается карточка материала
    return '<button type="button" class="list-row list-row--btn" data-material="' + m.id + '" aria-haspopup="dialog">' +
      '<span class="filetype">' + esc(m.kind) + '</span>' +
      '<span class="list-row__main"><span class="strong">' + esc(m.title) + '</span><span class="list-row__meta">' + esc([m.meta, 'загружено ' + m.date].filter(Boolean).join(' · ')) + '</span></span>' +
      status +
      '<span class="muted small" style="width: 90px; text-align: right">' + mine.length + ' ' + A.plural(mine.length, 'вопрос', 'вопроса', 'вопросов') + '</span>' +
      '<svg class="list-row__go" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>' +
    '</button>';
  }).join('') || '<div class="empty">Материалов пока нет. <a href="upload">Загрузите лекцию</a> — агенты составят по ней вопросы.</div>';

  // ---------- Карточка материала ----------
  var modal = A.$('#material-modal');
  A.$('#mm-close').innerHTML = A.icons.x;
  A.$('#mm-close').addEventListener('click', function () { modal.close(); });
  // Клик по затемнённому фону закрывает окно
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.close(); });

  var STATE = { uploaded: 'Загружен', processing: 'В обработке', ready: 'Текст извлечён', error: 'Ошибка чтения' };
  var RUN = { queued: ['В очереди', 'gray'], running: ['Идёт', 'blue'], paused: ['Пауза', 'gray'], done: ['Готово', 'green'], failed: ['Ошибка', 'red'], cancelled: ['Остановлен', 'gray'] };

  function tile(value, label, tone) {
    return '<div class="tile' + (tone ? ' tile--' + tone : '') + '"><div class="tile__value">' + value + '</div><div class="tile__label">' + label + '</div></div>';
  }

  function details(d) {
    var q = d.questions;
    var types = Object.keys(q.byType).map(function (k) { return A.chip((M.types || {})[k] || k) + '<span class="muted small">' + q.byType[k] + '</span>'; }).join(' ');
    var runs = d.runs.map(function (r) {
      var st = RUN[r.status] || [r.status, 'gray'];
      return '<a class="list-row" href="pipeline?run=' + r.id + '" style="padding: 10px 0; text-decoration: none; color: inherit">' +
        '<span class="list-row__main"><span class="strong small">Запуск №' + r.id + '</span><span class="list-row__meta xsmall">' + esc(r.date || '') + ' · составлено ' + r.written +
        (r.rejected ? ', отклонено Критиком ' + r.rejected : '') + (r.disputed ? ', спорных ' + r.disputed : '') + '</span></span>' +
        (r.cost != null ? '<span class="muted small">$' + String(r.cost).replace('.', ',') + '</span>' : '') + A.chip(st[0], st[1]) + '</a>';
    }).join('');
    return '<div class="row-wrap" style="gap: 8px">' + A.chip(STATE[d.status] || d.status, d.status === 'error' ? 'red' : (d.status === 'processing' ? 'blue' : 'green')) +
        '<span class="muted small">' + esc([d.size, 'загружен ' + d.date].filter(Boolean).join(' · ')) + '</span></div>' +
      '<section><div class="lbl">Текст</div>' +
        (d.chars ? '<p class="small" style="margin: 6px 0 0">' + d.chars.toLocaleString('ru-RU') + ' символов · ' + d.fragments + ' ' + A.plural(d.fragments, 'фрагмент', 'фрагмента', 'фрагментов') + ' для Аналитика</p>' +
          '<blockquote class="quote small" style="margin-top: 10px">' + esc(d.excerpt) + (d.chars > 600 ? '…' : '') + '</blockquote>'
          : '<p class="small t-red" style="margin: 6px 0 0">Текст не извлечён — по этому материалу агенты не составят вопросы. Если это скан, загрузите версию с текстовым слоем.</p>') +
      '</section>' +
      '<section><div class="lbl">Вопросы по материалу</div>' +
        '<div class="grid-tiles" style="margin-top: 10px">' + tile(q.total, 'всего') + tile(q.pending, 'ждут одобрения', q.pending ? 'amber' : '') +
          tile(q.approved, 'одобрено', q.approved ? 'green' : '') + tile(q.rejected, 'отклонено') + '</div>' +
        (q.total ? '<p class="small" style="margin: 12px 0 0"><span class="critic-badge critic-badge--ok">' + A.icons.check + 'Критик без замечаний: ' + q.clean + '</span> ' +
          (q.flagged ? '<span class="critic-badge critic-badge--warn">С замечаниями: ' + q.flagged + '</span>' : '') + '</p>' : '') +
        (types ? '<div class="row-wrap" style="margin-top: 10px">' + types + '</div>' : '') +
      '</section>' +
      (runs ? '<section><div class="lbl">Запуски агентов</div><div style="margin-top: 4px">' + runs + '</div></section>' : '') +
      '<div class="actions" style="gap: 10px">' +
        (q.total ? '<a class="btn btn--primary" href="bank?status=' + (q.pending ? 'pending' : 'all') + '&source=' + d.id + '">Вопросы в банке</a>' : '') +
        '<a class="btn" href="upload">' + (q.total ? 'Сгенерировать ещё' : 'Сгенерировать вопросы') + '</a>' +
      '</div>';
  }

  A.$('#materials').addEventListener('click', function (e) {
    var row = e.target.closest('[data-material]');
    if (!row) return;
    var m = M.materials.filter(function (x) { return String(x.id) === row.getAttribute('data-material'); })[0];
    A.$('#mm-kind').textContent = m.kind;
    A.$('#mm-title').textContent = m.title;
    A.$('#mm-body').innerHTML = '<div class="muted small">Загружаю…</div>';
    modal.showModal();
    A.api('GET', 'materials/' + m.id).then(function (d) { A.$('#mm-body').innerHTML = details(d); },
      function () { A.$('#mm-body').innerHTML = '<div class="t-red small">Не удалось загрузить сведения о материале.</div>'; });
  });

  var dot = { done: 'green', warn: 'amber', work: 'blue', wait: 'gray' };
  A.$('#agents').innerHTML = M.agents.map(function (a) {
    return '<div style="display: flex; gap: 12px; align-items: flex-start"><span class="dot dot--' + dot[a.state] + '"></span>' +
      '<div><div class="strong">' + esc(a.name) + '</div><div class="muted small">' + esc(a.summary) + '</div></div></div>';
  }).join('');

  var tone = { running: 'blue', training: 'blue', draft: 'gray' };
  A.$('#tests').innerHTML = M.tests.map(function (t) {
    var link = t.submitted
      ? '<a href="analytics?test=' + encodeURIComponent(t.id) + '">Результаты</a>'
      : t.count ? '<a href="preview?test=' + encodeURIComponent(t.id) + '">Пройти самому</a>' : '<a href="tests">Открыть</a>';
    return '<tr><td class="strong">' + esc(t.title) + '</td><td>' + t.count + '</td><td>' + A.chip(t.statusLabel, tone[t.status]) +
      '</td><td>' + t.passed + '</td><td>' + t.avg + '</td><td class="right">' + link + '</td></tr>';
  }).join('');
})();
