/* Экран 6. Сборка теста: матрица «тип × сложность», итог пересчитывается на лету */
(function () {
  var A = window.App, M = A.M, esc = A.esc;

  // [тип, баллы, лёгкие, средние, сложные, минут на вопрос]
  var rows = [
    ['single', 1, 4, 6, 2, 0.7],
    ['multiple', 2, 0, 4, 2, 1],
    ['sql', 2, 2, 4, 2, 2],
    ['open', 5, 0, 2, 2, 4]
  ];
  // Сколько сложных вопросов есть в банке по теме (для предупреждения о нехватке)
  var bankHard = { 'Подзапросы': 2 };
  var topics = ['Соединения (JOIN)', 'Группировка и агрегаты', 'Подзапросы'];
  var allTopics = topics.concat(['Нормализация', 'Транзакции', 'ER-моделирование', 'Реляционная модель']);

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

    // Предупреждение: сложных на тему = сложные / число тем (как считает агент-сборщик)
    var perTopic = topics.length ? Math.ceil(hard / topics.length) : 0;
    var short = topics.filter(function (t) { return bankHard[t] !== undefined && bankHard[t] < perTopic; })[0];
    var box = A.$('#shortage');
    box.hidden = !short;
    if (short) box.innerHTML = 'По теме «' + esc(short) + '» в банке только ' + bankHard[short] + ' сложных вопроса, а нужно ' + perTopic + '. <a href="upload.html">Догенерировать</a>';
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
    manual: 'Откройте банк вопросов, отметьте нужные и нажмите «Добавить в тест».',
    adaptive: 'Экзаменатор начнёт со средних вопросов и будет менять сложность после каждого ответа. Матрица задаёт пул, из которого он выбирает.'
  };
  A.$$('input[name=mode]').forEach(function (r) {
    r.addEventListener('change', function () {
      var n = notes[r.value];
      A.$('#mode-note').hidden = !n;
      A.$('#mode-note').innerHTML = r.value === 'manual' ? n + ' <a href="bank.html?status=approved">Открыть банк</a>' : n;
    });
  });

  A.$('#builder').addEventListener('submit', function (e) {
    e.preventDefault();
    if (!+A.$('#s-count').textContent) { A.toast('Добавьте в тест хотя бы один вопрос'); return; }
    A.toast('Тест «' + A.$('#test-title').textContent.trim() + '» опубликован для: ' + A.$('#group').value);
  });

  renderMatrix(); renderTopics(); summary();
})();
