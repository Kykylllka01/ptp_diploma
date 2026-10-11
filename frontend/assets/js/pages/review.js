/* Экран 8. Проверка открытых ответов: балл агента + уверенность, подтверждение преподавателем */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var current, scores;

  function list() { return A.reviews(); }
  function crit(r) { return M.criteria[r.questionId]; }
  function maxOf(r) { return crit(r).reduce(function (s, c) { return s + c[1]; }, 0); }
  function agentTotal(r) { return r.agent.reduce(function (s, a) { return s + a[0]; }, 0); }

  function renderQueue() {
    var all = list();
    var done = all.filter(function (r) { return r.status === 'confirmed'; }).length;
    A.$('#progress').textContent = done + ' из ' + all.length + ' подтверждено';
    A.$('#queue').innerHTML = all.map(function (r) {
      var isDone = r.status === 'confirmed';
      var line = isDone ? r.questionId + ' · подтверждено ' + r.final + ' / ' + maxOf(r)
        : r.questionId + ' · агент: ' + agentTotal(r) + ' / ' + maxOf(r) + ' · уверенность ' + r.confidence + '%';
      return '<button type="button" class="q-item' + (r.id === current.id ? ' is-on' : '') + (isDone ? ' is-done' : '') + '" data-id="' + r.id + '">' +
        '<span class="strong">' + esc(r.student) + '</span><span' + (isDone ? '' : ' class="muted"') + '>' + line + '</span></button>';
    }).join('');
  }

  function renderTotal() {
    var t = scores.reduce(function (s, x) { return s + x; }, 0);
    A.$('#total').textContent = t;
    A.$('#max').textContent = 'из ' + maxOf(current) + ' ' + A.plural(maxOf(current), 'балла', 'баллов', 'баллов');
  }

  function renderCurrent() {
    var r = current, q = M.questionTexts[r.questionId], c = crit(r);
    scores = r.scores ? r.scores.slice() : r.agent.map(function (a) { return a[0]; });

    A.$('#q-label').textContent = 'Вопрос ' + r.questionId + ' · развёрнутый · ' + maxOf(r) + ' баллов';
    A.$('#q-text').textContent = q.text;
    A.$('#q-ref').textContent = q.reference;
    A.$('#a-meta').textContent = r.student + ' · ' + r.answer.replace(/<[^>]+>/g, '').length + ' символов · ' + r.minutes + ' мин';
    A.$('#a-text').innerHTML = r.answer; // в демо разметка подсветки берётся из моков; с сервера — только экранированный текст + диапазоны
    A.$('#confidence').innerHTML = A.chip('Агент-оценщик · уверенность ' + r.confidence + '%', r.confidence < 70 ? 'amber' : 'blue');

    var tone = { ok: 't-green', part: 't-amber', no: 't-red' };
    A.$('#criteria').innerHTML = c.map(function (cr, i) {
      var a = r.agent[i], btns = '';
      for (var p = 0; p <= cr[1]; p++) {
        btns += '<button type="button" data-c="' + i + '" data-p="' + p + '"' + (scores[i] === p ? ' class="is-on" aria-pressed="true"' : ' aria-pressed="false"') + '>' + p + '</button>';
      }
      return '<div class="crit"><div class="crit__main"><div class="strong">' + esc(cr[0]) + '</div>' +
        '<div class="small ' + tone[a[1]] + '" style="margin-top: 4px">Агент: ' + esc(a[2]) + '</div></div>' +
        '<div class="sc" role="group" aria-label="Баллы за критерий ' + (i + 1) + '">' + btns + '</div></div>';
    }).join('');

    A.$('#comment').value = r.comment || '';
    A.$('#confirm').textContent = r.status === 'confirmed' ? 'Сохранить изменения' : 'Подтвердить и далее';
    renderTotal();
    renderQueue();
  }

  function open(id) {
    current = list().filter(function (r) { return r.id === id; })[0];
    renderCurrent();
  }

  function next() {
    var all = list();
    var i = all.map(function (r) { return r.id; }).indexOf(current.id);
    var rest = all.slice(i + 1).concat(all.slice(0, i)).filter(function (r) { return r.status !== 'confirmed'; });
    if (rest.length) open(rest[0].id);
    else { renderQueue(); A.toast('Все ответы проверены — студенты увидят итоговые баллы'); }
  }

  A.$('#queue').addEventListener('click', function (e) {
    var b = e.target.closest('[data-id]');
    if (b) open(+b.getAttribute('data-id'));
  });
  A.$('#criteria').addEventListener('click', function (e) {
    var b = e.target.closest('[data-c]');
    if (!b) return;
    var c = +b.getAttribute('data-c');
    scores[c] = +b.getAttribute('data-p');
    A.$$('[data-c="' + c + '"]').forEach(function (x) {
      var on = +x.getAttribute('data-p') === scores[c];
      x.classList.toggle('is-on', on);
      x.setAttribute('aria-pressed', on);
    });
    renderTotal();
  });
  A.$('#confirm').addEventListener('click', function () {
    var total = scores.reduce(function (s, x) { return s + x; }, 0);
    A.saveReview(current.id, { status: 'confirmed', final: total, scores: scores.slice(), comment: A.$('#comment').value });
    A.toast(current.student + ': ' + total + ' из ' + maxOf(current) + ' — подтверждено');
    current = list().filter(function (r) { return r.id === current.id; })[0];
    next();
  });
  A.$('#skip').addEventListener('click', next);

  var all = list();
  var start = all.filter(function (r) { return r.status !== 'confirmed'; })[0] || all[0];
  open(start.id);
})();
