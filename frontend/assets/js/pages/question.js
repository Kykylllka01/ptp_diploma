/* Экран 5. Проверка и редактирование вопроса (question.html?id=Q-121) */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var LETTERS = 'АБВГДЕЖЗ';
  var CHOICE = ['single', 'multiple', 'tf'];

  var all = A.questions();
  var q = all.filter(function (x) { return x.id === A.param('id'); })[0] ||
          all.filter(function (x) { return x.status === 'pending'; })[0] || all[0];
  // Рабочая копия, чтобы правки не терялись до сохранения
  var draft = JSON.parse(JSON.stringify(q));
  draft.options = draft.options || [];
  var history = (draft.history || [['—', 'Вопрос из банка']]).slice();

  var pending = all.filter(function (x) { return x.status === 'pending'; });
  var idx = pending.map(function (x) { return x.id; }).indexOf(q.id);

  function fillSelect(el, dict, val) {
    el.innerHTML = Object.keys(dict).map(function (k) {
      return '<option value="' + k + '"' + (k === val ? ' selected' : '') + '>' + dict[k] + '</option>';
    }).join('');
  }

  function renderHead() {
    A.$('#pos').textContent = q.id + (idx >= 0 ? ' · ' + (idx + 1) + ' из ' + pending.length : '');
    var st = { pending: ['На проверке', 'amber'], approved: ['Одобрен', 'green'], rejected: ['Отклонён', 'red'] }[draft.status];
    A.$('#status-chip').innerHTML = A.chip(st[0], st[1]);
  }

  function renderOptions() {
    var isChoice = CHOICE.indexOf(draft.type) >= 0;
    A.$('#options-box').hidden = !isChoice;
    A.$('#reference-box').hidden = isChoice;
    A.$('#reference').value = draft.reference || '';
    if (!isChoice) return;
    var multi = draft.type === 'multiple';
    A.$('#options-hint').textContent = multi ? '· отметьте все верные' : '· отметьте верный';
    A.$('#add-option').hidden = draft.type === 'tf';
    A.$('#options').innerHTML = draft.options.map(function (o, i) {
      var cls = o.correct ? ' is-correct' : (o.flag ? ' is-flag' : '');
      return '<div class="ans-edit' + cls + '">' +
        '<input type="' + (multi ? 'checkbox' : 'radio') + '" name="correct" data-correct="' + i + '"' + (o.correct ? ' checked' : '') + ' aria-label="Вариант ' + LETTERS[i] + ' верный">' +
        '<span class="mono muted">' + LETTERS[i] + '</span>' +
        '<input type="text" data-opt="' + i + '" value="' + esc(o.text) + '" aria-label="Текст варианта ' + LETTERS[i] + '">' +
        (o.correct ? A.chip('Верный', 'green') : o.flag ? A.chip(o.flag, 'red') : '') +
        (draft.type !== 'tf' ? '<button type="button" class="btn btn--icon" data-del="' + i + '" aria-label="Удалить вариант ' + LETTERS[i] + '">' + A.icons.x + '</button>' : '') +
      '</div>';
    }).join('');
  }

  function renderTags() {
    A.$('#tags').innerHTML = (draft.tags || [draft.topic]).map(function (t, i) {
      return '<button type="button" class="chip" data-tag="' + i + '" title="Убрать тег">' + esc(t) + ' ×</button>';
    }).join('') + '<button type="button" class="btn btn--pill" id="add-tag">+ тег</button>';
  }

  function renderSide() {
    var c = draft.critic;
    A.$('#critic-score').textContent = c ? c.score + ' / 10' : '';
    A.$('#critic').innerHTML = c
      ? c.checks.map(function (ch) { return '<li>' + (ch.ok ? A.icons.ok : A.icons.warn) + '<span>' + esc(ch.text) + '</span></li>'; }).join('')
      : '<li>' + A.icons.ok + '<span>Замечаний нет</span></li>';
    A.$('#apply-fix').hidden = !(c && c.fix && !draft.fixApplied);
    A.$('#source').textContent = draft.source;
    A.$('#quote').innerHTML = draft.quote || 'Фрагмент источника появится после разбора материала.';
    A.$('#history').innerHTML = history.map(function (h) {
      return '<div><span class="mono muted">' + h[0] + '</span> ' + esc(h[1]) + '</div>';
    }).join('');
  }

  function time() { var d = new Date(); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }

  function collect() {
    draft.text = A.$('#text').value.trim();
    draft.explanation = A.$('#explanation').value.trim();
    draft.reference = A.$('#reference').value;
    draft.points = +A.$('#points').value || 1;
  }

  function validate() {
    collect();
    if (!draft.text) return 'Формулировка не может быть пустой';
    if (CHOICE.indexOf(draft.type) >= 0) {
      if (draft.options.some(function (o) { return !o.text.trim(); })) return 'Заполните текст всех вариантов';
      var n = draft.options.filter(function (o) { return o.correct; }).length;
      if (!n) return 'Отметьте верный вариант';
      if (draft.type !== 'multiple' && n > 1) return 'В этом типе верный вариант только один';
    }
    return '';
  }

  function save(status) {
    var patch = {
      status: status, text: draft.text, type: draft.type, difficulty: draft.difficulty, points: draft.points,
      options: draft.options, explanation: draft.explanation, reference: draft.reference, tags: draft.tags,
      history: history.concat([[time(), status === 'approved' ? 'Преподаватель одобрил' : 'Преподаватель отклонил']])
    };
    if (draft.fixApplied) { patch.fixApplied = true; patch.critic = draft.critic; }
    A.saveQuestion(q.id, patch);
  }

  function goNext() {
    var next = A.questions().filter(function (x) { return x.status === 'pending' && x.id !== q.id; })[0];
    location.href = next ? 'question.html?id=' + next.id : 'bank.html?status=approved';
  }

  // --- начальная отрисовка
  fillSelect(A.$('#type'), M.types, draft.type);
  fillSelect(A.$('#difficulty'), M.difficulties, draft.difficulty);
  A.$('#points').value = draft.points || 1;
  A.$('#text').value = draft.text;
  A.$('#explanation').value = draft.explanation || '';
  renderHead(); renderOptions(); renderTags(); renderSide();

  // --- события
  A.$('#type').addEventListener('change', function (e) {
    draft.type = e.target.value;
    if (draft.type === 'tf') draft.options = [{ text: 'Верно' }, { text: 'Неверно', correct: true }];
    else if (CHOICE.indexOf(draft.type) >= 0 && draft.options.length < 2) draft.options = [{ text: '', correct: true }, { text: '' }, { text: '' }, { text: '' }];
    renderOptions();
  });
  A.$('#difficulty').addEventListener('change', function (e) { draft.difficulty = e.target.value; });

  A.$('#options').addEventListener('input', function (e) {
    var i = e.target.getAttribute('data-opt');
    if (i !== null) draft.options[+i].text = e.target.value;
  });
  A.$('#options').addEventListener('change', function (e) {
    var i = e.target.getAttribute('data-correct');
    if (i === null) return;
    if (draft.type === 'multiple') draft.options[+i].correct = e.target.checked;
    else draft.options.forEach(function (o, k) { o.correct = k === +i; });
    renderOptions();
  });
  A.$('#options').addEventListener('click', function (e) {
    var d = e.target.closest('[data-del]');
    if (!d) return;
    if (draft.options.length <= 2) { A.toast('Нужно минимум два варианта'); return; }
    draft.options.splice(+d.getAttribute('data-del'), 1);
    renderOptions();
  });
  A.$('#add-option').addEventListener('click', function () {
    if (draft.options.length >= LETTERS.length) return;
    draft.options.push({ text: '' });
    renderOptions();
    var inputs = A.$$('#options input[type=text]');
    inputs[inputs.length - 1].focus();
  });

  A.$('#tags').addEventListener('click', function (e) {
    var t = e.target.closest('[data-tag]');
    if (t) { draft.tags = (draft.tags || [draft.topic]).filter(function (_, i) { return i !== +t.getAttribute('data-tag'); }); renderTags(); }
    if (e.target.id === 'add-tag') {
      var v = prompt('Новый тег');
      if (v && v.trim()) { draft.tags = (draft.tags || [draft.topic]).concat(v.trim()); renderTags(); }
    }
  });

  A.$('#apply-fix').addEventListener('click', function () {
    var fix = draft.critic.fix;
    draft.options[fix.optionIndex] = { text: fix.text };
    draft.fixApplied = true;
    draft.critic = Object.assign({}, draft.critic, { score: 9, checks: draft.critic.checks.map(function (c) { return c.ok ? c : { ok: true, text: 'Вариант заменён — теперь верный ответ один' }; }) });
    history.push([time(), 'Применено предложение критика']);
    renderOptions(); renderSide();
    A.toast('Вариант Г заменён на «' + fix.text + '»');
  });

  A.$('#approve').addEventListener('click', function () {
    var err = validate();
    if (err) { A.toast(err); return; }
    save('approved');
    A.toast('Вопрос ' + q.id + ' одобрен');
    setTimeout(goNext, 500);
  });
  A.$('#reject').addEventListener('click', function () {
    collect();
    save('rejected');
    A.toast('Вопрос ' + q.id + ' отклонён');
    setTimeout(goNext, 500);
  });
  A.$('#regen').addEventListener('click', function () {
    history.push([time(), 'Отправлен Составителю на перегенерацию']);
    renderSide();
    A.toast('Составитель перепишет вопрос с учётом замечаний критика');
  });
  A.$('#form').addEventListener('submit', function (e) { e.preventDefault(); });
})();
