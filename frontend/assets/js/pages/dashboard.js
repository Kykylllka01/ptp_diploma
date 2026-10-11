/* Экран 1. Обзор преподавателя */
(function () {
  var A = window.App, M = A.M, esc = A.esc;

  var qs = A.questions();
  var pending = qs.filter(function (q) { return q.status === 'pending'; });
  var openToReview = A.reviews().filter(function (r) { return r.status === 'pending'; }).length;
  var running = M.tests.filter(function (t) { return t.status !== 'draft'; }).length;

  A.$('#btn-upload').insertAdjacentHTML('afterbegin', A.icons.upload);
  A.$('#btn-build').insertAdjacentHTML('afterbegin', A.icons.plus);
  A.$('#teacher-name').textContent = M.teacher.firstName;
  A.$('#greeting-sub').textContent = pending.length
    ? 'Агенты закончили разбор лекции 4 «Нормализация отношений» — ' + pending.length + ' ' +
      A.plural(pending.length, 'новый вопрос ждёт', 'новых вопроса ждут', 'новых вопросов ждут') + ' вашей проверки.'
    : 'Все новые вопросы проверены. Можно собирать тест.';

  A.$('#stats').innerHTML =
    '<div class="card stat"><div class="lbl">Вопросов в банке</div><div class="stat__value">' + qs.length + '</div><div class="stat__hint">по ' + M.materials.length + ' материалам</div></div>' +
    '<a href="bank.html" class="card stat' + (pending.length ? ' card--warn' : '') + '"><div class="lbl">Ждут проверки</div><div class="stat__value">' + pending.length + '</div><div class="stat__hint">Открыть очередь →</div></a>' +
    '<div class="card stat"><div class="lbl">Активных тестов</div><div class="stat__value">' + running + '</div><div class="stat__hint">64 участника</div></div>' +
    '<a href="review.html" class="card stat"><div class="lbl">Открытые ответы</div><div class="stat__value">' + openToReview + '</div><div class="stat__hint">агент оценил, нужна ваша подпись</div></a>';

  A.$('#materials').innerHTML = M.materials.map(function (m) {
    var mine = qs.filter(function (q) { return q.materialId === m.id; });
    var p = mine.filter(function (q) { return q.status === 'pending'; }).length;
    var status = p ? A.chip(p + ' на проверке', 'amber') : A.chip('Готово', 'green');
    return '<div class="list-row">' +
      '<span class="filetype">' + m.kind + '</span>' +
      '<div class="list-row__main"><div class="strong">' + esc(m.title) + '</div><div class="list-row__meta">' + esc(m.meta) + ' · загружено ' + esc(m.date) + '</div></div>' +
      status +
      '<span class="muted small" style="width: 90px; text-align: right">' + mine.length + ' ' + A.plural(mine.length, 'вопрос', 'вопроса', 'вопросов') + '</span>' +
    '</div>';
  }).join('');

  var dot = { done: 'green', warn: 'amber', work: 'blue' };
  A.$('#agents').innerHTML = M.agents.map(function (a) {
    return '<div style="display: flex; gap: 12px; align-items: flex-start"><span class="dot dot--' + dot[a.state] + '"></span>' +
      '<div><div class="strong">' + esc(a.name) + '</div><div class="muted small">' + esc(a.summary) + '</div></div></div>';
  }).join('');

  var tone = { running: 'blue', training: 'blue', draft: 'gray' };
  A.$('#tests').innerHTML = M.tests.map(function (t) {
    var link = t.status === 'draft'
      ? '<a href="test-builder.html">Открыть</a>'
      : '<a href="analytics.html">Результаты</a>';
    return '<tr><td class="strong">' + esc(t.title) + '</td><td>' + t.count + '</td><td>' + A.chip(t.statusLabel, tone[t.status]) +
      '</td><td>' + t.passed + '</td><td>' + t.avg + '</td><td class="right">' + link + '</td></tr>';
  }).join('');
})();
