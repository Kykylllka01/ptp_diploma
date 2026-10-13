/* Экран 8. Проверка открытых ответов: балл агента + уверенность, подтверждение преподавателем */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var current, scores, saved = '';
  // Фильтры очереди: тест (?test=код), одна попытка (?attempt=id) и что показывать
  var f = { test: A.param('test') || '', attempt: A.param('attempt') ? +A.param('attempt') : 0, show: 'pending' };

  // Несколько преподавателей: по умолчанию ответы на свои тесты; ссылка на конкретный тест или попытку — без этого фильтра
  var own = A.owner('review', function () {
    // Открытый ответ выпал из охвата (или ещё ничего не открыто) — открываем первый из нового списка
    if (current && inScope(current)) { renderQueue(); return; }
    var first = firstOpen();
    if (first) open(first.id); else { renderQueue(); showNothing(); }
  });
  function firstOpen() { return list().filter(function (r) { return r.status !== 'confirmed'; })[0] || list()[0]; }
  // Своих ответов нет, а у коллег есть: экран проверки пуст, в очереди — переключатель «Все преподаватели»
  function showNothing() {
    A.$('.split__main').hidden = true;
    var box = A.$('#own-empty');
    if (!box) {
      A.$('.split__main').insertAdjacentHTML('beforebegin', '<div class="card card--pad empty" style="flex: 1" id="own-empty"></div>');
      box = A.$('#own-empty');
    }
    box.innerHTML = 'Ответов на ваши тесты пока нет. <button type="button" class="link-btn" data-owner="all">Показать ответы на тесты всех преподавателей</button>';
  }
  function all() { return A.reviews(); }
  function inScope(r) { return (f.test || f.attempt || own.match(r)) && (!f.test || r.test === f.test) && (!f.attempt || r.attemptId === f.attempt); }
  function list() {
    return all().filter(function (r) {
      return inScope(r) && (f.show === 'all' || (f.show === 'appeal' ? !!r.appeal : r.status !== 'confirmed') || (current && r.id === current.id));
    });
  }
  function crit(r) { return M.criteria[r.questionId]; }
  function maxOf(r) { return crit(r).reduce(function (s, c) { return s + c[1]; }, 0); }
  function agentTotal(r) { return r.agent.reduce(function (s, a) { return s + a[0]; }, 0); }

  function renderQueue() {
    var scope = all().filter(inScope);
    A.$('#owner').innerHTML = f.test || f.attempt ? '' : own.html(all());
    var done = scope.filter(function (r) { return r.status === 'confirmed'; }).length;
    A.$('#progress').textContent = done + ' из ' + scope.length + ' подтверждено' + (f.attempt ? ' · одна попытка' : '');
    A.$('#queue-filters').innerHTML = A.filterTabs([
      ['pending', 'Ждут', scope.length - done],
      ['appeal', 'Апелляции', scope.filter(function (r) { return r.appeal; }).length],
      ['all', 'Все', scope.length]
    ], f.show, 'Какие ответы показать');
    A.$('#queue').innerHTML = list().map(function (r) {
      var isDone = r.status === 'confirmed';
      var line = isDone ? r.questionId + ' · подтверждено ' + r.final + ' / ' + maxOf(r)
        : r.questionId + ' · агент: ' + agentTotal(r) + ' / ' + maxOf(r) + ' · уверенность ' + r.confidence + '%';
      var on = current && r.id === current.id;
      return '<button type="button" class="q-item' + (on ? ' is-on' : '') + (isDone ? ' is-done' : '') + '" data-id="' + r.id + '"' + (on ? ' aria-current="true"' : '') + '>' +
        '<span class="between" style="gap: 8px"><span class="strong">' + esc(r.student) + '</span>' + (r.appeal ? A.chip('апелляция', 'amber', 'chip--sm') : isDone ? A.chip('готово', 'green', 'chip--sm') : '') + '</span>' +
        (!f.test && r.testTitle ? '<span class="xsmall muted">' + esc(r.testTitle) + '</span>' : '') +
        '<span' + (isDone ? '' : ' class="muted"') + '>' + line + '</span></button>';
    }).join('') || '<div class="empty small">' + (!scope.length && all().length && !f.test && !f.attempt
      ? 'На ваши тесты ответов нет. <button type="button" class="link-btn" data-owner="all">Показать все</button>'
      : f.show === 'appeal' ? 'Апелляций нет' : 'Все ответы проверены') + '</div>';
  }

  function renderTotal() {
    var t = scores.reduce(function (s, x) { return s + x; }, 0);
    A.$('#total').textContent = t;
    A.$('#max').textContent = 'из ' + maxOf(current) + ' ' + A.plural(maxOf(current), 'балла', 'баллов', 'баллов');
  }

  function renderCurrent() {
    var r = current, q = M.questionTexts[r.questionId], c = crit(r);
    scores = r.scores ? r.scores.slice() : c.map(function (_, i) { return (r.agent[i] || [0])[0]; });

    A.$('#q-label').textContent = 'Вопрос ' + r.questionId + ' · развёрнутый · ' + maxOf(r) + ' ' + A.plural(maxOf(r), 'балл', 'балла', 'баллов');
    A.$('#q-text').textContent = q.text;
    A.$('#q-context').innerHTML = A.context(q.context);
    A.$('#q-ref').textContent = q.reference;
    A.$('#a-meta').textContent = r.student + ' · ' + r.answer.replace(/<[^>]+>/g, '').length + ' символов · ' + r.minutes + ' мин';
    A.$('#a-text').innerHTML = r.answer; // с сервера приходит экранированный текст, подсветка — только <mark> агента
    A.$('#appeal').hidden = !r.appeal;
    A.$('#appeal').innerHTML = r.appeal ? '<b>Апелляция студента:</b> ' + esc(r.appeal) : '';
    A.$('#confidence').innerHTML = A.chip('Агент-оценщик · уверенность ' + r.confidence + '%', r.confidence < 70 ? 'amber' : 'blue', 'chip--ai');

    var tone = { ok: 't-green', part: 't-amber', no: 't-red' };
    A.$('#criteria').innerHTML = c.map(function (cr, i) {
      var a = r.agent[i] || [0, 'no', 'нет оценки агента'], btns = '';
      for (var p = 0; p <= cr[1]; p++) {
        btns += '<button type="button" data-c="' + i + '" data-p="' + p + '"' + (scores[i] === p ? ' class="is-on" aria-pressed="true"' : ' aria-pressed="false"') + '>' + p + '</button>';
      }
      return '<div class="crit"><div class="crit__main"><div class="strong" id="crit-' + i + '">' + esc(cr[0]) + '</div>' +
        '<div class="small ' + tone[a[1]] + '" style="margin-top: 4px">Агент: ' + esc(a[2]) + '</div></div>' +
        '<div class="sc" role="group" aria-labelledby="crit-' + i + '">' + btns + '</div></div>';
    }).join('');

    A.$('#comment').value = r.comment || '';
    saved = state();
    A.$('#q-label').textContent += r.testTitle ? ' · ' + r.testTitle : '';
    A.$('#confirm').textContent = r.status === 'confirmed' ? 'Сохранить изменения' : 'Подтвердить и далее';
    renderTotal();
    renderQueue();
  }

  // Изменённые, но не подтверждённые баллы или комментарий — переход к другому ответу спрашивает
  function state() { return JSON.stringify([scores, A.$('#comment').value]); }
  function guard() {
    if (!current || state() === saved) return Promise.resolve(true);
    return A.confirm({ title: 'Уйти без сохранения?', text: 'Баллы или комментарий для ответа ' + current.student + ' изменены, но не подтверждены.', ok: 'Уйти', cancel: 'Остаться' });
  }
  function open(id, focus) {
    current = all().filter(function (r) { return r.id === id; })[0];
    A.$('.split__main').hidden = false;
    if (A.$('#own-empty')) A.$('#own-empty').remove();
    renderCurrent();
    if (focus) A.$('#q-label').focus();
  }

  function next() {
    var scope = all().filter(inScope);
    var i = scope.map(function (r) { return r.id; }).indexOf(current.id);
    var rest = scope.slice(i + 1).concat(scope.slice(0, i)).filter(function (r) { return r.status !== 'confirmed'; });
    if (rest.length) open(rest[0].id, true);
    else { renderQueue(); A.toast('Все ответы проверены — студенты увидят итоговые баллы'); }
  }

  A.$('#queue').addEventListener('click', function (e) {
    var b = e.target.closest('[data-id]');
    if (saving) { A.toast('Сохраняю оценку — секунду'); return; }
    if (b && +b.getAttribute('data-id') !== current.id) guard().then(function (ok) { if (ok) open(+b.getAttribute('data-id'), true); });
  });
  A.$('#queue-filters').addEventListener('click', function (e) {
    var b = e.target.closest('[data-filter]');
    if (b) { f.show = b.getAttribute('data-filter'); A.keepFocus(renderQueue); }
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
  var saving = false;
  A.$('#confirm').addEventListener('click', function () {
    var total = scores.reduce(function (s, x) { return s + x; }, 0);
    var id = current.id, name = current.student, max = maxOf(current);
    var btn = A.$('#confirm');
    btn.disabled = true;
    saving = true;
    // К следующему ответу — только когда оценка сохранена; при ошибке ответ остаётся на экране
    A.saveReview(id, { status: 'confirmed', final: total, scores: scores.slice(), comment: A.$('#comment').value }).then(function () {
      A.toast(name + ': ' + total + ' из ' + max + ' — подтверждено');
      if (current.id !== id) return;   // пока сохраняли, открыли другой ответ — остаёмся на нём
      current = all().filter(function (r) { return r.id === id; })[0];
      saved = state();
      next();
    }, function () { renderQueue(); }).then(function () { saving = false; btn.disabled = false; }, function () { saving = false; btn.disabled = false; });
  });
  A.$('#skip').addEventListener('click', function () { guard().then(function (ok) { if (ok) next(); }); });
  window.addEventListener('beforeunload', function (e) { if (current && state() !== saved) { e.preventDefault(); e.returnValue = ''; } });

  // Фильтр по тесту — когда ответы из нескольких тестов
  var tests = {};
  all().forEach(function (r) { if (r.test) tests[r.test] = r.testTitle; });
  if (Object.keys(tests).length > 1 || f.test) {
    var tf = A.$('#test-filter');
    tf.innerHTML = '<option value="">Все тесты</option>' + Object.keys(tests).map(function (k) { return '<option value="' + esc(k) + '">' + esc(tests[k]) + '</option>'; }).join('');
    tf.value = f.test;
    tf.hidden = false;
    tf.addEventListener('change', function () {
      guard().then(function (ok) {
        if (!ok) { tf.value = f.test; return; }
        f.test = tf.value; f.attempt = 0;
        var first = firstOpen();
        if (first) open(first.id); else renderQueue();
      });
    });
  }

  var start = firstOpen();
  if (!start && all().length) { renderQueue(); showNothing(); return; }
  if (!start) {
    A.$('#progress').textContent = 'Открытых ответов пока нет';
    A.$('.split').innerHTML = '<div class="card card--pad empty" style="flex: 1">Когда студенты сдадут тесты с развёрнутыми ответами, они появятся здесь с оценкой агента.</div>';
    return;
  }
  open(start.id);
})();
