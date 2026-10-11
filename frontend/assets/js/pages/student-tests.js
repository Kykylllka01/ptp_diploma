/* Экран 9. Студент — мои тесты */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var attempt = A.store.get('attempt', null);
  var submitted = attempt && attempt.submitted;

  var todo = M.tests.filter(function (t) { return t.id !== 'lab2'; });
  A.$('#todo').innerHTML = todo.map(function (t) {
    var open = t.status === 'running';
    var meta = [t.count + ' ' + A.plural(t.count, 'вопрос', 'вопроса', 'вопросов'), t.minutes + ' минут', t.attempts + ' попытка'];
    if (t.open) meta.push(t.open + ' открытых');
    var action;
    if (!open) action = '<button class="btn" disabled style="align-self: flex-start">Пока недоступен</button>';
    else if (submitted) action = '<a href="result.html" class="btn" style="align-self: flex-start">Смотреть результат</a>';
    else action = '<a href="test.html" class="btn btn--primary" style="align-self: flex-start">' + (attempt ? 'Продолжить тест' : 'Начать тест') + '</a>';
    return '<article class="card test-card' + (open && !submitted ? ' is-main' : '') + '">' +
      '<div class="between">' + (open ? A.chip(submitted ? 'Сдан' : t.deadline, submitted ? 'green' : 'red') + A.chip('Оценивается') : A.chip(t.deadline)) + '</div>' +
      '<h3>' + esc(t.title) + '</h3>' +
      '<div class="meta">' + meta.map(function (m) { return '<span>' + m + '</span>'; }).join('') + '</div>' +
      '<p style="margin: 0; font-size: 14px; color: var(--text-2); line-height: 1.5">' + esc(t.about) + '</p>' +
      action + '</article>';
  }).join('');

  var done = M.studentDone.slice();
  if (submitted) done.unshift({ title: M.tests[0].title, meta: 'Сдан только что', status: ['Предварительно', 'amber'], score: attempt.percent + '%' });
  A.$('#done').innerHTML = done.map(function (d) {
    return '<a href="result.html" class="done-row"><div style="flex: 1; min-width: 220px"><div class="strong">' + esc(d.title) + '</div>' +
      '<div class="muted small" style="margin-top: 2px">' + esc(d.meta) + '</div></div>' + A.chip(d.status[0], d.status[1]) +
      '<span class="done-row__score">' + d.score + '</span></a>';
  }).join('');

  A.$('#train-topics').addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) return;
    A.$$('#train-topics button').forEach(function (x) { x.classList.toggle('is-on', x === b); x.setAttribute('aria-pressed', x === b); });
  });
})();
