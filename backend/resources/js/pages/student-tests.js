/* Экран 9. Студент — мои тесты. Состояние попытки (t.my) приходит с сервера. */
(function () {
  var A = window.App, M = A.M, esc = A.esc;

  var todo = M.tests.filter(function (t) {
    var done = t.my && t.my.status !== 'in_progress' && t.my.left <= 0;
    return (t.status === 'running' || t.status === 'draft') && !done;
  });
  // Срок: красный — меньше двух суток, жёлтый — меньше пяти, дальше — спокойный
  function deadlineChip(t) {
    if (!t.deadline) return A.chip('Без срока');
    var days = t.deadlineTs ? (t.deadlineTs * 1000 - Date.now()) / 864e5 : 99;
    return A.chip(t.deadline, days < 2 ? 'red' : days < 5 ? 'amber' : 'blue');
  }
  A.$('#todo').innerHTML = todo.map(function (t) {
    var open = t.isOpen;
    var my = t.my;
    var submitted = my && my.status !== 'in_progress';
    // 0 минут — без ограничения времени, 99 попыток — без ограничения (так их сохраняет сборщик теста)
    var meta = [
      t.count + ' ' + A.plural(t.count, 'вопрос', 'вопроса', 'вопросов'),
      t.minutes > 0 ? t.minutes + ' ' + A.plural(t.minutes, 'минута', 'минуты', 'минут') : 'без ограничения времени',
      t.attempts >= 99 ? 'попыток без ограничения' : t.attempts + ' ' + A.plural(t.attempts, 'попытка', 'попытки', 'попыток')
    ];
    if (t.open) meta.push(t.open + ' ' + A.plural(t.open, 'вопрос', 'вопроса', 'вопросов') + ' с развёрнутым ответом');
    // Тест с жёстким прокторингом: перед стартом — предупреждение (ссылки ловит обработчик ниже)
    var pr = t.proctoring ? ' data-proctor="' + esc(t.id) + '"' : '';
    var action;
    if (!t.count) action = '<button class="btn" disabled style="align-self: flex-start">Вопросы ещё не добавлены</button>';
    else if (!open) action = '<button class="btn" disabled style="align-self: flex-start">Пока недоступен</button>';
    else if (submitted && my.left > 0) action = '<div class="row-wrap" style="gap: 8px">' +
      '<a href="test?test=' + encodeURIComponent(t.id) + '" class="btn btn--primary"' + pr + '>Пересдать · ' + my.left + ' ' + A.plural(my.left, 'попытка', 'попытки', 'попыток') + '</a>' +
      '<a href="result?attempt=' + my.attemptId + '" class="btn">Смотреть результат</a></div>';
    else if (submitted) action = '<a href="result?attempt=' + my.attemptId + '" class="btn" style="align-self: flex-start">Смотреть результат</a>';
    else action = '<a href="test?test=' + encodeURIComponent(t.id) + '" class="btn btn--primary" style="align-self: flex-start"' + pr + '>' + (my ? 'Продолжить тест' : 'Начать тест') + '</a>';
    return '<article class="card test-card' + (open && !submitted ? ' is-main' : '') + '">' +
      // Срока может не быть — тогда без пустой красной плашки
      '<div class="between">' + (open ? (submitted ? A.chip('Сдан · можно пересдать', 'green') : deadlineChip(t)) +
        A.chip(t.graded ? 'Оценивается' : 'Без оценки') : A.chip(t.deadline || 'Ещё не открыт')) + '</div>' +
      (t.proctoring ? '<div>' + A.chip('Жёсткий прокторинг', 'red') + '</div>' : '') +
      '<h3>' + esc(t.title) + '</h3>' +
      '<div class="meta">' + meta.map(function (m) { return '<span>' + m + '</span>'; }).join('') + '</div>' +
      (t.about ? '<p style="margin: 0; font-size: 14px; color: var(--text-2); line-height: 1.5">' + esc(t.about) + '</p>' : '') +
      action + '</article>';
  }).join('') || '<div class="card card--pad empty" style="grid-column: 1 / -1">Сейчас проходить нечего: новых тестов нет. Когда преподаватель откроет тест, он появится здесь.</div>';

  // Предупреждение до начала попытки: время пойдёт, как только откроется тест
  A.$('#todo').addEventListener('click', function (e) {
    var link = e.target.closest('[data-proctor]');
    if (!link || e.ctrlKey || e.metaKey || e.shiftKey) return;
    e.preventDefault();
    A.confirm({
      title: 'В тесте жёсткий прокторинг',
      text: 'Во время теста нельзя переключаться на другие вкладки, сворачивать браузер, переходить в другие окна и программы, закрывать или перезагружать страницу. ' +
        'Если уйти со страницы, вопросы и варианты ответа скроются, а уход запишется — преподаватель увидит, когда и на сколько вы уходили.',
      ok: 'Понятно, начать', cancel: 'Не сейчас'
    }).then(function (yes) {
      if (!yes) return;
      try { sessionStorage.setItem('proctor-ok-' + link.getAttribute('data-proctor'), '1'); } catch (err) { /* правила покажет сам тест */ }
      location.href = link.href;
    });
  });

  A.$('#done').innerHTML = M.studentDone.map(function (d) {
    return '<a href="result?attempt=' + d.attemptId + '" class="done-row"><div style="flex: 1; min-width: 220px"><div class="strong">' + esc(d.title) + '</div>' +
      '<div class="muted small" style="margin-top: 2px">' + esc(d.meta) + '</div></div>' + A.chip(d.status[0], d.status[1]) +
      '<span class="done-row__score">' + esc(d.score) + '</span></a>';
  }).join('') || '<div class="empty">Пока ничего не сдано</div>';

  // Тренировка: блок виден, только если преподаватель создал тренировочный тест
  var trainings = M.tests.filter(function (t) { return t.status === 'training'; });
  function pickTraining(i) {
    A.$$('#train-topics button').forEach(function (x, k) { x.classList.toggle('is-on', k === i); x.setAttribute('aria-pressed', k === i); });
    A.$('#train-start').href = 'test?mode=training&test=' + encodeURIComponent(trainings[i].id);
  }
  if (trainings.length) {
    A.$('#training').hidden = false;
    A.$('#train-topics').innerHTML = trainings.map(function (t) {
      return '<button type="button" class="chip chip--dark" aria-pressed="false">' + esc(t.title) + '</button>';
    }).join('');
    pickTraining(0);
    A.$('#train-topics').addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (b) pickTraining(A.$$('#train-topics button').indexOf(b));
    });
  }
})();
