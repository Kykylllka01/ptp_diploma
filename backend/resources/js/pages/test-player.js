/* Экраны 10–11. Прохождение теста: вопрос с вариантами, несколько верных, SQL, развёрнутый ответ.
   ?mode=training — тренировка: без таймера и оценки, с проверкой после каждого ответа. */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var LETTERS = 'АБВГДЕЖЗИК';
  // Вариантов может быть больше, чем букв, — тогда номер, а не «undefined»
  function letter(i) { return LETTERS[i] || String(i + 1); }
  // Режимы: экзамен; тренировка студента (и работа над ошибками); пробное прохождение преподавателем
  var preview = M.mode === 'preview';
  var training = preview || A.param('mode') === 'training';
  var submitted = false;
  var Q = M.attempt.questions.slice();

  // Перемешивание по настройке теста: порядок постоянный для попытки (зависит от её номера)
  function seeded(seed) {
    return function () { seed = (seed * 1103515245 + 12345) % 2147483648; return seed / 2147483648; };
  }
  function shuffled(list, rnd) {
    var a = list.slice();
    for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(rnd() * (i + 1)); var t = a[i]; a[i] = a[j]; a[j] = t; }
    return a;
  }
  var optOrder = {};
  if (!training && M.attempt.shuffle && M.attempt.attemptId) {
    var rnd = seeded(M.attempt.attemptId * 7919 + 17);
    Q = shuffled(Q, rnd);
    Q.forEach(function (q) {
      if (q.options && q.type !== 'tf') optOrder[q.id] = shuffled(q.options.map(function (_, k) { return k; }), rnd);
    });
  }
  function orderOf(q) { return optOrder[q.id] || q.options.map(function (_, k) { return k; }); }
  var G = {
    isAnswered: function (q, ans) {
      if (ans == null) return false;
      if (q.type === 'match') return Array.isArray(ans) && ans.length === q.terms.length && ans.every(function (x) { return x >= 0; });
      if (Array.isArray(ans)) return ans.length > 0;
      return String(ans).trim().length > 0;
    }
  };

  // Попытка живёт на сервере: ответы, отметки и текущий вопрос; время старта — по часам сервера
  var clockSkew = Date.now() - M.attempt.serverNow;
  var st = {
    answers: training ? {} : Object.assign({}, M.attempt.answers),
    flags: training ? {} : Object.assign({}, M.attempt.flags),
    current: training ? 0 : Math.min(M.attempt.current || 0, Q.length - 1),
    startedAt: M.attempt.startedAt + clockSkew
  };

  // Автосохранение с задержкой, чтобы не слать запрос на каждую букву
  var saveTimer, saving = Promise.resolve();
  function persist() {
    if (training || !M.attempt.attemptId) return;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(flush, 800);
  }
  function flush() {
    clearTimeout(saveTimer);
    if (submitted) return saving;
    // Без всплывающих сообщений: состояние сохранения видно в боковой панели, повтор — сам через 5 с
    saving = A.api('PUT', 'attempts/' + M.attempt.attemptId, { answers: st.answers, flags: st.flags, current: st.current }, true)
      .then(function () { markSaved(); })
      .catch(function (err) {
        if (err && err.status === 409) { finish(true); return; }
        // Сеть пропала или сервер ответил ошибкой — не делаем вид, что сохранили, и повторяем
        var s = A.$('#saved');
        if (s) s.textContent = 'Не сохранено — повторим через несколько секунд';
        saveStatus('Ответы не сохранились — нет связи с сервером. Повторим через несколько секунд, не закрывайте страницу.', 'error');
        clearTimeout(saveTimer);
        saveTimer = setTimeout(flush, 5000);
      });
    return saving;
  }

  // Уход со страницы: обычный запрос браузер отменит, keepalive — доставит последние ответы
  function flushOnLeave() {
    if (!saveTimer || submitted || training || !M.attempt.attemptId) return;
    clearTimeout(saveTimer);
    saveTimer = null;
    try {
      fetch('/api/attempts/' + M.attempt.attemptId, {
        method: 'PUT', keepalive: true, credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': A.csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ answers: st.answers, flags: st.flags, current: st.current })
      });
    } catch (e) { /* страница уже закрывается */ }
  }

  var typeLabel = { single: 'один верный ответ', tf: 'верно или неверно', multiple: 'несколько верных ответов', match: 'сопоставление', sql: 'SQL-запрос', open: 'развёрнутый ответ' };

  function ans(i) { return st.answers[Q[i].id]; }
  function answeredCount() { return Q.filter(function (q, i) { return G.isAnswered(q, ans(i)); }).length; }

  /* ---------- Отрисовка вопроса ---------- */
  function render() {
    var i = st.current, q = Q[i], a = ans(i);
    A.$('#q-label').textContent = 'Вопрос ' + (i + 1) + ' из ' + Q.length + ' · ' + typeLabel[q.type] + ' · ' + q.points + ' ' + A.plural(q.points, 'балл', 'балла', 'баллов');
    A.$('#q-text').textContent = q.text;
    A.$('#q-context').innerHTML = A.context(q.context);
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
      // Радиогруппа как у системных переключателей: в фокус попадает выбранный вариант, стрелки переключают выбор
      var order = orderOf(q), focusK = !multi ? (sel.length ? sel[0] : order[0]) : null;
      body.innerHTML = '<div role="' + (multi ? 'group' : 'radiogroup') + '" aria-labelledby="q-text" class="stack-sm" style="gap: 12px">' +
        order.map(function (k, pos) {
          var on = sel.indexOf(k) >= 0;
          return '<button type="button" class="choice' + (multi ? ' choice--multi' : '') + (on ? ' is-on' : '') + '" role="' + (multi ? 'checkbox' : 'radio') + '" aria-checked="' + on + '" data-opt="' + k + '"' +
            (!multi && k !== focusK ? ' tabindex="-1"' : '') + '>' +
            '<span class="choice__mark">' + letter(pos) + '</span><span style="flex: 1">' + esc(q.options[k]) + '</span></button>';
        }).join('') + '</div>' +
        (training ? '<button type="button" class="btn" style="margin-top: 16px" id="check">Проверить</button>' : '');
    } else if (q.type === 'match') {
      // Определения длинные, поэтому они идут списком с буквами, а для термина выбирается буква
      var chosen = a || [];
      body.innerHTML = '<ol class="match-defs">' + q.definitions.map(function (d, j) {
        return '<li><span class="mono strong">' + letter(j) + '</span><span>' + esc(d) + '</span></li>';
      }).join('') + '</ol>' +
        '<div class="stack-sm" style="gap: 10px; margin-top: 20px">' + q.terms.map(function (t, k) {
        var cur = chosen[k] == null ? -1 : chosen[k];
        return '<label class="match-row"><span class="strong">' + esc(t) + '</span>' +
          '<select class="select" data-match="' + k + '" aria-label="Определение для «' + esc(t) + '»"><option value="-1">— буква определения —</option>' +
          q.definitions.map(function (d, j) { return '<option value="' + j + '"' + (cur === j ? ' selected' : '') + '>' + letter(j) + '</option>'; }).join('') +
          '</select></label>';
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
        (training
          ? '<button type="button" class="btn" style="margin-top: 16px" id="check">' + (preview ? 'Показать эталон' : 'Сравнить с эталоном') + '</button>'
          : '<div class="note note--info" style="margin-top: 20px">' + A.icons.info + '<span>Ответ предварительно проверит агент-оценщик по критериям выше. Итоговый балл подтверждает преподаватель.</span></div>');
      updateChars();
    } else if (q.type === 'sql') {
      body.innerHTML =
        '<div class="small muted">Таблицы: <span class="mono">' + esc(q.schema) + '</span></div>' +
        '<label for="sql" style="display: block; margin-top: 16px; font-weight: 500; font-size: 15px">Ваш запрос</label>' +
        '<textarea id="sql" class="sql-input" rows="6" spellcheck="false" placeholder="' + esc(q.placeholder) + '" style="margin-top: 8px">' + esc(a || '') + '</textarea>' +
        '<div class="row-wrap" style="margin-top: 12px; gap: 12px"><button type="button" class="btn" id="run">Выполнить на учебной базе</button><span class="muted small" id="run-status"></span></div>' +
        '<div class="sql-result" id="run-result"></div>' +
        (training ? '<button type="button" class="btn" style="margin-top: 16px" id="check">Проверить</button>' : '');
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

  function setAnswer(v) {
    st.answers[Q[st.current].id] = v;
    persist();
    renderNav();
    var s = A.$('#saved');
    if (s && !training) s.innerHTML = 'Сохраняем…';
    if (!training) saveStatus('Сохраняем ответ…', 'busy');
  }
  function markSaved() {
    var s = A.$('#saved');
    if (s) { s.innerHTML = A.icons.ok.replace(/18/g, '14') + ' Сохранено'; s.style.display = 'flex'; s.style.gap = '6px'; }
    var d = new Date();
    saveStatus('Ответы сохранены в ' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2), 'ok');
  }
  // Состояние автосохранения в боковой панели — для всех типов вопросов, а не только для развёрнутых
  function saveStatus(text, kind) {
    var el = A.$('#autosave');
    if (!el || preview || training) return;
    el.textContent = text;
    el.className = 'note xsmall save-status save-status--' + kind;
  }
  function updateChars() {
    var t = A.$('#answer');
    if (t) A.$('#chars').textContent = t.value.length + ' / 2000 символов';
  }

  /* ---------- События ---------- */
  A.$('#q-body').addEventListener('change', function (e) {
    var k = e.target.getAttribute('data-match');
    if (k === null) return;
    var q = Q[st.current];
    var cur = (ans(st.current) || q.terms.map(function () { return -1; })).slice();
    cur[+k] = +e.target.value;
    setAnswer(cur);
  });
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
      // render() пересоздаёт кнопки — возвращаем фокус клавиатуры на выбранный вариант
      var again = A.$('[data-opt="' + k + '"]');
      if (again) again.focus();
      return;
    }
    if (e.target.id === 'run') runSql();
    if (e.target.id === 'check') checkTraining(false);
  });
  A.$('#q-body').addEventListener('keydown', function (e) {
    var cur = e.target.closest('[role="radio"]');
    if (!cur || ['ArrowDown', 'ArrowRight', 'ArrowUp', 'ArrowLeft'].indexOf(e.key) < 0) return;
    e.preventDefault();
    var all = A.$$('[role="radio"]', A.$('#q-body')), i = all.indexOf(cur);
    var next = all[(i + (e.key === 'ArrowDown' || e.key === 'ArrowRight' ? 1 : all.length - 1)) % all.length];
    next.click();   // выбор и перерисовка; обработчик клика возвращает фокус на выбранный вариант
  });
  A.$('#q-body').addEventListener('input', function (e) {
    if (e.target.id === 'answer') { setAnswer(e.target.value); updateChars(); }
    if (e.target.id === 'sql') setAnswer(e.target.value);
  });

  // Запрос выполняется на сервере в учебной базе (SQLite в памяти)
  function runSql() {
    var t = (ans(st.current) || '').trim();
    var status = A.$('#run-status'), out = A.$('#run-result');
    if (!t) { status.textContent = 'Напишите запрос'; return; }
    status.textContent = 'Выполняем…';
    var runBtn = A.$('#run');
    if (runBtn) runBtn.disabled = true;
    A.api('POST', 'sql/run', { sql: t }).then(function (r) {
      if (!r.ok) { status.textContent = r.error; out.innerHTML = ''; return; }
      status.textContent = 'Запрос выполнен · ' + r.rows.length + ' ' + A.plural(r.rows.length, 'строка', 'строки', 'строк') + ' · ' + r.ms + ' мс';
      out.innerHTML = '<table><thead><tr>' + r.columns.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('') + '</tr></thead><tbody>' +
        r.rows.map(function (row) { return '<tr>' + row.map(function (v) { return '<td>' + (v === null ? '<span class="muted">NULL</span>' : esc(v)) + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table>' +
        (r.rows.length >= 200 ? '<p class="muted small">Показаны первые 200 строк</p>' : '');
    }, function () {
      status.textContent = 'Запрос не выполнен — проверьте соединение и попробуйте ещё раз';
    }).then(function () { if (runBtn) runBtn.disabled = false; });
  }

  function checkTraining(withAgent) {
    var q = Q[st.current], fb = A.$('#feedback');
    if (q.type !== 'open' && !G.isAnswered(q, ans(st.current))) { A.toast('Сначала ответьте на вопрос'); return; }
    if (withAgent) { fb.hidden = false; fb.innerHTML = '<div class="note">Оценщик проверяет ответ: три прогона модели, это до минуты…</div>'; }
    var path = 'questions/' + encodeURIComponent(q.id) + (preview ? '/preview-check' : '/check');
    // Сервер проверяет, что вопрос из этой тренировки (test) или из своей сданной попытки (attempt)
    var body = { answer: ans(st.current), agent: !!withAgent, test: M.attempt.testId };
    if (A.param('attempt')) body.attempt = Number(A.param('attempt'));
    A.api('POST', path, body).then(function (r) {
      fb.hidden = false;
      fb.innerHTML = feedbackHtml(q, r);
    }).catch(function () { fb.hidden = true; });
  }

  function feedbackHtml(q, r) {
    var html = '';
    if (q.type === 'open') {
      if (r.agent) {
        html += '<div class="ans ' + (r.correct ? 'ans--good' : 'ans--bad') + '">' + (r.correct ? A.icons.check : A.icons.cross) +
          '<span><b class="strong">Оценщик: ' + r.score + ' из ' + r.max + '</b> · уверенность ' + r.agent.confidence + '%<br>' + esc(r.agent.comment || '') + '</span></div>' +
          '<ul class="small" style="margin: 10px 0 0; padding-left: 20px; line-height: 1.6">' + r.agent.scores.map(function (s, i) {
            return '<li>' + esc((r.criteria[i] || [''])[0]) + ': <b>' + s[0] + '</b> — ' + esc(s[2]) + '</li>';
          }).join('') + '</ul>';
      }
      html += '<div class="note" style="margin-top: 10px; white-space: pre-wrap"><b class="strong">Эталон.</b> ' + esc(r.reference || 'не задан') + '</div>' +
        '<ul class="small" style="margin: 10px 0 0; padding-left: 20px; line-height: 1.6">' + (r.criteria || []).map(function (c) {
          return '<li>' + esc(c[0]) + ' — ' + c[1] + ' ' + A.plural(c[1], 'балл', 'балла', 'баллов') + '</li>';
        }).join('') + '</ul>' +
        (preview && !r.agent ? '<button type="button" class="btn btn--sm" style="margin-top: 12px" id="check-agent">Оценить ответ агентом</button>' : '');
      return html;
    }
    html = r.correct
      ? '<div class="ans ans--good">' + A.icons.check + '<span><b class="strong">Верно' + (r.max > 1 ? ' · ' + r.score + ' из ' + r.max : '') + '.</b> ' + esc(r.explanation || '') + '</span></div>'
      : '<div class="ans ans--bad">' + A.icons.cross + '<span><b class="strong">Неверно' + (q.type === 'sql' ? ' · ' + r.score + ' из ' + r.max : '') + '.</b> ' + (preview ? '' : 'Подсказка: ') + esc(r.explanation || '') + ' <span class="muted">(' + esc(r.ref || '') + ')</span></span></div>';
    if (q.type === 'sql' && r.criteria && r.criteria.length) {
      html += '<ul class="small" style="margin: 10px 0 0; padding-left: 20px; line-height: 1.6">' + r.criteria.map(function (c) { return '<li>' + c[0] + ' / ' + c[1] + ' — ' + esc(c[2]) + '</li>'; }).join('') + '</ul>';
    }
    if (r.key && (preview || q.type === 'sql')) {
      html += '<div class="note" style="margin-top: 10px; white-space: pre-wrap' + (q.type === 'sql' ? '; font-family: ui-monospace, Consolas, monospace' : '') + '"><b class="strong" style="font-family: var(--font)">' + (q.type === 'sql' ? 'Эталонный запрос' : 'Верный ответ') + ':</b> ' + esc(r.key) + '</div>';
    }
    return html;
  }

  A.$('#feedback').addEventListener('click', function (e) {
    if (e.target.id === 'check-agent') checkTraining(true);
  });

  A.$('#flag').addEventListener('click', function () {
    var id = Q[st.current].id;
    st.flags[id] = !st.flags[id];
    persist(); render();
  });
  A.$('#qnav').addEventListener('click', function (e) {
    var b = e.target.closest('[data-go]');
    if (b) { st.current = +b.getAttribute('data-go'); persist(); A.keepFocus(render); }
  });
  function go(step) {
    st.current += step; persist(); render();
    window.scrollTo(0, 0);
    A.$('#q-text').focus({ preventScroll: true });
  }
  A.$('#prev').addEventListener('click', function () { if (st.current > 0) go(-1); });
  A.$('#next').addEventListener('click', function () {
    if (st.current < Q.length - 1) go(1);
    else finish();
  });
  A.$('#finish').addEventListener('click', finish);

  function finish(force) {
    if (training) { location.href = 'tests'; return; }
    if (submitted) return;
    var left = Q.length - answeredCount();
    if (force !== true && left) {
      A.confirm({ title: 'Завершить тест?', text: 'Без ответа ' + left + ' ' + A.plural(left, 'вопрос', 'вопроса', 'вопросов') + '. После завершения изменить ответы будет нельзя.', ok: 'Завершить', cancel: 'Вернуться к вопросам' })
        .then(function (yes) { if (yes) finish(true); });
      return;
    }
    submitted = true;
    clearTimeout(saveTimer);
    saveTimer = null;
    clearInterval(tickTimer);
    // Оценивает сервер: варианты сразу, SQL на учебной базе, развёрнутые — агент-оценщик
    A.api('POST', 'attempts/' + M.attempt.attemptId + '/submit', { answers: st.answers, flags: st.flags, current: st.current })
      .then(function (r) { location.href = r.redirect; })
      // Сдача не прошла (например, учебная база SQL недоступна — сервер откатил её): ответы сохранены,
      // сообщение уже показано, кнопку «Завершить тест» можно нажать ещё раз. Таймер не перезапускаем,
      // чтобы после конца времени он не повторял сдачу каждую секунду.
      .catch(function () { submitted = false; });
  }

  /* ---------- Таймер ---------- */
  var timerEl = A.$('#timer'), tickTimer;
  if (preview) {
    timerEl.hidden = true;
    A.$('.test-header__title').textContent = 'Проверка теста · ' + M.attempt.title;
    A.$('#finish').textContent = 'Закончить проверку';
    A.$('#autosave').innerHTML = 'Пробное прохождение: ответы не сохраняются и не попадают в аналитику. После ответа нажмите «Проверить» — покажем верный ответ.' +
      (M.attempt.minutes ? '<br>У студентов будет ' + M.attempt.minutes + ' мин.' : '');
  } else if (training) {
    timerEl.hidden = true;
    A.$('.test-header__title').textContent = (/^Работа над ошибками/.test(M.attempt.title) ? '' : 'Тренировка · ') + M.attempt.title;
    A.$('#finish').textContent = 'Закончить тренировку';
    A.$('#autosave').textContent = 'Тренировка без оценки: после ответа нажмите «Проверить»';
  } else {
    var tick = function () {
      var left = M.attempt.minutes * 60 - Math.floor((Date.now() - st.startedAt) / 1000);
      if (left <= 0) { finish(true); return; }
      var m = Math.floor(left / 60), s = left % 60;
      timerEl.lastChild.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
      timerEl.classList.toggle('is-low', left < 300);
      if (left <= 300 && !warned5) { warned5 = true; A.toast('Осталось 5 минут. Ответы сохраняются автоматически'); }
      if (left <= 60 && !warned1) { warned1 = true; A.toast('Осталась 1 минута — тест завершится сам', true); }
    };
    var warned5 = M.attempt.minutes * 60 - Math.floor((Date.now() - st.startedAt) / 1000) <= 300, warned1 = false;
    if (M.attempt.minutes > 0) {
      tick();
      tickTimer = setInterval(tick, 1000);
    } else timerEl.hidden = true;
  }

  // Не теряем последние ответы при уходе со страницы
  window.addEventListener('pagehide', flushOnLeave);
  window.addEventListener('beforeunload', flushOnLeave);
  // Переключился на другую вкладку — сохраняем сразу, вдруг дальше закроет браузер
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden' && saveTimer && !submitted) flush(); });

  /* ---------- Жёсткий прокторинг ----------
     Уход со страницы — другая вкладка, свёрнутое или другое окно, закрытая страница — прячет вопросы
     и записывается в попытку: преподаватель видит каждый уход, когда он был и сколько длился. */
  if (M.attempt.proctoring && !training && M.attempt.attemptId && Q.length) proctoring();

  function proctoring() {
    var id = M.attempt.attemptId, count = M.attempt.proctorCount || 0;
    var ackKey = 'proctor-ok-' + M.attempt.testId;
    var acked = false;
    try { acked = sessionStorage.getItem(ackKey) === '1'; } catch (e) { /* хранилище недоступно — покажем правила */ }
    var out = false, closing = false, reportTimer, pending = null;
    var title = document.title;

    var screen = document.createElement('div');
    screen.className = 'proctor';
    screen.hidden = true;
    screen.setAttribute('role', 'alertdialog');
    screen.setAttribute('aria-modal', 'true');
    screen.setAttribute('aria-labelledby', 'proctor-title');
    screen.setAttribute('aria-describedby', 'proctor-text');
    document.body.appendChild(screen);

    // Запрос с keepalive: браузер доставит его, даже если вкладку как раз закрывают
    function post(path, body) {
      try {
        return fetch('/api/attempts/' + id + '/' + path, {
          method: 'POST', keepalive: true, credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': A.csrf(), 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify(body || {})
        }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
      } catch (e) { return Promise.resolve(null); }
    }

    var main = A.$('main.page'), lastFocus = null;
    function show(html) {
      // Где был фокус (например, в поле ответа) — туда и вернёмся
      if (screen.hidden && main.contains(document.activeElement)) lastFocus = document.activeElement;
      screen.innerHTML = '<div class="proctor__box">' + html + '</div>';
      screen.hidden = false;
      document.body.classList.add('is-proctor-locked');
      main.inert = true;
      focusButton();
    }
    function focusButton() {
      var b = screen.querySelector('button');
      if (b && document.hasFocus()) b.focus();
    }
    function unlock() {
      screen.hidden = true;
      document.body.classList.remove('is-proctor-locked');
      main.inert = false;
      (lastFocus && lastFocus.isConnected ? lastFocus : A.$('#q-text')).focus({ preventScroll: true });
      lastFocus = null;
    }
    // Студент на странице и видит вопросы: незакрытые уходы (закрытая вкладка, другая вкладка теста) отмечаются возвращёнными
    function present() {
      if (acked && !out && document.hasFocus() && document.visibilityState === 'visible') post('proctor/back');
    }

    function rules() {
      show('<div class="proctor__icon proctor__icon--info" aria-hidden="true">' + A.icons.info + '</div>' +
        '<h2 class="proctor__title" id="proctor-title">В этом тесте жёсткий прокторинг</h2>' +
        '<div id="proctor-text"><ul class="proctor__rules">' +
          '<li>Не переключайтесь на другие вкладки, не сворачивайте браузер и не переходите в другие окна и программы.</li>' +
          '<li>Не закрывайте и не перезагружайте страницу теста — это тоже считается уходом.</li>' +
          '<li>Если уйти со страницы, вопросы и варианты ответа скроются, а уход запишется: преподаватель увидит, когда и на сколько вы уходили.</li>' +
          (M.attempt.minutes > 0 ? '<li>Время теста идёт, даже пока вопросы скрыты.</li>' : '') +
        '</ul></div>' +
        '<button type="button" class="btn btn--primary" data-proctor-ok>Понятно, начать тест</button>');
    }
    function warning() {
      show('<div class="proctor__icon" aria-hidden="true">' + A.icons.cross + '</div>' +
        '<h2 class="proctor__title" id="proctor-title">Вы ушли со страницы теста</h2>' +
        '<p class="proctor__text" id="proctor-text">Вопросы скрыты. Уход записан, преподаватель его увидит.<br>' +
          'Уходов за эту попытку: <b>' + count + '</b>.</p>' +
        '<button type="button" class="btn btn--primary" data-proctor-ok>Вернуться к тесту</button>');
    }

    screen.addEventListener('click', function (e) {
      if (!e.target.closest('[data-proctor-ok]') || out) return;
      if (!acked) {
        acked = true;
        try { sessionStorage.setItem(ackKey, '1'); } catch (err) { /* без памяти правила покажутся снова после перезагрузки */ }
      }
      unlock();
      present();
    });

    function report(kind) {
      clearTimeout(reportTimer);
      reportTimer = null;
      pending = post('proctor', { kind: kind });
    }
    function leave(hidden) {
      if (!acked || submitted || closing) return;
      if (!out) {
        out = true;
        count++;
        document.title = '⚠ Вернитесь к тесту';
        // Открытое окно («Завершить тест?») лежит поверх всего — закрываем, чтобы предупреждение было видно
        A.$$('dialog[open] [data-cancel]').forEach(function (b) { b.click(); });
        warning();
        // Сначала окно теряет фокус, а скрытие вкладки приходит следом — ждём, чтобы записать точный вид ухода
        if (!hidden) reportTimer = setTimeout(function () { report(document.visibilityState === 'hidden' ? 'hidden' : 'blur'); }, 300);
      }
      if (hidden && reportTimer) report('hidden');
      else if (hidden && !pending) report('hidden');
    }
    function back() {
      if (!out || document.visibilityState !== 'visible' || !document.hasFocus()) return;
      out = false;
      document.title = title;
      if (reportTimer) report('blur');
      // Возврат отмечаем после записи ухода, иначе отмечать будет нечего
      var p = pending || Promise.resolve();
      pending = null;
      p.then(function () { post('proctor/back'); });
      focusButton();
    }

    window.addEventListener('blur', function () { leave(false); });
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') leave(true); else back();
    });
    window.addEventListener('focus', back);
    // Закрытие или перезагрузка страницы — тоже уход; время возврата отметится, когда тест откроют снова
    window.addEventListener('beforeunload', function () {
      if (closing || !acked || submitted) return;
      closing = true;
      if (!out) post('proctor', { kind: 'close' });
      // Уход отменили (страница жива через полторы секунды) — надзор продолжается, а «уход» закрываем
      setTimeout(function () { closing = false; present(); }, 1500);
    });
    window.addEventListener('pageshow', function (e) { if (e.persisted) { closing = false; present(); } });

    // Открыли заново после закрытия: запрос об уходе мог дойти позже, чем страница, — отмечаем возврат ещё раз
    setTimeout(present, 2000);

    // Правила — до первого вопроса; страница без фокуса (открыта в фоновой вкладке) тоже начинается с них
    if (!acked || !document.hasFocus()) rules();
  }

  if (!Q.length) {
    A.$('#q-text').textContent = /^Работа над ошибками/.test(M.attempt.title) ? 'Ошибок нет — повторять нечего' : 'В тесте пока нет вопросов';
    A.$('#q-label').textContent = '';
    ['#prev', '#next', '#flag'].forEach(function (s) { A.$(s).hidden = true; });
    return;
  }
  render();
})();
