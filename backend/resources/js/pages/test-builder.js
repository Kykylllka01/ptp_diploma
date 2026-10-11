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
    var pct = function (n) { return total ? (n / total * 100) + '%' : '0%'; };
    A.$('#d-easy').style.width = pct(easy); A.$('#d-med').style.width = pct(med); A.$('#d-hard').style.width = pct(hard);
    A.$('#l-easy').textContent = 'Лёгкие ' + easy; A.$('#l-med').textContent = 'Средние ' + med; A.$('#l-hard').textContent = 'Сложные ' + hard;

    // Предупреждение: в ячейке матрицы просят больше вопросов, чем одобрено в банке по выбранным темам
    var names = { easy: 'лёгких', medium: 'средних', hard: 'сложных' }, short = null;
    rows.forEach(function (r) {
      LEVELS.forEach(function (level, k) {
        var have = available(r[0], level);
        if (!short && r[2 + k] > have) short = [M.types[r[0]], names[level], have, r[2 + k]];
      });
    });
    var box = A.$('#shortage');
    box.hidden = !short;
    if (short) {
      box.innerHTML = '«' + esc(short[0]) + '», ' + short[1] + ': в банке ' + (short[2] ? 'только ' + short[2] : 'нет ни одного') +
        ', а нужно ' + short[3] + '. Тест соберётся из того, что есть. <a href="upload">Догенерировать</a>';
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

  // Группы студентов из базы; «все по ссылке» — тест видят все студенты курса
  A.$('#group').innerHTML = (M.groups || []).map(function (g) { return '<option>' + esc(g) + '</option>'; }).join('') + '<option value="">Все студенты курса</option>';
  if (A.param('training')) A.$('#training').setAttribute('aria-pressed', 'true');
  function on(id) { return A.$(id).getAttribute('aria-pressed') === 'true'; }
  A.$$('input[name=mode]').forEach(function (r) {
    r.addEventListener('change', function () {
      var n = notes[r.value];
      A.$('#mode-note').hidden = !n;
      A.$('#mode-note').innerHTML = r.value === 'manual' ? n + ' <a href="bank?status=approved">Открыть банк</a>' : n;
    });
  });

  A.$('#builder').addEventListener('submit', function (e) {
    e.preventDefault();
    var mode = A.$('input[name=mode]:checked').value;
    if (mode !== 'manual' && !+A.$('#s-count').textContent) { A.toast('Добавьте в тест хотя бы один вопрос'); return; }
    var title = A.$('#test-title').textContent.trim();
    var training = A.$('#training').getAttribute('aria-pressed') === 'true';
    // Двойной клик создавал два теста — кнопка недоступна до ответа сервера
    var submitBtn = e.submitter || A.$('#builder [type=submit]');
    if (submitBtn) submitBtn.disabled = true;
    A.api('POST', 'tests', {
      title: title,
      mode: mode,
      matrix: rows,
      topics: topics,
      group: A.$('#group').value || null,
      opens_at: A.$('#opens-at').value || null,
      deadline_at: A.$('#deadline-at').value || null,
      minutes: +A.$('#minutes').value,
      attempts: +A.$('#attempts').value,
      training: training,
      publish: true,
      settings: { shuffle: on('#sw-shuffle'), show_review: on('#sw-review'), auto_grade: on('#sw-auto'), individual: on('#sw-individual') }
    }).then(function (r) {
      var msg = mode === 'manual'
        ? 'Тест «' + title + '» создан. Отметьте вопросы в банке и нажмите «Добавить в тест» — после этого он откроется студентам'
        : 'Тест «' + title + '» опубликован: подобрано ' + r.picked + ' из ' + r.requested + ' ' + A.plural(r.requested, 'вопроса', 'вопросов', 'вопросов');
      A.toast(msg);
      setTimeout(function () { location.href = 'tests'; }, 1500);
    }, function () { if (submitBtn) submitBtn.disabled = false; });
  });

  renderMatrix(); renderTopics(); summary();

  // По умолчанию — темы, по которым есть одобренные вопросы, и все такие вопросы в матрице
  function fillFromBank() {
    rows.forEach(function (r) { LEVELS.forEach(function (level, k) { r[2 + k] = available(r[0], level); }); });
    renderMatrix(); renderTopics(); summary();
  }

  A.api('GET', 'bank-stats').then(function (s) {
    counts = s.counts || {};
    Object.keys(counts).forEach(function (t) { if (allTopics.indexOf(t) < 0) allTopics.push(t); });
    topics = allTopics.filter(function (t) { return counts[t]; });
    if (!topics.length) topics = allTopics.slice(0, 1);
    A.$('#bank-total').textContent = 'Одобрено в банке: ' + s.total;
    fillFromBank();
  });
})();
