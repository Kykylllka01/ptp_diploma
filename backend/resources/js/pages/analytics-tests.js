/* Аналитика курса: темы, группы, тесты рядом и трудные вопросы; отсюда открывается разбор отдельного теста */
(function () {
  var A = window.App, M = A.M, esc = A.esc, O = M.overview;
  var STATUS = { running: ['Идёт', 'blue'], training: ['Тренировка', 'blue'], closed: ['Приём закрыт', 'gray'] };

  // Полоса с подписью: слабый результат (< 60 %) — оранжевым
  function bar(label, percent, side, href, extra) {
    var weak = percent != null && percent < 60;
    var inner = '<span class="bar-row__label">' + label + '</span>' +
      '<span class="bar-row__track"><span class="bar-row__fill' + (weak ? ' is-weak' : '') + '" style="width:' + (percent || 0) + '%"></span></span>' +
      '<span class="bar-row__value' + (weak ? ' t-orange' : '') + '">' + (percent == null ? '—' : percent + '%') + '</span>' +
      '<span class="bar-row__side">' + side + (extra || '') + '</span>';
    return href ? '<a class="bar-row bar-row--link" href="' + href + '">' + inner + A.icons.chevron + '</a>' : '<div class="bar-row">' + inner + '</div>';
  }

  // Выбор теста для подробного разбора
  if (O.tests.length) {
    var pick = A.$('#test-pick');
    pick.innerHTML = '<option value="">Разбор теста…</option>' + O.tests.map(function (t) { return '<option value="' + esc(t.id) + '">' + esc(t.title) + '</option>'; }).join('');
    pick.hidden = false;
    pick.addEventListener('change', function () { if (pick.value) location.href = 'analytics?test=' + encodeURIComponent(pick.value); });
  }

  A.$('#stats').innerHTML =
    '<div class="card stat"><div class="lbl">Средний балл по курсу</div><div class="stat__value">' + (O.avg == null ? '—' : O.avg + '%') + '</div><div class="stat__hint">лучшие попытки по всем тестам</div></div>' +
    '<div class="card stat"><div class="lbl">Сдано работ</div><div class="stat__value">' + O.works + '</div><div class="stat__hint">' + O.tests.length + ' ' + A.plural(O.tests.length, 'опубликованный тест', 'опубликованных теста', 'опубликованных тестов') + '</div></div>' +
    '<div class="card stat"><div class="lbl">Студентов сдавали</div><div class="stat__value">' + O.students + ' <small>/ ' + O.studentsTotal + '</small></div><div class="stat__hint">хотя бы один тест</div></div>' +
    '<a href="review" class="card stat' + (O.pending ? ' card--warn' : '') + '"><div class="lbl">Ждут проверки</div><div class="stat__value">' + O.pending + '</div><div class="stat__hint">' + (O.pending ? 'развёрнутые ответы — открыть очередь →' : 'все ответы проверены') + '</div></a>';

  if (!O.works) {
    // Пока никто не сдавал — один понятный экран вместо пустых графиков
    A.$('#charts').hidden = true;
    A.$('#problems-card').hidden = true;
    A.$('#tests').innerHTML = O.tests.length
      ? O.tests.map(function (t) { return bar(esc(t.title) + ' ' + A.chip(STATUS[t.status][0], STATUS[t.status][1], 'chip--sm'), null, 'пока никто не сдал', 'analytics?test=' + encodeURIComponent(t.id)); }).join('')
      : '<div class="empty">Здесь появится аналитика, когда студенты сдадут первый тест. <a href="test-builder">Собрать тест</a></div>';
    return;
  }

  // Темы курса
  A.$('#topics').innerHTML = O.topics.map(function (t) {
    return bar(esc(t.name), t.percent, t.answers + ' ' + A.plural(t.answers, 'ответ', 'ответа', 'ответов'));
  }).join('') || '<div class="empty">Появится после первых сдач</div>';
  var weak = O.topics.filter(function (t) { return t.percent < 60 && t.answers >= 5; });
  if (weak.length) {
    A.$('#topic-note').hidden = false;
    A.$('#topic-note').innerHTML = '<b class="strong">Стоит повторить:</b> ' + weak.slice(0, 3).map(function (t) { return '«' + esc(t.name) + '» — ' + t.percent + '%'; }).join(', ') +
      '. <a href="test-builder?training=1" class="strong">Собрать тренировку</a>';
  }

  // Группы
  A.$('#groups').innerHTML = O.groups.map(function (g) {
    return bar(esc(g.name), g.avg, g.students + ' ' + A.plural(g.students, 'студент', 'студента', 'студентов'));
  }).join('') + (O.groups.length < 2 ? '<p class="muted small" style="margin: 14px 0 0">Сравнение появится, когда тесты сдадут студенты из нескольких групп. Группы и ссылки-приглашения — на странице <a href="groups">«Группы»</a>.</p>' : '');

  // Тесты рядом: средний балл, сколько сдали, что ждёт проверки
  A.$('#tests').innerHTML = O.tests.map(function (t) {
    var st = STATUS[t.status] || ['', 'gray'];
    var side = (t.participants != null ? 'сдали ' + t.submitted + ' из ' + t.participants : 'сдали ' + t.submitted) + (t.last ? ' · ' + esc(t.last) : '');
    var extra = t.pending ? ' ' + A.chip(t.pending + ' ждут проверки', 'amber', 'chip--sm') : '';
    return bar('<span class="strong">' + esc(t.title) + '</span> ' + A.chip(st[0], st[1], 'chip--sm'), t.avg, side, 'analytics?test=' + encodeURIComponent(t.id), extra);
  }).join('');

  // Трудные вопросы курса
  A.$('#problems').innerHTML = O.problems.map(function (p) {
    var low = p.correct < 50;
    return '<tr><td style="min-width: 320px"><div class="clamp-2">' + esc(p.text) + '</div><div class="muted xsmall mono" style="margin-top: 2px">' + esc(p.id) + ' · ' + esc((M.types || {})[p.type] || p.type) + '</div></td>' +
      '<td class="small">' + esc(p.topic || '—') + '</td>' +
      '<td><div class="pc"><div class="progress progress--md"><div class="' + (low ? 'is-weak' : '') + '" style="width:' + p.correct + '%"></div></div><span class="strong' + (low ? ' t-orange' : '') + '">' + p.correct + '%</span></div></td>' +
      '<td class="num-col">' + p.answers + '</td>' +
      '<td class="right"><a href="question?id=' + encodeURIComponent(p.id) + '" class="btn btn--xs">Открыть</a></td></tr>';
  }).join('') || '<tr><td colspan="5" class="empty">Пока у вопросов меньше пяти ответов — выводы делать рано.</td></tr>';
})();
