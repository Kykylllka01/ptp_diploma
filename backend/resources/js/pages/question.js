/* Экран 5. Проверка и редактирование вопроса (question?id=Q-121) */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var LETTERS = 'АБВГДЕЖЗ';
  var CHOICE = ['single', 'multiple', 'tf'];

  var all = A.questions();
  var q = all.filter(function (x) { return x.id === A.param('id'); })[0] ||
          all.filter(function (x) { return x.status === 'pending'; })[0] || all[0];
  if (!q) {
    A.$('main').innerHTML = '<section class="card card--pad empty">Вопросов пока нет. <a href="upload">Загрузите материал</a> — агенты составят вопросы для банка.</section>';
    return;
  }
  if (A.param('id') && q.id !== A.param('id')) A.toast('Вопрос ' + A.param('id') + ' не найден — открыт ' + q.id);
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
    var st = { pending: ['Ждёт одобрения', 'amber'], approved: ['Одобрен', 'green'], rejected: ['Отклонён', 'red'] }[draft.status];
    A.$('#status-chip').innerHTML = A.chip(st[0], st[1]);
    // Одобренный вопрос правят и просто сохраняют; «далее» — только в очереди на проверку
    A.$('#approve').hidden = draft.status === 'approved';
    A.$('#approve').textContent = draft.status === 'pending' ? 'Одобрить и далее' : 'Одобрить';
    A.$('#reject').hidden = draft.status === 'rejected';
    A.$('#save').classList.toggle('btn--primary', draft.status === 'approved');
    A.$('#back').href = 'bank?status=' + (q.status || 'all');
  }

  // Критерии развёрнутого ответа: [текст, баллы]; балл вопроса = сумма критериев
  function renderCriteria() {
    var isOpen = draft.type === 'open';
    A.$('#criteria-box').hidden = !isOpen;
    A.$('#points').readOnly = isOpen;
    if (!isOpen) return;
    draft.criteria = draft.criteria || [];
    A.$('#criteria').innerHTML = draft.criteria.map(function (c, i) {
      return '<div class="ans-edit"><span class="mono muted">' + (i + 1) + '</span>' +
        '<input type="text" data-crit="' + i + '" value="' + esc(c[0]) + '" aria-label="Критерий ' + (i + 1) + '">' +
        '<input type="number" class="input" style="width: 76px; min-height: 36px" min="1" max="20" data-crit-points="' + i + '" value="' + c[1] + '" aria-label="Баллы за критерий ' + (i + 1) + '">' +
        '<button type="button" class="btn btn--icon" data-crit-del="' + i + '" aria-label="Удалить критерий ' + (i + 1) + '">' + A.icons.x + '</button></div>';
    }).join('') || '<div class="muted small">Критериев нет — добавьте хотя бы один</div>';
    syncPoints();
  }
  function syncPoints() {
    if (draft.type !== 'open') return;
    var sum = (draft.criteria || []).reduce(function (s, c) { return s + (+c[1] || 0); }, 0);
    if (sum) { draft.points = sum; A.$('#points').value = sum; }
  }

  function renderOptions() {
    var isChoice = CHOICE.indexOf(draft.type) >= 0, isMatch = draft.type === 'match';
    A.$('#options-box').hidden = !isChoice && !isMatch;
    A.$('#reference-box').hidden = isChoice || isMatch;
    A.$('#reference').value = draft.reference || '';
    A.$('#reference-label').textContent = draft.type === 'sql' ? 'Эталонный запрос (учебная база: ' + (draft.sqlSchema || 'groups, students') + ')' : 'Эталонный ответ';
    A.$('#sql-tools').hidden = draft.type !== 'sql';
    A.$('#run-ref-result').innerHTML = '';
    renderCriteria();
    if (isMatch) {
      // Пары «термин — определение»; лишнее определение начинается с «Лишнее:»
      A.$('#options-hint').textContent = '· строки «термин — определение», лишнее определение начните с «Лишнее:»';
      A.$('#add-option').hidden = false;
      A.$('#options').innerHTML = draft.options.map(function (o, i) {
        return '<div class="ans-edit"><span class="mono muted">' + (i + 1) + '</span>' +
          '<input type="text" data-opt="' + i + '" value="' + esc(o.text) + '" aria-label="Пара ' + (i + 1) + '">' +
          '<button type="button" class="btn btn--icon" data-del="' + i + '" aria-label="Удалить строку ' + (i + 1) + '">' + A.icons.x + '</button></div>';
      }).join('');
      return;
    }
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
      ? c.checks.map(function (ch) { return '<li>' + (ch.ok ? A.icons.ok : A.icons.warn) + '<span><span class="sr-only">' + (ch.ok ? 'Пройдено: ' : 'Замечание: ') + '</span>' + esc(ch.text) + '</span></li>'; }).join('')
      : '<li>' + A.icons.info + '<span>Критик этот вопрос не проверял: он создан или изменён вручную.</span></li>';
    A.$('#apply-fix').hidden = !(c && c.fix && !draft.fixApplied);
    A.$('#source').textContent = draft.source;
    if (draft.materialId) A.$('#source').href = 'bank?status=all&source=' + draft.materialId;
    A.$('#quote').innerHTML = draft.quote || 'Фрагмент источника появится после разбора материала.';
    A.$('#history').innerHTML = history.map(function (h) {
      return '<div><span class="mono muted">' + h[0] + '</span> ' + esc(h[1]) + '</div>';
    }).join('');
  }

  function time() { var d = new Date(); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }

  function collect() {
    draft.text = A.$('#text').value.trim();
    draft.context = A.$('#context').value.replace(/\s+$/, '');
    if (draft.type === 'match') draft.options.forEach(function (o) { o.correct = !/^Лишнее:/.test(o.text.trim()); });
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
    if (draft.type === 'open') {
      if (!(draft.criteria || []).length) return 'Добавьте хотя бы один критерий оценки';
      if (draft.criteria.some(function (c) { return !String(c[0]).trim() || !(+c[1] > 0); })) return 'У каждого критерия нужны текст и балл';
      if (!String(draft.reference || '').trim()) return 'Заполните эталонный ответ';
    }
    if (draft.type === 'sql' && !String(draft.reference || '').trim()) return 'Заполните эталонный запрос';
    if (draft.type === 'match') {
      draft.options.forEach(function (o) { if (o.correct) o.text = o.text.replace(/\s+[–-]\s+/, ' — '); });
      if (draft.options.some(function (o) { return o.correct && o.text.indexOf(' — ') < 0; })) return 'Пары записывайте как «термин — определение» (подойдёт и обычный дефис с пробелами)';
      if (draft.options.filter(function (o) { return o.correct; }).length < 2) return 'Нужно минимум две пары';
    }
    return '';
  }

  function save(status) {
    var patch = {
      status: status, topic: draft.topic, text: draft.text, context: draft.context || null, type: draft.type, difficulty: draft.difficulty, points: draft.points,
      options: CHOICE.indexOf(draft.type) >= 0 || draft.type === 'match' ? draft.options.map(function (o) { return o.correct ? { text: o.text, correct: true } : { text: o.text }; }) : null,
      explanation: draft.explanation, reference: draft.reference, tags: draft.tags,
      criteria: draft.type === 'open' ? draft.criteria.map(function (c) { return [String(c[0]).trim(), +c[1]]; }) : undefined
    };
    if (!status) delete patch.status;
    if (draft.fixApplied) { patch.fixApplied = true; patch.critic = draft.critic; }
    return A.saveQuestion(q.id, patch).then(function (fresh) { setDirty(false); return fresh; });
  }

  function goNext() {
    var next = A.questions().filter(function (x) { return x.status === 'pending' && x.id !== q.id; })[0];
    location.href = next ? 'question?id=' + next.id : 'bank?status=approved';
  }

  // --- начальная отрисовка
  fillSelect(A.$('#type'), M.types, draft.type);
  fillSelect(A.$('#difficulty'), M.difficulties, draft.difficulty);
  var topics = {};
  (M.topics || []).concat(draft.topic && (M.topics || []).indexOf(draft.topic) < 0 ? [draft.topic] : []).forEach(function (t) { topics[t] = t; });
  fillSelect(A.$('#topic'), topics, draft.topic);
  A.$('#topic').addEventListener('change', function (e) { draft.topic = e.target.value; });

  // Несохранённые правки: подпись у кнопок и предупреждение при уходе со страницы
  var dirty = false;
  function setDirty(v) {
    dirty = v;
    A.$('#dirty').textContent = v ? 'Есть несохранённые правки' : '';
  }
  A.$('#form').addEventListener('input', function () { setDirty(true); });
  A.$('#form').addEventListener('change', function () { setDirty(true); });
  A.$('#form').addEventListener('click', function (e) { if (e.target.closest('[data-del], [data-crit-del], [data-tag], #add-option, #add-criterion')) setDirty(true); });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  function invalid(err) {
    A.toast(err, true);
    var field = /Формулировка/.test(err) ? '#text' : /эталон/i.test(err) ? '#reference' : null;
    if (field) A.$(field).focus();
  }
  A.$('#points').value = draft.points || 1;
  A.$('#text').value = draft.text;
  A.$('#context').value = draft.context || '';
  A.$('#context').rows = Math.min(14, Math.max(3, (draft.context || '').split('\n').length + 1));
  A.$('#explanation').value = draft.explanation || '';
  renderHead(); renderOptions(); renderTags(); renderSide();

  // --- события
  A.$('#type').addEventListener('change', function (e) {
    draft.type = e.target.value;
    if (draft.type === 'tf') draft.options = [{ text: 'Верно' }, { text: 'Неверно', correct: true }];
    else if (CHOICE.indexOf(draft.type) >= 0 && draft.options.length < 2) draft.options = [{ text: '', correct: true }, { text: '' }, { text: '' }, { text: '' }];
    else if (draft.type === 'match' && !draft.options.some(function (o) { return / — /.test(o.text); })) draft.options = [{ text: 'Термин 1 — определение 1', correct: true }, { text: 'Термин 2 — определение 2', correct: true }, { text: 'Лишнее: правдоподобное определение' }];
    if (draft.type === 'open' && !(draft.criteria || []).length) draft.criteria = [['Раскрыто основное понятие', 2], ['Приведён верный пример', 2]];
    renderOptions();
  });
  A.$('#difficulty').addEventListener('change', function (e) { draft.difficulty = e.target.value; });

  A.$('#criteria').addEventListener('input', function (e) {
    var t = e.target.getAttribute('data-crit'), p = e.target.getAttribute('data-crit-points');
    if (t !== null) draft.criteria[+t][0] = e.target.value;
    if (p !== null) { draft.criteria[+p][1] = Math.max(1, parseInt(e.target.value, 10) || 1); syncPoints(); }
  });
  A.$('#criteria').addEventListener('click', function (e) {
    var d = e.target.closest('[data-crit-del]');
    if (d) { draft.criteria.splice(+d.getAttribute('data-crit-del'), 1); renderCriteria(); }
  });
  A.$('#add-criterion').addEventListener('click', function () {
    draft.criteria = (draft.criteria || []).concat([['', 1]]);
    renderCriteria();
    var inputs = A.$$('#criteria input[type=text]');
    inputs[inputs.length - 1].focus();
  });

  // Эталонный SQL выполняется в той же учебной базе, где проверяются ответы студентов
  A.$('#run-ref').addEventListener('click', function () {
    var sql = A.$('#reference').value.trim(), status = A.$('#run-ref-status'), out = A.$('#run-ref-result');
    if (!sql) { status.textContent = 'Эталон пуст'; return; }
    status.textContent = 'Выполняем…';
    A.api('POST', 'sql/run', { sql: sql }).then(function (r) {
      if (!r.ok) { status.textContent = 'Ошибка: ' + r.error; out.innerHTML = ''; return; }
      status.textContent = 'Выполнен · ' + r.rows.length + ' ' + A.plural(r.rows.length, 'строка', 'строки', 'строк');
      out.innerHTML = '<table><thead><tr>' + r.columns.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('') + '</tr></thead><tbody>' +
        r.rows.map(function (row) { return '<tr>' + row.map(function (v) { return '<td>' + esc(v) + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table>';
    });
  });

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
      A.ask({ title: 'Новый тег', label: 'Тег', placeholder: 'Например, 3НФ', maxlength: 60, ok: 'Добавить' }).then(function (v) {
        if (v) { draft.tags = (draft.tags || [draft.topic]).concat(v); renderTags(); setDirty(true); }
      });
    }
  });

  A.$('#apply-fix').addEventListener('click', function () {
    var fix = draft.critic.fix, old = draft.options[fix.optionIndex] || {};
    draft.options[fix.optionIndex] = old.correct ? { text: fix.text, correct: true } : { text: fix.text };
    draft.fixApplied = true;
    draft.critic = Object.assign({}, draft.critic, { checks: draft.critic.checks.map(function (c) { return c.ok ? c : { ok: true, text: 'Учтено предложение критика: ' + c.text.charAt(0).toLowerCase() + c.text.slice(1) }; }) });
    history.push([time(), 'Применено предложение критика']);
    renderOptions(); renderSide();
    A.toast('Вариант ' + LETTERS[fix.optionIndex] + ' заменён на «' + fix.text + '»');
  });

  A.$('#approve').addEventListener('click', function () {
    var err = validate();
    if (err) { invalid(err); return; }
    var wasPending = q.status === 'pending';
    save('approved').then(function () {
      A.toast('Вопрос ' + q.id + ' одобрен');
      if (wasPending) setTimeout(goNext, 500);
      else { draft.status = 'approved'; renderHead(); }
    });
  });
  A.$('#save').addEventListener('click', function () {
    var err = validate();
    if (err) { invalid(err); return; }
    save(null).then(function () { A.toast('Изменения сохранены'); });
  });
  A.$('#reject').addEventListener('click', function () {
    collect();
    save('rejected').then(function () {
      A.toast('Вопрос ' + q.id + ' отклонён');
      setTimeout(goNext, 500);
    });
  });
  A.$('#regen').addEventListener('click', function () {
    var btn = A.$('#regen');
    (dirty ? A.confirm({ title: 'Перегенерировать вопрос?', text: 'Ваши несохранённые правки пропадут: Составитель перепишет вопрос заново.', ok: 'Перегенерировать', danger: true }) : Promise.resolve(true)).then(function (yes) {
      if (!yes) return;
      btn.disabled = true;
      btn.textContent = 'Переписываю…';
      A.toast('Составитель переписывает вопрос с учётом замечаний Критика — это до минуты');
      regen().then(function () { btn.disabled = false; btn.textContent = 'Перегенерировать'; }, function () { btn.disabled = false; btn.textContent = 'Перегенерировать'; });
    });
  });
  function regen() {
    return A.api('POST', 'questions/' + encodeURIComponent(q.id) + '/regenerate').then(function (fresh) {
      Object.assign(q, fresh);
      draft = JSON.parse(JSON.stringify(fresh));
      draft.options = draft.options || [];
      history = (draft.history || []).slice();
      A.$('#text').value = draft.text;
      A.$('#context').value = draft.context || '';
      A.$('#explanation').value = draft.explanation || '';
      renderHead(); renderOptions(); renderTags(); renderSide();
      setDirty(false);
      A.toast('Вопрос переписан, Критик проверил его заново');
    });
  }
  A.$('#form').addEventListener('submit', function (e) { e.preventDefault(); });

  // Удалить можно вопрос, который ещё нигде не использован (например, добавленный вручную по ошибке)
  A.$('#approve').insertAdjacentHTML('afterend', A.menu('Ещё действия с вопросом', [{ label: 'Удалить вопрос', attrs: 'data-delete-q', danger: true }]));
  document.addEventListener('click', function (e) {
    if (!e.target.closest('[data-delete-q]')) return;
    A.confirm({ title: 'Удалить вопрос ' + q.id + '?', text: 'Вопрос исчезнет из банка. Если он уже есть в тесте или на него отвечали студенты, удалить не получится — тогда отклоните его.', ok: 'Удалить', danger: true })
      .then(function (yes) {
        if (!yes) return;
        A.api('DELETE', 'questions/' + encodeURIComponent(q.id)).then(function () {
          setDirty(false);
          A.toast('Вопрос удалён');
          setTimeout(function () { location.href = 'bank?status=' + (q.status || 'all'); }, 400);
        });
      });
  });
})();
