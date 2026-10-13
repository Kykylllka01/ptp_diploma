/* Экран 3. Работа агентов. Состояние конвейера приходит с сервера:
   сначала в APP_DATA.run, затем опросом GET /api/generation-runs/{id}, пока запуск не закончится.
   (Позже опрос можно заменить на Laravel Echo / WebSocket.) */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var run = M.run;

  var agents = [
    { id: 'analyst', no: '01', name: 'Аналитик', role: 'Разбор материалов' },
    { id: 'writer', no: '02', name: 'Составитель', role: 'Генерация вопросов' },
    { id: 'critic', no: '03', name: 'Критик', role: 'Контроль качества' },
    { id: 'catalog', no: '04', name: 'Каталогизатор', role: 'Теги, сложность, дубли с банком' }
  ];
  var shownLog = 0;
  var timer;

  if (!run) {
    A.$('#title').textContent = 'Агенты ещё не запускались';
    A.$('#eta').innerHTML = 'Загрузите материалы и запустите генерацию на странице <a href="upload">«Материалы»</a>.';
    A.$('#stop').hidden = true;
    A.$('#live').textContent = '—';
    A.$('#fresh').innerHTML = '<div class="muted small">Пока пусто</div>';
    return;
  }

  function render() {
    // warn — шаг завершён, но часть работы не удалась (подробности в журнале); error — запуск упал
    var tones = { done: 'green', work: 'blue', wait: 'gray', warn: 'amber', error: 'red' };
    // Карточки строятся один раз, дальше меняются на месте: опрос раз в 2 секунды не перезапускает анимацию рамки
    var box = A.$('#agents');
    if (box.children.length !== agents.length) {
      box.innerHTML = agents.map(function (a) {
        return '<div class="card agent"><div class="between"><span class="mono muted xsmall">' + a.no + '</span><span data-chip></span></div>' +
          '<div class="agent__name">' + a.name + '</div><div class="agent__desc">' + a.role + '</div><div class="agent__status"></div></div>';
      }).join('');
    }
    agents.forEach(function (a, i) {
      var st = run.states[a.id] || ['wait', 'Ожидает', ''], card = box.children[i];
      card.className = 'card agent' + (st[0] === 'done' ? ' is-done' : st[0] === 'work' ? ' is-work' : '') + (a.id === 'writer' && st[0] === 'work' ? ' is-focus' : '');
      card.querySelector('[data-chip]').innerHTML = A.chip(st[1], tones[st[0]]);
      var status = card.querySelector('.agent__status');
      status.className = 'agent__status' + (st[0] === 'wait' ? ' muted' : '');
      status.textContent = st[2];
    });

    var total = run.total || 0, done = Math.min(total, run.checked);
    A.$('#total-label').textContent = done + ' / ' + total;
    A.$('#total-bar').style.width = (total ? Math.round(done / total * 100) : 0) + '%';
    // Пока агенты работают, полоса «бежит»
    A.$('#total-bar').parentNode.classList.toggle('is-live', !/^(done|failed|cancelled)$/.test(run.status));

    A.$('#fresh').innerHTML = run.fresh.slice().reverse().map(function (q) {
      var warn = !!q.flag;
      return '<div class="gen-q' + (warn ? ' gen-q--warn' : '') + '">' +
        '<div class="row-wrap">' + A.chip(M.types[q.type]) + A.chip(M.difficulties[q.difficulty]) +
          (warn ? A.chip('Нужна проверка', 'amber') : A.chip('Без замечаний Критика', 'green')) + '</div>' +
        '<a class="gen-q__text" href="question?id=' + encodeURIComponent(q.id) + '">' + esc(q.text) + '</a>' +
        '<div class="xsmall ' + (warn ? 't-amber' : 'muted') + '" style="margin-top: 6px">' +
          (warn ? 'Критик: ' + esc(q.flag.text) : 'Источник: ' + esc(q.source)) + '</div></div>';
    }).join('') || '<div class="muted small">Составитель ещё не начал</div>';

    var el = A.$('#log');
    run.log.slice(shownLog).forEach(function (entry) {
      el.insertAdjacentHTML('beforeend', '<div><span class="log__time">' + esc(entry[3] || '') + '</span> <span class="a-' + entry[0] + '">' + esc(entry[1]) + '</span> ' + esc(entry[2]) + '</div>');
    });
    shownLog = run.log.length;
    el.scrollTop = el.scrollHeight;

    A.$('#run-label').textContent = 'Запуск №' + run.id + ' · ' + run.total + ' ' + A.plural(run.total, 'вопрос', 'вопроса', 'вопросов');
    var finished = run.status === 'done' || run.status === 'failed' || run.status === 'cancelled';
    if (run.status === 'cancelled') {
      A.$('#title').textContent = 'Запуск остановлен';
      A.$('#eta').textContent = run.written ? 'Готовые вопросы (' + (run.written - run.rejected) + ') сохранены в банке.' : 'Вопросы не успели появиться.';
    } else if (run.status === 'cancelling') {
      A.$('#title').textContent = 'Останавливаем агентов';
      A.$('#eta').textContent = 'Агенты закончат текущий шаг и остановятся.';
    } else if (run.status === 'done') {
      A.$('#title').textContent = 'Агенты закончили работу';
      A.$('#eta').textContent = run.disputed
        ? 'Готово. Спорные вопросы (' + run.disputed + ') ждут вас в банке.'
        : 'Готово. Новые вопросы ждут проверки в банке.';
    } else if (run.status === 'failed') {
      A.$('#title').textContent = 'Конвейер остановился с ошибкой';
      A.$('#eta').textContent = run.error || 'Агенты остановились с ошибкой. Попробуйте запустить ещё раз; если повторится — сообщите администратору сайта.';
    } else {
      A.$('#title').textContent = 'Агенты работают над вопросами';
      A.$('#eta').textContent = 'Готовые вопросы появляются ниже сразу.';
    }
    A.$('#live').textContent = finished ? 'готово' : 'обновляется';
    A.$('#stop').hidden = finished;
    A.$('#stop').disabled = run.status === 'cancelling';
    if (finished) clearInterval(timer);
  }

  function poll() {
    A.api('GET', 'generation-runs/' + run.id).then(function (fresh) { run = fresh; render(); });
  }

  A.$('#stop').addEventListener('click', function () {
    A.confirm({ title: 'Остановить агентов?', text: 'Уже составленные вопросы останутся в банке. Остальные агенты делать не будут.', ok: 'Остановить', danger: true }).then(function (yes) {
      if (yes) A.api('POST', 'generation-runs/' + run.id + '/cancel').then(function (fresh) { run = fresh; render(); });
    });
  });

  render();
  if (['done', 'failed', 'cancelled'].indexOf(run.status) < 0) timer = setInterval(poll, 2000);
})();
