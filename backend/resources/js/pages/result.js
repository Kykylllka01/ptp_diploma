/* Экран 12. Результат и разбор. Попытка и оценки приходят с сервера (APP_DATA.result). */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var LETTERS = 'АБВГДЕ';

  var att = M.result;
  if (!att) {
    A.$('main').innerHTML = '<section class="card card--pad empty">Вы ещё не сдали ни одного теста. <a href="tests">Перейти к тестам</a></section>';
    return;
  }
  var Q = M.attempt.questions;
  var pendingOpen = Q.filter(function (q) { return !att.results[q.id].final; }).length;

  A.$('#test-title').textContent = att.testTitle;
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
  var weakQ = weakest && wrong.filter(function (q) { return q.topic === weakest[0]; })[0];
  A.$('#advice').textContent = !weakest ? 'Подробного разбора по этому тесту нет.' : weakest[1] < 100
    ? 'Больше всего ошибок в теме «' + weakest[0] + '» (' + weakest[1] + '%). Перечитайте ' + (weakQ && weakQ.ref ? (/^(Лекция|Лаб\. работа|Семинар)/.test(weakQ.ref) ? weakQ.ref.replace(/^Лекция/, 'лекцию').replace(/^Лаб\. работа/, 'лабораторную работу').replace(/^Семинар/, 'семинар') : 'материал «' + weakQ.ref + '»') : 'материалы по теме') + ' и пройдите короткую тренировку.'
    : 'Ошибок нет — отличный результат. Можно пройти тренировку по следующей теме.';
  // Работа над ошибками: вопросы с выбором ответа, сопоставление и SQL (развёрнутые проверяет преподаватель)
  var practice = wrong.filter(function (q) { return q.type !== 'open'; });
  A.$('#train-btn').hidden = !practice.length || !att.showReview;
  A.$('#train-btn').href = 'test?mode=training&attempt=' + att.attemptId;
  A.$('#train-btn').textContent = 'Работа над ошибками · ' + practice.length + ' ' + A.plural(practice.length, 'вопрос', 'вопроса', 'вопросов');

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
        A.context(q.context) +
        '<p style="margin: 10px 0 12px; font-size: 17px; font-weight: 500">' + esc(q.text) + '</p>';
      var body;
      if (q.type === 'match') {
        var given = a || [];
        body = '<div class="stack-sm" style="gap: 8px">' + q.terms.map(function (t, k) {
          var ok = given[k] === q.correct[k], mine = given[k] >= 0 ? q.definitions[given[k]] : 'нет ответа';
          return '<div class="ans ' + (ok ? 'ans--good' : 'ans--bad') + '">' + (ok ? A.icons.check : A.icons.cross) + '<span><b class="strong">' + esc(t) + ':</b> ' + esc(mine) +
            (ok ? '' : '<br><span class="muted">Верно: ' + esc(q.definitions[q.correct[k]]) + '</span>') + '</span></div>';
        }).join('') + '</div>';
        body += '<p style="margin: 12px 0 0; color: var(--text-2); font-size: 15px; line-height: 1.55">' + esc(q.explanation || '') + ' <span class="muted">' + esc(q.ref || '') + '</span></p>';
      } else if (q.options) {
        var yours = (a || []).map(function (k) { return q.options[k]; }).join('; ') || 'нет ответа';
        var right = q.correct.map(function (k) { return q.options[k]; }).join('; ');
        body = r.score === r.max
          ? '<div class="ans ans--good">' + A.icons.check + '<span><b class="strong">Ваш ответ:</b> ' + esc(yours) + '</span></div>'
          : '<div class="stack-sm" style="gap: 8px"><div class="ans ans--bad">' + A.icons.cross + '<span><b class="strong">Ваш ответ:</b> ' + esc(yours) + '</span></div>' +
            '<div class="ans ans--good">' + A.icons.check + '<span><b class="strong">Верно:</b> ' + esc(right) + '</span></div></div>';
        body += '<p style="margin: 12px 0 0; color: var(--text-2); font-size: 15px; line-height: 1.55">' + esc(q.explanation || '') + ' <span class="muted">' + esc(q.ref || '') + '</span></p>';
      } else {
        body = '<div class="ans ans--neutral' + (q.type === 'sql' ? ' mono' : '') + '" style="white-space: pre-wrap; font-size: 14px">' + esc(a || 'Нет ответа') + '</div>' +
          '<div class="stack-sm" style="margin-top: 12px; font-size: 15px">' + (r.criteria || []).map(function (c) {
            var tone = c[0] === c[1] ? 'green' : c[0] === 0 ? 'red' : 'amber';
            return '<div style="display: flex; gap: 12px; align-items: flex-start">' + A.chip(c[0] + ' / ' + c[1], tone, '') + '<span>' + esc(c[2]) + '</span></div>';
          }).join('') + '</div>' +
          '<div class="row-wrap" style="margin-top: 14px; gap: 10px"><button type="button" class="btn btn--sm" data-ref="' + q.id + '">Показать эталонный ответ</button>' +
          (r.canAppeal ? (r.appeal ? '<span class="chip chip--amber">Апелляция отправлена</span>' : '<button type="button" class="btn btn--sm" data-appeal="' + q.id + '">Оспорить оценку</button>') : '') + '</div>' +
          (r.appeal ? '<div class="note" style="margin-top: 12px"><b class="strong">Ваша апелляция:</b> ' + esc(r.appeal) + '</div>' : '') +
          '<div class="note" style="margin-top: 12px; white-space: pre-wrap" id="ref-' + q.id + '" hidden>' + esc(refFor(q)) + '</div>';
      }
      return '<article class="review-item">' + head + body + '</article>';
    }).join('') || '<div class="empty">Здесь пусто — ошибок нет.</div>';
  }

  function refFor(q) { return q.reference || 'Эталон не задан'; }

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
    var ap = e.target.closest('[data-appeal]');
    if (ap) {
      var res = att.results[ap.getAttribute('data-appeal')];
      var text = prompt('Почему вы не согласны с оценкой? Преподаватель перепроверит ответ.');
      if (!text || text.trim().length < 5) return;
      A.api('POST', 'answers/' + res.answerId + '/appeal', { text: text.trim() }).then(function () {
        res.appeal = text.trim();
        A.toast('Апелляция отправлена преподавателю');
        renderReview();
      });
    }
  });

  if (att.showReview) { renderFilters(); renderReview(); }
  else A.$('#review-card').innerHTML = '<h2 class="card-title" style="font-size: 19px">Разбор ответов</h2><p class="muted" style="margin: 10px 0 0">' + esc(att.reviewNote || 'Преподаватель скрыл разбор этого теста: правильные ответы не показываются.') + '</p>';
})();
