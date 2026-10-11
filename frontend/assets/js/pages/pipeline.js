/* Экран 3. Работа агентов — имитация конвейера.
   В реальном приложении состояние приходит с сервера (polling или WebSocket / Laravel Echo). */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var TOTAL = 60;

  var agents = [
    { id: 'analyst', no: '01', name: 'Аналитик', role: 'Разбор материалов' },
    { id: 'writer', no: '02', name: 'Составитель', role: 'Генерация вопросов' },
    { id: 'critic', no: '03', name: 'Критик', role: 'Контроль качества' },
    { id: 'catalog', no: '04', name: 'Каталогизатор', role: 'Теги, сложность, дубли с банком' }
  ];
  var s = { tick: 0, written: 0, checked: 0, rejected: 0, disputed: 0, catalog: 0, log: 0, stopped: false };

  var fresh = A.questions().filter(function (q) { return q.materialId === 4 || q.status === 'pending'; }).slice(0, 6);
  var shown = [];

  function now() {
    var d = new Date();
    return [d.getHours(), d.getMinutes(), d.getSeconds()].map(function (n) { return (n < 10 ? '0' : '') + n; }).join(':');
  }

  function agentState(id) {
    if (id === 'analyst') return s.tick > 3 ? ['done', 'Готово', '7 тем · 23 понятия · 12 примеров схем'] : ['work', 'Работает', 'Читает материалы…'];
    if (id === 'writer') {
      if (s.tick <= 3) return ['wait', 'Ожидает', 'Начнёт после аналитика'];
      return s.written >= TOTAL ? ['done', 'Готово', TOTAL + ' вопросов, 6 типов'] : ['work', 'Работает', s.written + ' из ' + TOTAL + ' · тема «Функциональные зависимости»'];
    }
    if (id === 'critic') {
      if (s.written < 3) return ['wait', 'Ожидает', 'Начнёт после первых вопросов'];
      var t = s.checked + ' проверено · ' + s.rejected + ' отклонено · ' + s.disputed + ' спорных';
      return s.checked >= TOTAL ? ['done', 'Готово', t] : ['work', 'Работает', t];
    }
    if (s.checked < TOTAL) return ['wait', 'Ожидает', 'Начнёт после критика'];
    return s.catalog >= 100 ? ['done', 'Готово', 'Теги проставлены · 1 похожий вопрос'] : ['work', 'Работает', 'Сверяет с банком… ' + s.catalog + '%'];
  }

  function render() {
    var tones = { done: 'green', work: 'blue', wait: 'gray' };
    A.$('#agents').innerHTML = agents.map(function (a) {
      var st = agentState(a.id);
      return '<div class="card agent ' + (st[0] === 'done' ? 'is-done' : st[0] === 'work' ? 'is-work' : '') + (a.id === 'writer' && st[0] === 'work' ? ' is-focus' : '') + '">' +
        '<div class="between"><span class="mono muted xsmall">' + a.no + '</span>' + A.chip(st[1], tones[st[0]]) + '</div>' +
        '<div class="agent__name">' + a.name + '</div><div class="agent__desc">' + a.role + '</div>' +
        '<div class="agent__status' + (st[0] === 'wait' ? ' muted' : '') + '">' + esc(st[2]) + '</div></div>';
    }).join('');

    var done = Math.min(TOTAL, s.checked);
    A.$('#total-label').textContent = done + ' / ' + TOTAL;
    A.$('#total-bar').style.width = Math.round(done / TOTAL * 100) + '%';

    A.$('#fresh').innerHTML = shown.slice().reverse().map(function (q) {
      var warn = q.status === 'pending';
      return '<div class="gen-q' + (warn ? ' gen-q--warn' : '') + '">' +
        '<div class="row-wrap">' + A.chip(M.types[q.type]) + A.chip(M.difficulties[q.difficulty]) +
          (warn ? A.chip('Нужна проверка', 'amber') : A.chip('Одобрен критиком', 'green')) + '</div>' +
        '<div class="gen-q__text">' + esc(q.text) + '</div>' +
        '<div class="xsmall ' + (warn ? 't-amber' : 'muted') + '" style="margin-top: 6px">' +
          (warn && q.flag ? 'Критик: ' + esc(q.flag.text) : 'Источник: ' + esc(q.source)) + '</div></div>';
    }).join('') || '<div class="muted small">Составитель ещё не начал</div>';
  }

  function log(entry) {
    var el = A.$('#log');
    el.insertAdjacentHTML('beforeend', '<div><span class="log__time">' + now() + '</span> <span class="a-' + entry[0] + '">' + entry[1] + '</span> ' + esc(entry[2]) + '</div>');
    el.scrollTop = el.scrollHeight;
  }

  function step() {
    if (s.stopped) return;
    s.tick++;
    if (s.tick > 3 && s.written < TOTAL) s.written = Math.min(TOTAL, s.written + 3);
    if (s.written >= 3 && s.checked < s.written) {
      s.checked = Math.min(s.written, s.checked + 2);
      if (s.tick % 4 === 0) s.rejected++;
      if (s.tick % 5 === 0) s.disputed++;
    }
    if (s.checked >= TOTAL && s.catalog < 100) s.catalog = Math.min(100, s.catalog + 20);
    if (s.tick % 3 === 0 && s.log < M.pipelineLog.length) log(M.pipelineLog[s.log++]);
    if (s.tick % 6 === 4 && shown.length < fresh.length) shown.push(fresh[shown.length]);

    var left = Math.max(0, Math.ceil((TOTAL - s.checked) / 6));
    A.$('#eta').textContent = s.catalog >= 100 ? 'Готово. Спорные вопросы ждут вас в банке.' : 'Осталось около ' + left + ' сек. Готовые вопросы появляются ниже сразу.';
    if (s.catalog >= 100) {
      A.$('#title').textContent = 'Агенты закончили работу';
      A.$('#live').textContent = 'готово';
      A.$('#stop').disabled = true;
      while (s.log < M.pipelineLog.length) log(M.pipelineLog[s.log++]);
      clearInterval(timer);
    }
    render();
  }

  A.$('#stop').addEventListener('click', function () {
    s.stopped = !s.stopped;
    this.textContent = s.stopped ? 'Продолжить' : 'Остановить';
    A.$('#live').textContent = s.stopped ? 'пауза' : 'live';
  });

  var timer = setInterval(step, 500);
  render();
})();
