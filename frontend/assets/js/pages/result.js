/* Экран 12. Результат и разбор. Берёт последнюю попытку из хранилища,
   а если её нет — показывает демо-попытку (ответы как в прототипе). */
(function () {
  var A = window.App, M = A.M, G = window.Grading, esc = A.esc;
  var Q = M.attempt.questions;
  var LETTERS = 'АБВГДЕ';

  var att = A.store.get('attempt', null);
  if (!att || !att.submitted) {
    att = { minutes: 38, answers: {
      'Q-012': [1], 'Q-005': [1], 'Q-063': [1], 'Q-071': [0, 1],
      'Q-027': 'SELECT g.name, COUNT(s.id) AS students\nFROM groups g\nLEFT JOIN students s ON s.group_id = g.id\nGROUP BY g.name\nORDER BY students DESC;',
      'Q-031': [0],
      'Q-044': 'Нормализация нужна, чтобы в таблицах не было повторяющихся данных. Если хранить студентов и группы в одной таблице, название группы повторяется много раз, и при переименовании его надо менять везде. Аномалия удаления — это когда удаляешь строку и данные пропадают.',
      'Q-052': [2]
    } };
    att.results = {}; att.score = 0; att.max = 0;
    Q.forEach(function (q) { var r = G.grade(q, att.answers[q.id]); att.results[q.id] = r; att.score += r.score; att.max += r.max; });
    att.percent = Math.round(att.score / att.max * 100);
  }

  var test = M.tests.filter(function (t) { return t.id === M.attempt.testId; })[0];
  var pendingOpen = Q.filter(function (q) { return !att.results[q.id].final; }).length;

  A.$('#test-title').textContent = test.title;
  A.$('#percent').textContent = att.percent + '%';
  A.$('#score').textContent = att.score + ' / ' + att.max;
  A.$('#donut').style.background = 'conic-gradient(#0072BC 0 ' + att.percent + '%, #EEF0F3 ' + att.percent + '% 100%)';
  A.$('#chips').innerHTML =
    (pendingOpen ? A.chip('Предварительно: ' + pendingOpen + ' ' + A.plural(pendingOpen, 'открытый ответ ждёт', 'открытых ответа ждут', 'открытых ответов ждут') + ' преподавателя', 'amber') : '') +
    A.chip('Время ' + att.minutes + ' мин');

  // По темам
  var topics = {};
  Q.forEach(function (q) {
    var r = att.results[q.id];
    topics[q.topic] = topics[q.topic] || [0, 0];
    topics[q.topic][0] += r.score; topics[q.topic][1] += r.max;
  });
  var tlist = Object.keys(topics).map(function (k) { return [k, Math.round(topics[k][0] / topics[k][1] * 100)]; });
  A.$('#topics').innerHTML = tlist.map(function (t) {
    var weak = t[1] < 60;
    return '<div class="topic-row"><span>' + esc(t[0]) + '</span><div class="progress progress--md"><div class="' + (weak ? 'is-weak' : '') + '" style="width:' + t[1] + '%"></div></div>' +
      '<b' + (weak ? ' class="t-orange"' : '') + '>' + t[1] + '%</b></div>';
  }).join('');

  var weakest = tlist.slice().sort(function (a, b) { return a[1] - b[1]; })[0];
  var wrong = Q.filter(function (q) { var r = att.results[q.id]; return r.score < r.max; });
  var weakQ = wrong.filter(function (q) { return q.topic === weakest[0]; })[0];
  A.$('#advice').textContent = weakest[1] < 100
    ? 'Больше всего ошибок в теме «' + weakest[0] + '» (' + weakest[1] + '%). Перечитайте ' + (weakQ ? weakQ.ref.replace(/^Лекция/, 'лекцию').replace(/^Лаб\. работа/, 'лабораторную работу').replace(/^Семинар/, 'семинар') : 'материалы по теме') + ' и пройдите короткую тренировку.'
    : 'Ошибок нет — отличный результат. Можно пройти тренировку по следующей теме.';
  A.$('#train-btn').textContent = 'Тренировка по ошибкам · ' + wrong.length + ' ' + A.plural(wrong.length, 'вопрос', 'вопроса', 'вопросов');

  // Разбор
  var filter = 'all';
  function renderFilters() {
    var open = Q.filter(function (q) { return q.type === 'open' || q.type === 'sql'; }).length;
    var opts = [['all', 'Все'], ['wrong', 'Ошибки · ' + wrong.length], ['open', 'Открытые · ' + open]];
    A.$('#filters').innerHTML = opts.map(function (o) {
      return '<button type="button" class="btn btn--xs' + (filter === o[0] ? ' is-on' : '') + '" aria-pressed="' + (filter === o[0]) + '" data-f="' + o[0] + '">' + o[1] + '</button>';
    }).join('');
  }

  function scoreChip(r) {
    var tone = r.score === r.max ? 'green' : r.score === 0 ? 'red' : 'amber';
    if (!r.final) tone = 'amber';
    return A.chip(r.score + ' / ' + r.max + (r.final ? '' : ' · предварительно'), tone);
  }

  function renderReview() {
    var items = Q.map(function (q, i) { return [q, i]; }).filter(function (x) {
      var q = x[0], r = att.results[q.id];
      if (filter === 'wrong') return r.score < r.max;
      if (filter === 'open') return q.type === 'open' || q.type === 'sql';
      return true;
    });
    A.$('#review').innerHTML = items.map(function (x) {
      var q = x[0], i = x[1], r = att.results[q.id], a = att.answers[q.id];
      var head = '<div class="between"><span class="lbl">Вопрос ' + (i + 1) + ' · ' + M.types[q.type].toLowerCase() + '</span>' + scoreChip(r) + '</div>' +
        '<p style="margin: 10px 0 12px; font-size: 17px; font-weight: 500">' + esc(q.text) + '</p>';
      var body;
      if (q.options) {
        var yours = (a || []).map(function (k) { return LETTERS[k] + ') ' + q.options[k]; }).join('; ') || 'нет ответа';
        var right = q.correct.map(function (k) { return LETTERS[k] + ') ' + q.options[k]; }).join('; ');
        body = r.score === r.max
          ? '<div class="ans ans--good">' + A.icons.check + '<span><b class="strong">Ваш ответ:</b> ' + esc(yours) + '</span></div>'
          : '<div class="stack-sm" style="gap: 8px"><div class="ans ans--bad">' + A.icons.cross + '<span><b class="strong">Ваш ответ:</b> ' + esc(yours) + '</span></div>' +
            '<div class="ans ans--good">' + A.icons.check + '<span><b class="strong">Верно:</b> ' + esc(right) + '</span></div></div>';
        body += '<p style="margin: 12px 0 0; color: var(--text-2); font-size: 15px; line-height: 1.55">' + esc(q.explanation) + ' <a href="tests.html">' + esc(q.ref) + '</a></p>';
      } else {
        body = '<div class="ans ans--neutral' + (q.type === 'sql' ? ' mono' : '') + '" style="white-space: pre-wrap; font-size: 14px">' + esc(a || 'Нет ответа') + '</div>' +
          '<div class="stack-sm" style="margin-top: 12px; font-size: 15px">' + r.criteria.map(function (c) {
            var tone = c[0] === c[1] ? 'green' : c[0] === 0 ? 'red' : 'amber';
            return '<div style="display: flex; gap: 12px; align-items: flex-start">' + A.chip(c[0] + ' / ' + c[1], tone, '') + '<span>' + esc(c[2]) + '</span></div>';
          }).join('') + '</div>' +
          '<div class="row-wrap" style="margin-top: 14px; gap: 10px"><button type="button" class="btn btn--sm" data-ref="' + q.id + '">Показать эталонный ответ</button>' +
          '<button type="button" class="btn btn--sm" data-appeal="' + q.id + '">Оспорить оценку</button></div>' +
          '<div class="note" style="margin-top: 12px; white-space: pre-wrap" id="ref-' + q.id + '" hidden>' + esc(refFor(q)) + '</div>';
      }
      return '<article class="review-item">' + head + body + '</article>';
    }).join('') || '<div class="empty">Здесь пусто — ошибок нет.</div>';
  }

  function refFor(q) {
    if (q.type === 'sql') return 'SELECT g.name, COUNT(s.id) AS students\nFROM groups g\nLEFT JOIN students s ON s.group_id = g.id\nGROUP BY g.name\nORDER BY students DESC;';
    return (M.questionTexts[q.id] || {}).reference || '';
  }

  A.$('#filters').addEventListener('click', function (e) {
    var b = e.target.closest('[data-f]');
    if (b) { filter = b.getAttribute('data-f'); renderFilters(); renderReview(); }
  });
  A.$('#review').addEventListener('click', function (e) {
    var r = e.target.closest('[data-ref]');
    if (r) {
      var box = A.$('#ref-' + r.getAttribute('data-ref'));
      box.hidden = !box.hidden;
      r.textContent = box.hidden ? 'Показать эталонный ответ' : 'Скрыть эталонный ответ';
    }
    if (e.target.closest('[data-appeal]')) A.toast('Заявка отправлена преподавателю. Ответ придёт в уведомлениях');
  });

  renderFilters(); renderReview();
})();
