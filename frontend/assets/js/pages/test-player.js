/* Экраны 10–11. Прохождение теста: вопрос с вариантами, несколько верных, SQL, развёрнутый ответ.
   ?mode=training — тренировка: без таймера и оценки, с проверкой после каждого ответа. */
(function () {
  var A = window.App, M = A.M, G = window.Grading, esc = A.esc;
  var LETTERS = 'АБВГДЕ';
  var training = A.param('mode') === 'training';
  var Q = M.attempt.questions;
  var KEY = training ? 'training' : 'attempt';

  var st = A.store.get(KEY, null);
  if (!st || st.submitted) st = { answers: {}, flags: {}, current: 0, startedAt: Date.now() };
  if (training) { st.flags = {}; }
  function persist() { if (!training) A.store.set(KEY, st); }
  persist();

  var typeLabel = { single: 'один верный ответ', tf: 'верно или неверно', multiple: 'несколько верных ответов', sql: 'SQL-запрос', open: 'развёрнутый ответ' };

  function ans(i) { return st.answers[Q[i].id]; }
  function answeredCount() { return Q.filter(function (q, i) { return G.isAnswered(q, ans(i)); }).length; }

  /* ---------- Отрисовка вопроса ---------- */
  function render() {
    var i = st.current, q = Q[i], a = ans(i);
    A.$('#q-label').textContent = 'Вопрос ' + (i + 1) + ' из ' + Q.length + ' · ' + typeLabel[q.type] + ' · ' + q.points + ' ' + A.plural(q.points, 'балл', 'балла', 'баллов');
    A.$('#q-text').textContent = q.text;
    A.$('#feedback').hidden = true;

    var flagged = !!st.flags[q.id];
    var fb = A.$('#flag');
    fb.hidden = training;
    fb.classList.toggle('is-on', flagged);
    fb.setAttribute('aria-pressed', flagged);
    fb.innerHTML = A.icons.flag + (flagged ? 'Отмечен' : 'Вернуться позже');

    var body = A.$('#q-body');
    if (q.type === 'single' || q.type === 'tf' || q.type === 'multiple') {
      var multi = q.type === 'multiple', sel = a || [];
      body.innerHTML = '<div role="' + (multi ? 'group' : 'radiogroup') + '" aria-label="Варианты ответа" class="stack-sm" style="gap: 12px">' +
        q.options.map(function (o, k) {
          var on = sel.indexOf(k) >= 0;
          return '<button type="button" class="choice' + (multi ? ' choice--multi' : '') + (on ? ' is-on' : '') + '" role="' + (multi ? 'checkbox' : 'radio') + '" aria-checked="' + on + '" data-opt="' + k + '">' +
            '<span class="choice__mark">' + LETTERS[k] + '</span><span style="flex: 1">' + esc(o) + '</span></button>';
        }).join('') + '</div>' +
        (training ? '<button type="button" class="btn" style="margin-top: 16px" id="check">Проверить</button>' : '');
    } else if (q.type === 'open') {
      body.innerHTML =
        '<details class="criteria"><summary>Как будет оцениваться ответ</summary><ul>' +
          q.criteria.map(function (c) { return '<li>' + esc(c[0]) + ' — ' + c[1] + ' ' + A.plural(c[1], 'балл', 'балла', 'баллов') + '</li>'; }).join('') +
        '</ul></details>' +
        '<label for="answer" style="display: block; margin-top: 24px; font-weight: 500; font-size: 15px">Ваш ответ</label>' +
        '<textarea id="answer" class="answer-input" rows="9" maxlength="2000">' + esc(a || '') + '</textarea>' +
        '<div class="between xsmall muted" style="margin-top: 8px"><span id="saved"></span><span id="chars"></span></div>' +
        '<div class="note note--info" style="margin-top: 20px">' + A.icons.info + '<span>Ответ предварительно проверит агент-оценщик по критериям выше. Итоговый балл подтверждает преподаватель.</span></div>';
      updateChars();
    } else if (q.type === 'sql') {
      body.innerHTML =
        '<div class="small muted">Таблицы: <span class="mono">' + esc(q.schema) + '</span></div>' +
        '<label for="sql" style="display: block; margin-top: 16px; font-weight: 500; font-size: 15px">Ваш запрос</label>' +
        '<textarea id="sql" class="sql-input" rows="6" spellcheck="false" placeholder="' + esc(q.placeholder) + '" style="margin-top: 8px">' + esc(a || '') + '</textarea>' +
        '<div class="row-wrap" style="margin-top: 12px; gap: 12px"><button type="button" class="btn" id="run">Выполнить на учебной базе</button><span class="muted small" id="run-status"></span></div>' +
        '<div class="sql-result" id="run-result"></div>';
    }

    A.$('#prev').disabled = i === 0;
    A.$('#next').textContent = i === Q.length - 1 ? (training ? 'Закончить тренировку' : 'Завершить тест') : 'Следующий вопрос →';
    renderNav();
  }

  function renderNav() {
    A.$('#qnav').innerHTML = Q.map(function (q, i) {
      var cls = i === st.current ? 'is-current' : st.flags[q.id] ? 'is-flagged' : G.isAnswered(q, ans(i)) ? 'is-answered' : '';
      var state = i === st.current ? ', текущий' : st.flags[q.id] ? ', отмечен' : cls ? ', отвечен' : '';
      return '<button type="button" class="qn ' + cls + '" data-go="' + i + '" aria-label="Вопрос ' + (i + 1) + state + '">' + (i + 1) + '</button>';
    }).join('');
    var answered = answeredCount();
    var flagged = Object.keys(st.flags).filter(function (k) { return st.flags[k]; }).length;
    A.$('#legend').innerHTML =
      '<span><i class="a"></i>Отвечен · ' + answered + '</span>' +
      (training ? '' : '<span><i class="f"></i>Отмечен, вернуться · ' + flagged + '</span>') +
      '<span><i></i>Без ответа · ' + (Q.length - answered) + '</span>';
    A.$('#test-progress').style.width = Math.round(answered / Q.length * 100) + '%';
  }

  var savedTimer;
  function setAnswer(v) {
    st.answers[Q[st.current].id] = v;
    persist();
    renderNav();
    var s = A.$('#saved');
    if (s) {
      clearTimeout(savedTimer);
      s.innerHTML = 'Сохраняем…';
      savedTimer = setTimeout(function () { s.innerHTML = A.icons.ok.replace(/18/g, '14') + ' Сохранено'; s.style.display = 'flex'; s.style.gap = '6px'; }, 600);
    }
  }
  function updateChars() {
    var t = A.$('#answer');
    if (t) A.$('#chars').textContent = t.value.length + ' / 2000 символов';
  }

  /* ---------- События ---------- */
  A.$('#q-body').addEventListener('click', function (e) {
    var b = e.target.closest('[data-opt]');
    if (b) {
      var q = Q[st.current], k = +b.getAttribute('data-opt');
      var cur = (ans(st.current) || []).slice();
      if (q.type === 'multiple') {
        var p = cur.indexOf(k);
        if (p >= 0) cur.splice(p, 1); else cur.push(k);
      } else cur = [k];
      setAnswer(cur);
      render();
      return;
    }
    if (e.target.id === 'run') runSql();
    if (e.target.id === 'check') checkTraining();
  });
  A.$('#q-body').addEventListener('input', function (e) {
    if (e.target.id === 'answer') { setAnswer(e.target.value); updateChars(); }
    if (e.target.id === 'sql') setAnswer(e.target.value);
  });

  function runSql() {
    var t = (ans(st.current) || '').trim();
    var status = A.$('#run-status'), out = A.$('#run-result');
    if (!/^select\b/i.test(t)) { status.textContent = 'Ошибка: на учебной базе можно выполнять только SELECT'; out.innerHTML = ''; return; }
    if (!/group\s+by/i.test(t)) { status.textContent = 'Ошибка: столбец «g.name» должен входить в GROUP BY или агрегатную функцию'; out.innerHTML = ''; return; }
    var rows = M.sandbox.rows.slice();
    if (!/left\s+(outer\s+)?join/i.test(t)) rows = rows.filter(function (r) { return r[1] > 0; });
    if (!/desc/i.test(t)) rows.sort(function (a, b) { return a[0].localeCompare(b[0]); });
    status.textContent = 'Запрос выполнен · ' + rows.length + ' строк · ' + (8 + Math.round(Math.random() * 8)) + ' мс';
    out.innerHTML = '<table><thead><tr>' + M.sandbox.columns.map(function (c) { return '<th>' + c + '</th>'; }).join('') + '</tr></thead><tbody>' +
      rows.map(function (r) { return '<tr><td>' + esc(r[0]) + '</td><td>' + r[1] + '</td></tr>'; }).join('') + '</tbody></table>';
  }

  function checkTraining() {
    var q = Q[st.current], r = G.grade(q, ans(st.current));
    var fb = A.$('#feedback');
    fb.hidden = false;
    fb.innerHTML = r.score === r.max
      ? '<div class="ans ans--good">' + A.icons.check + '<span><b class="strong">Верно.</b> ' + esc(q.explanation) + '</span></div>'
      : '<div class="ans ans--bad">' + A.icons.cross + '<span><b class="strong">Неверно.</b> Подсказка: ' + esc(q.explanation) + ' <span class="muted">(' + esc(q.ref) + ')</span></span></div>';
  }

  A.$('#flag').addEventListener('click', function () {
    var id = Q[st.current].id;
    st.flags[id] = !st.flags[id];
    persist(); render();
  });
  A.$('#qnav').addEventListener('click', function (e) {
    var b = e.target.closest('[data-go]');
    if (b) { st.current = +b.getAttribute('data-go'); persist(); render(); }
  });
  A.$('#prev').addEventListener('click', function () { if (st.current > 0) { st.current--; persist(); render(); } });
  A.$('#next').addEventListener('click', function () {
    if (st.current < Q.length - 1) { st.current++; persist(); render(); window.scrollTo(0, 0); }
    else finish();
  });
  A.$('#finish').addEventListener('click', finish);

  function finish(force) {
    if (training) { location.href = 'tests.html'; return; }
    var left = Q.length - answeredCount();
    if (force !== true && left && !confirm('Без ответа ' + left + ' ' + A.plural(left, 'вопрос', 'вопроса', 'вопросов') + '. Завершить тест?')) return;
    var score = 0, max = 0, results = {};
    Q.forEach(function (q, i) {
      var r = G.grade(q, ans(i));
      results[q.id] = r; score += r.score; max += r.max;
    });
    st.submitted = true;
    st.results = results; st.score = score; st.max = max;
    st.percent = Math.round(score / max * 100);
    st.minutes = Math.max(1, Math.round((Date.now() - st.startedAt) / 60000));
    A.store.set(KEY, st);
    location.href = 'result.html';
  }

  /* ---------- Таймер ---------- */
  var timerEl = A.$('#timer');
  if (training) {
    timerEl.hidden = true;
    A.$('.test-header__title').textContent = 'Тренировка · Нормализация и проектирование';
    A.$('#finish').textContent = 'Закончить тренировку';
    A.$('#autosave').textContent = 'Тренировка без оценки: после ответа нажмите «Проверить»';
  } else {
    var tick = function () {
      var left = M.attempt.minutes * 60 - Math.floor((Date.now() - st.startedAt) / 1000);
      if (left <= 0) { finish(true); return; }
      var m = Math.floor(left / 60), s = left % 60;
      timerEl.lastChild.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
      timerEl.classList.toggle('is-low', left < 300);
    };
    tick();
    setInterval(tick, 1000);
  }

  render();
})();
