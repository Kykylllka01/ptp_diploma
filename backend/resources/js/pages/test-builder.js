/* Экран 6. Сборка теста: матрица «тип × сложность», итог пересчитывается на лету */
(function () {
  var A = window.App, M = A.M, esc = A.esc;

  // [тип, баллы, лёгкие, средние, сложные, минут на вопрос]; числа заполняются по банку
  var rows = [
    ['single', 1, 0, 0, 0, 0.7],
    ['multiple', 2, 0, 0, 0, 1],
    ['tf', 1, 0, 0, 0, 0.5],
    ['match', 2, 0, 0, 0, 1.5],
    ['sql', 2, 0, 0, 0, 2],
    ['open', 5, 0, 0, 0, 4]
  ];
  var LEVELS = ['easy', 'medium', 'hard'];
  // Одобренные вопросы банка: тема → тип → сложность → количество
  var counts = {};
  var allTopics = (M.topics || []).slice();
  var topics = [];

  // Сколько одобренных вопросов типа и сложности есть по выбранным темам
  function available(type, level) {
    return topics.reduce(function (s, t) { return s + (((counts[t] || {})[type] || {})[level] || 0); }, 0);
  }

  function renderMatrix() {
    A.$('#matrix').innerHTML = rows.map(function (r, i) {
      var cell = function (col, label) {
        return '<td><input class="num" type="number" min="0" max="50" data-r="' + i + '" data-c="' + col + '" value="' + r[col] + '" aria-label="' + M.types[r[0]] + ', ' + label + '"></td>';
      };
      return '<tr><td>' + M.types[r[0]] + '</td>' + cell(2, 'лёгкие') + cell(3, 'средние') + cell(4, 'сложные') + '<td class="right">' + r[1] + '</td></tr>';
    }).join('');
  }

  function renderTopics() {
    A.$('#topics').innerHTML = topics.map(function (t, i) {
      return '<button type="button" class="chip chip--blue" data-topic="' + i + '" title="Убрать тему">' + esc(t) + ' ×</button>';
    }).join('');
    var rest = allTopics.filter(function (t) { return topics.indexOf(t) < 0; });
    A.$('#add-topic').innerHTML = '<option value="">+ тема</option>' + rest.map(function (t) { return '<option>' + esc(t) + '</option>'; }).join('');
    A.$('#add-topic').hidden = !rest.length;
  }

  function summary() {
    var easy = 0, med = 0, hard = 0, points = 0, minutes = 0, open = 0;
    rows.forEach(function (r) {
      var n = r[2] + r[3] + r[4];
      easy += r[2]; med += r[3]; hard += r[4];
      points += n * r[1]; minutes += n * r[5];
      if (r[0] === 'open' || r[0] === 'sql') open += n;
    });
    var total = easy + med + hard;
    A.$('#s-count').textContent = total;
    A.$('#s-points').textContent = points;
    A.$('#s-time').textContent = '~' + Math.round(minutes);
    A.$('#s-open').textContent = open;
    A.$('#s-count-l').textContent = A.plural(total, 'вопрос', 'вопроса', 'вопросов');
    A.$('#s-points-l').textContent = A.plural(points, 'балл', 'балла', 'баллов') + ' максимум';
    A.$('#s-open-l').textContent = A.plural(open, 'развёрнутый или SQL', 'развёрнутых или SQL', 'развёрнутых или SQL');
    var pct = function (n) { return total ? (n / total * 100) + '%' : '0%'; };
    A.$('#d-easy').style.width = pct(easy); A.$('#d-med').style.width = pct(med); A.$('#d-hard').style.width = pct(hard);
    A.$('#l-easy').textContent = 'Лёгкие ' + easy; A.$('#l-med').textContent = 'Средние ' + med; A.$('#l-hard').textContent = 'Сложные ' + hard;

    // В ячейке матрицы просят больше вопросов, чем одобрено в банке по выбранным темам: тест соберётся меньше.
    // got — сколько вопросов будет на самом деле, spare — запас сверх матрицы для разных вариантов
    var names = { easy: 'лёгких', medium: 'средних', hard: 'сложных' }, short = [], got = 0, spare = 0;
    rows.forEach(function (r) {
      LEVELS.forEach(function (level, k) {
        var have = available(r[0], level), need = r[2 + k];
        if (!need) return;
        got += Math.min(need, have);
        spare += Math.max(0, have - need);
        if (need > have) short.push('«' + esc(M.types[r[0]]) + '», ' + names[level] + ': ' + (have ? 'есть ' + have : 'нет') + ' из ' + need);
      });
    });
    // Вручную вопросы выбираются в банке — варианта по матрице нет
    var individual = on('#sw-individual') && (A.$('input[name=mode]:checked') || {}).value !== 'manual';
    var box = A.$('#shortage');
    box.hidden = !short.length;
    if (short.length) {
      box.innerHTML = '<b>Соберётся ' + got + ' из ' + total + ' ' + A.plural(total, 'вопроса', 'вопросов', 'вопросов') + '</b>' +
        (individual ? ' в варианте каждого студента' : '') + ' — в банке по выбранным темам не хватает: ' +
        short.join('; ') + '. Уменьшите числа в матрице или <a href="upload">догенерируйте вопросы</a>.';
    }
    var hint = A.$('#individual-note');
    hint.hidden = !individual || !got;
    if (individual && got) {
      hint.innerHTML = spare
        ? 'У каждого студента ' + got + ' ' + A.plural(got, 'вопрос', 'вопроса', 'вопросов') + ' из пула в ' + (got + spare) + ': варианты будут различаться'
        : '<span class="t-amber">Запасных вопросов нет: в банке ровно столько, сколько в матрице, — варианты у всех студентов совпадут.</span> Уменьшите числа в матрице или догенерируйте вопросы.';
    }
  }

  A.$('#matrix').addEventListener('input', function (e) {
    var el = e.target;
    if (!el.hasAttribute('data-r')) return;
    rows[+el.getAttribute('data-r')][+el.getAttribute('data-c')] = Math.max(0, Math.min(50, parseInt(el.value, 10) || 0));
    summary();
  });

  A.$('#topics').addEventListener('click', function (e) {
    var t = e.target.closest('[data-topic]');
    if (!t) return;
    if (topics.length === 1) { A.toast('Нужна хотя бы одна тема'); return; }
    topics.splice(+t.getAttribute('data-topic'), 1);
    renderTopics(); summary();
  });
  A.$('#add-topic').addEventListener('change', function (e) {
    if (!e.target.value) return;
    topics.push(e.target.value);
    renderTopics(); summary();
  });

  var notes = {
    rules: '',
    manual: 'Тест сохранится черновиком. Отметьте вопросы в банке и нажмите «Добавить в тест» — после этого тест откроется студентам.'
  };

  // Участники: «все студенты курса» или одна/несколько групп (группы и ссылки к ним — на экране «Группы»)
  var groups = M.groups || [];
  var chosen = [];
  // Групп несколько (их ведут разные преподаватели) — «всем студентам курса» только явным выбором,
  // иначе тест по ошибке увидят чужие группы
  var allPicked = groups.length < 2;
  function renderGroups() {
    A.$('#groups').innerHTML = '<button type="button" data-all aria-pressed="' + (allPicked && !chosen.length) + '">Все студенты курса</button>' +
      groups.map(function (g) {
        return '<button type="button" data-g="' + g.id + '" aria-pressed="' + (chosen.indexOf(g.id) >= 0) + '">' + esc(g.name) +
          ' <small>' + g.students + '</small></button>';
      }).join('');
    var n = chosen.length ? groups.filter(function (g) { return chosen.indexOf(g.id) >= 0; }).reduce(function (s, g) { return s + g.students; }, 0) : null;
    A.$('#groups-hint').innerHTML = !groups.length
      ? 'Групп пока нет — тест увидят все студенты. <a href="groups">Создать группу</a>'
      : !chosen.length && !allPicked ? '<span class="t-amber">Отметьте группы, для которых этот тест.</span> <a href="groups">Группы</a>'
      : (chosen.length ? 'Тест увидят ' + n + ' ' + A.plural(n, 'студент', 'студента', 'студентов') + ' выбранных групп.' : 'Тест увидят все студенты курса.') + ' <a href="groups">Группы</a>';
  }
  A.$('#groups').addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) return;
    if (b.hasAttribute('data-all')) { chosen = []; allPicked = true; }
    else {
      allPicked = false;
      var id = +b.getAttribute('data-g'), i = chosen.indexOf(id);
      if (i >= 0) chosen.splice(i, 1); else chosen.push(id);
    }
    renderGroups();
  });
  renderGroups();
  if (A.param('training')) A.$('#training').setAttribute('aria-pressed', 'true');
  function on(id) { return A.$(id).getAttribute('aria-pressed') === 'true'; }
  A.$$('input[name=mode]').forEach(function (r) {
    r.addEventListener('change', function () {
      var n = notes[r.value];
      A.$('#mode-note').hidden = !n;
      A.$('#mode-note').innerHTML = r.value === 'manual' ? n + ' <a href="bank?status=approved">Открыть банк</a>' : n;
      // Вручную матрица не нужна: вопросы выбираются в банке, а тест сохраняется черновиком
      A.$('#composition').hidden = r.value === 'manual';
      publishLabel(); summary();
    });
  });
  function publishLabel() {
    var manual = (A.$('input[name=mode]:checked') || {}).value === 'manual';
    A.$('#publish').textContent = manual ? 'Создать черновик' : on('#training') ? 'Опубликовать тренировку' : 'Опубликовать тест';
    // В тренировке попыток нет — следить не за чем
    var proctor = A.$('#sw-proctoring');
    proctor.disabled = on('#training');
    if (proctor.disabled) proctor.setAttribute('aria-pressed', 'false');
  }
  A.$('#training').addEventListener('click', function () { setTimeout(publishLabel); });
  // Свой вариант каждому: итог и запас пересчитываются под переключатель
  A.$('#sw-individual').addEventListener('click', function () { setTimeout(summary); });

  A.$('#builder').addEventListener('submit', function (e) {
    e.preventDefault();
    var mode = A.$('input[name=mode]:checked').value;
    if (mode !== 'manual' && !+A.$('#s-count').textContent) { A.toast('Добавьте в тест хотя бы один вопрос'); return; }
    var title = A.$('#test-title').value.trim();
    if (!title) { A.$('#test-title').focus(); A.toast('Введите название теста', true); return; }
    if (!chosen.length && !allPicked) {
      A.$('#groups button').focus();
      A.toast('Отметьте группы, для которых тест, или выберите «Все студенты курса»', true);
      return;
    }
    var training = A.$('#training').getAttribute('aria-pressed') === 'true';
    // Двойной клик создавал два теста — кнопка недоступна до ответа сервера
    var submitBtn = e.submitter || A.$('#builder [type=submit]');
    if (submitBtn) submitBtn.disabled = true;
    A.api('POST', 'tests', {
      title: title,
      mode: mode,
      matrix: rows,
      topics: topics,
      groups: chosen,
      opens_at: A.$('#opens-at').value || null,
      deadline_at: A.$('#deadline-at').value || null,
      minutes: +A.$('#minutes').value,
      attempts: +A.$('#attempts').value,
      weight: A.$('#weight').value === '' ? null : +A.$('#weight').value,
      training: training,
      publish: true,
      settings: { shuffle: on('#sw-shuffle'), show_review: on('#sw-review'), auto_grade: on('#sw-auto'), individual: on('#sw-individual'), proctoring: !training && on('#sw-proctoring') }
    }).then(function (r) {
      var msg = mode === 'manual'
        ? 'Тест «' + title + '» создан. Отметьте вопросы в банке и нажмите «Добавить в тест» — после этого он откроется студентам'
        : 'Тест «' + title + '» опубликован: ' + (r.test.individual
          ? 'в варианте каждого студента ' + r.picked + ' из ' + r.requested + ' ' + A.plural(r.requested, 'вопроса', 'вопросов', 'вопросов') + ', пул — ' + r.pool
          : 'подобрано ' + r.picked + ' из ' + r.requested + ' ' + A.plural(r.requested, 'вопроса', 'вопросов', 'вопросов'));
      A.toast(msg);
      setTimeout(function () { location.href = 'tests'; }, 1500);
    }, function () { if (submitBtn) submitBtn.disabled = false; });
  });

  renderMatrix(); renderTopics(); summary();
  // Заголовок страницы повторяет вводимое название
  A.$('#test-title').addEventListener('input', function (e) { A.$('#title-preview').textContent = e.target.value.trim() || 'Новый тест'; });
  // Enter в названии не публикует тест — публикация только кнопкой
  A.$('#test-title').addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });

  // По умолчанию — темы, по которым есть одобренные вопросы, и все такие вопросы в матрице
  function fillFromBank() {
    rows.forEach(function (r) { LEVELS.forEach(function (level, k) { r[2 + k] = available(r[0], level); }); });
    renderMatrix(); renderTopics(); summary();
  }

  A.api('GET', 'bank-stats').then(function (s) {
    counts = s.counts || {};
    Object.keys(counts).forEach(function (t) { if (allTopics.indexOf(t) < 0) allTopics.push(t); });
    topics = allTopics.filter(function (t) { return counts[t]; });
    var wanted = A.param('topic');
    if (wanted && allTopics.indexOf(wanted) >= 0) topics = [wanted];
    if (!topics.length) topics = allTopics.slice(0, 1);
    A.$('#bank-total').textContent = 'Одобрено в банке: ' + s.total;
    fillFromBank();
    publishLabel();
  });
})();
