/* Экран 2. Загрузка материалов и параметры генерации.
   Файлы уходят на POST /api/materials (с прогрессом), запуск агентов — POST /api/generation-runs. */
(function () {
  var A = window.App, M = A.M, esc = A.esc;

  // Материалы этого набора: { name, kind, size, info, progress, id, noText, ocr }
  var files = [];

  function kindOf(name) {
    var ext = (name.split('.').pop() || '').toUpperCase();
    return ['PDF', 'DOCX', 'PPTX', 'TXT', 'MP4'].indexOf(ext) >= 0 ? ext : 'FILE';
  }
  function sizeOf(bytes) { return bytes < 1048576 ? Math.max(1, Math.round(bytes / 1024)) + ' КБ' : (bytes / 1048576).toFixed(1).replace('.', ',') + ' МБ'; }

  /* ---------- Загруженные материалы курса ---------- */
  var library = (M.materials || []).slice(), librarySig = null;
  var PAGE = 15;
  // Поиск, вкладка, тип файла, порядок и сколько строк показано; вкладку и порядок помним между заходами
  var lf = { q: '', show: A.recall('materials.show', 'all'), kind: '', sort: A.recall('materials.sort', 'new'), limit: PAGE };
  var SORTS = {
    new: [function (m) { return m.createdTs; }, 'desc'],
    old: [function (m) { return m.createdTs; }, 'asc'],
    title: [function (m) { return m.title; }, 'asc'],
    questions: [function (m) { return m.questionCount || 0; }, 'desc'],
    size: [function (m) { return m.sizeBytes; }, 'desc']
  };
  if (!SORTS[lf.sort]) lf.sort = 'new';
  if (['all', 'used', 'fresh', 'problem'].indexOf(lf.show) < 0) lf.show = 'all';
  function problem(m) { return m.status === 'error' || !m.hasText; }
  function kindsOf() { return library.map(function (m) { return m.kind; }).filter(function (k, i, a) { return k && a.indexOf(k) === i; }).sort(); }
  // Фильтр, которым нельзя управлять (панель скрыта, тип исчез, вкладки нет), не должен прятать материалы
  function normalize() {
    var kinds = kindsOf();
    if (kinds.length < 2 || kinds.indexOf(lf.kind) < 0) lf.kind = '';
    if (lf.show === 'problem' && !library.some(problem)) lf.show = 'all';
    if (library.length < 2) { lf.show = 'all'; lf.q = ''; A.$('#lib-search').value = ''; }
  }
  function resetFilters() {
    lf.q = ''; lf.kind = ''; lf.show = 'all'; lf.limit = PAGE;
    A.$('#lib-search').value = '';
    A.remember('materials.show', 'all');
  }
  function inTab(m, tab) {
    return tab === 'all' || (tab === 'problem' ? problem(m) : !problem(m) && (tab === 'used' ? m.questionCount > 0 : !m.questionCount));
  }
  // Подходит под поиск и тип — от этого считаются числа на вкладках
  function searched() {
    var q = lf.q.trim().toLowerCase();
    return library.filter(function (m) { return (!q || m.title.toLowerCase().indexOf(q) >= 0) && (!lf.kind || m.kind === lf.kind); });
  }
  function filtersOn() { return !!(lf.q.trim() || lf.kind || lf.show !== 'all'); }

  function renderTools() {
    A.$('#lib-tools').hidden = library.length < 2;
    var base = searched(), count = function (t) { return base.filter(function (m) { return inTab(m, t); }).length; };
    var tabs = [['all', 'Все', base.length], ['used', 'С вопросами', count('used')], ['fresh', 'Без вопросов', count('fresh')]];
    if (library.some(problem)) tabs.push(['problem', 'Не прочитаны', count('problem')]);
    A.$('#lib-filters').innerHTML = A.filterTabs(tabs, lf.show, 'Какие материалы показать');
    // Тип файла — только когда в библиотеке их несколько
    var kinds = kindsOf(), sel = A.$('#lib-kind');
    sel.innerHTML = '<option value="">Все типы</option>' + kinds.map(function (k) { return '<option value="' + esc(k) + '">' + esc(k === 'URL' ? 'Ссылки' : k) + '</option>'; }).join('');
    sel.value = lf.kind;
    sel.hidden = kinds.length < 2;
    A.$('#lib-sort').value = lf.sort;
  }

  function renderLibrary() {
    // Прогресс загрузки перерисовывает набор часто; библиотеку — только когда изменился её состав или фильтры
    normalize();
    var sig = library.map(function (m) { return m.id + ':' + m.questionCount; }).join() + '|' + files.map(function (f) { return f.id || ''; }).join() + '|' + JSON.stringify(lf);
    if (sig === librarySig) return;
    librarySig = sig;
    A.$('#library-count').textContent = library.length ? library.length + ' ' + A.plural(library.length, 'материал', 'материала', 'материалов') : '';
    renderTools();
    var all = A.sortBy(searched().filter(function (m) { return inTab(m, lf.show); }), SORTS[lf.sort][0], SORTS[lf.sort][1]);
    var page = all.slice(0, lf.limit);
    A.$('#lib-result').textContent = filtersOn() && library.length > 1 ? 'Найдено: ' + all.length + ' из ' + library.length : '';
    A.$('#lib-more-row').hidden = page.length >= all.length;
    A.$('#lib-shown').textContent = 'Показано ' + page.length + ' из ' + all.length;
    A.$('#lib-more').textContent = 'Показать ещё ' + Math.min(PAGE, all.length - page.length);
    if (!all.length && library.length) {
      A.$('#library').innerHTML = '<div class="empty">Под фильтры ничего не подходит. <button type="button" class="link-btn" data-reset>Сбросить фильтры</button></div>';
      return;
    }
    A.$('#library').innerHTML = page.map(function (m) {
      var inSet = files.some(function (f) { return f.id === m.id; });
      var status = m.status === 'error' || !m.hasText ? A.chip('Текст не извлечён', 'red') : m.status === 'processing' ? A.chip('В обработке', 'blue')
        : m.questionCount ? A.chip(m.questionCount + ' ' + A.plural(m.questionCount, 'вопрос', 'вопроса', 'вопросов'), 'green') : A.chip('Вопросов нет');
      var items = [
        m.fileUrl ? { label: m.isLink ? 'Открыть страницу' : 'Открыть файл', href: m.fileUrl } : null,
        m.questionCount ? { label: 'Вопросы в банке', href: 'bank?status=all&source=' + m.id } : null,
        '-',
        { label: 'Удалить', attrs: 'data-delete-material="' + m.id + '"', danger: true }
      ];
      return '<div class="list-row" style="gap: 14px">' + A.fileIcon(m.kind, 40) +
        '<div class="list-row__main"><span class="strong">' + esc(m.title) + '</span><span class="list-row__meta">' +
          esc([m.size, m.meta && !/^(документ|презентация|текст)$/i.test(m.meta) ? m.meta : '', 'загружен ' + m.date].filter(Boolean).join(' · ')) + '</span></div>' +
        status +
        (m.hasText ? '<button type="button" class="btn btn--xs" data-use="' + m.id + '"' + (inSet ? ' disabled' : '') + '>' + (inSet ? 'В наборе' : 'В новый набор') + '</button>' : '') +
        A.menu('Действия с материалом «' + m.title + '»', items, 'material-' + m.id) + '</div>';
    }).join('') || '<div class="empty">Материалов пока нет — загрузите первую лекцию в форме выше.</div>';
  }
  function useMaterial(m) {
    var f = { name: m.title, size: '' };
    fromServer(f, m);
    files.push(f);
    render();
  }

  function fromServer(f, m) {
    f.id = m.id; f.kind = m.kind; f.info = m.meta; f.progress = 100;
    f.noText = !m.hasText;
    if (m.size) f.size = m.size;
    // Новый материал сразу появляется в списке курса
    if (m.id && !library.some(function (x) { return x.id === m.id; })) {
      library.unshift(m);
      // Только что загруженный материал должен быть виден, даже если включён фильтр
      if (!inTab(m, lf.show) || (lf.kind && m.kind !== lf.kind) || (lf.q.trim() && m.title.toLowerCase().indexOf(lf.q.trim().toLowerCase()) < 0)) resetFilters();
    }
  }

  function render() {
    A.$('#files').innerHTML = files.map(function (f, i) {
      if (f.error) {
        return '<div class="file-row file-row--err">' + A.fileIcon(f.kind, 36) +
          '<div class="file-row__main"><div class="strong">' + esc(f.name) + '</div><div class="t-red xsmall">' + esc(f.error) + '</div></div>' +
          '<button type="button" class="btn btn--icon" data-remove="' + i + '" aria-label="Убрать">' + A.icons.x + '</button></div>';
      }
      if (f.noText) {
        return '<div class="file-row file-row--err">' +
          A.fileIcon(f.kind, 36) +
          '<div class="file-row__main"><div class="strong">' + esc(f.name) + '</div><div class="t-red xsmall">' +
          esc(f.info || 'Текст не найден') + '. Агенты не смогут прочитать файл: загрузите версию с текстовым слоем (например, сохранённую из Word в PDF)' + '</div></div>' +
          '<button type="button" class="btn btn--icon" data-remove="' + i + '" aria-label="Убрать">' + A.icons.x + '</button>' +
        '</div>';
      }
      var done = f.progress >= 100 && f.id;
      return '<div class="file-row">' +
        A.fileIcon(f.kind, 36) +
        '<div class="file-row__main"><div class="strong">' + esc(f.name) + '</div>' +
          (done
            ? '<div class="muted xsmall">' + esc([f.info, f.size, 'текст распознан'].filter(Boolean).join(' · ')) + '</div>'
            : '<div class="progress" style="margin-top: 8px"><div style="width:' + f.progress + '%"></div></div>') +
        '</div>' +
        (done ? '<span class="chip chip--green">' + A.icons.check.replace(/18/g, '14') + 'Готов</span>'
              : '<span class="muted xsmall">Загрузка ' + Math.round(f.progress) + '%</span>') +
        '<button type="button" class="btn btn--icon" data-remove="' + i + '" aria-label="' + (done ? 'Убрать из набора' : 'Отменить загрузку') + '">' + A.icons.x + '</button>' +
      '</div>';
    }).join('') || '<div class="muted small">Файлы ещё не добавлены</div>';
    renderLibrary();
  }

  // Загрузка через XHR, чтобы показывать прогресс
  function upload(file) {
    var f = { name: file.name, kind: kindOf(file.name), size: sizeOf(file.size), progress: 0 };
    files.push(f);
    render();
    var fd = new FormData();
    fd.append('file', file);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/materials');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-CSRF-TOKEN', A.csrf());
    xhr.upload.onprogress = function (e) { if (e.lengthComputable) { f.progress = Math.min(99, e.loaded / e.total * 100); render(); } };
    xhr.onload = function () {
      if (A.authFailed(xhr.status)) return;
      var data = {};
      try { data = JSON.parse(xhr.responseText); } catch (e) {}
      if (xhr.status >= 200 && xhr.status < 300) fromServer(f, data);
      else f.error = (data.errors && data.errors.file && data.errors.file[0]) || data.message || 'Не удалось загрузить файл';
      render();
    };
    xhr.onerror = function () { f.error = 'Сеть недоступна'; render(); };
    f.xhr = xhr;
    xhr.send(fd);
  }

  function addFiles(list) { Array.prototype.forEach.call(list, upload); }

  A.$('#file-input').addEventListener('change', function (e) { addFiles(e.target.files); e.target.value = ''; });

  var dz = A.$('#dropzone');
  ['dragenter', 'dragover'].forEach(function (ev) {
    dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('is-over'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('is-over'); });
  });
  dz.addEventListener('drop', function (e) { addFiles(e.dataTransfer.files); });

  A.$('#files').addEventListener('click', function (e) {
    var rm = e.target.closest('[data-remove]');
    if (rm) {
      var f = files[+rm.getAttribute('data-remove')];
      if (f.xhr && f.progress < 100) f.xhr.abort();
      files.splice(+rm.getAttribute('data-remove'), 1);
      render();
    }
  });

  function addMaterial(payload, name, kind) {
    var f = { name: name, kind: kind, size: '', progress: 30 };
    files.push(f);
    render();
    A.api('POST', 'materials', payload).then(function (m) { fromServer(f, m); render(); })
      .catch(function (err) { f.error = err.message; render(); });
  }

  A.$$('[data-add]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-add');
      if (t === 'URL') {
        A.ask({ title: 'Материал по ссылке', label: 'Адрес страницы', placeholder: 'https://…', inputmode: 'url', maxlength: 1000, ok: 'Добавить',
          validate: function (v) { return !/^https?:\/\/\S+\.\S+/.test(v) ? 'Нужна ссылка, которая начинается с http:// или https://' : ''; } })
          .then(function (link) { if (link) addMaterial({ url: link }, link, 'URL'); });
      } else if (t === 'TXT') {
        A.ask({ title: 'Текст материала', label: 'Вставьте текст лекции или конспекта', multiline: true, maxlength: 500000, ok: 'Добавить',
          validate: function (v) { return v.length < 100 ? 'Слишком мало текста: агентам нужен хотя бы абзац-другой' : ''; } })
          .then(function (text) { if (text) addMaterial({ text: text, title: 'Текст из буфера обмена' }, 'Текст из буфера обмена', 'TXT'); });
      } else {
        // Из уже загруженных: берём материалы курса, которых ещё нет в наборе
        var have = files.map(function (f) { return f.id; });
        var rest = library.filter(function (m) { return have.indexOf(m.id) < 0 && m.hasText; });
        if (!rest.length) { A.toast('Все материалы курса уже в наборе'); return; }
        A.choose({ title: 'Материал из загруженных', ok: 'Добавить в набор',
          options: rest.map(function (m) { return { value: m.id, label: m.title, hint: [m.kind, m.size, 'загружен ' + m.date].filter(Boolean).join(' · ') }; }) })
          .then(function (id) {
            var m = rest.filter(function (x) { return String(x.id) === String(id); })[0];
            if (m) { var f = { name: m.title, size: '' }; fromServer(f, m); files.push(f); render(); }
          });
      }
    });
  });


  /* ---------- Поиск, вкладки, тип и порядок библиотеки ---------- */
  function refilter() { lf.limit = PAGE; renderLibrary(); }
  A.$('#lib-search').addEventListener('input', function (e) { lf.q = e.target.value; refilter(); });
  A.$('#lib-filters').addEventListener('click', function (e) {
    var b = e.target.closest('[data-filter]');
    if (!b) return;
    lf.show = b.getAttribute('data-filter');
    A.remember('materials.show', lf.show);
    A.keepFocus(refilter);
  });
  A.$('#lib-kind').addEventListener('change', function (e) { lf.kind = e.target.value; refilter(); });
  A.$('#lib-sort').addEventListener('change', function (e) { lf.sort = e.target.value; A.remember('materials.sort', lf.sort); refilter(); });
  // «Показать ещё»: фокус — на первую новую строку, кнопка может исчезнуть
  A.$('#lib-more').addEventListener('click', function () {
    var prev = lf.limit;
    lf.limit += PAGE;
    renderLibrary();
    var row = A.$$('#library .list-row')[prev], t = row && row.querySelector('button:not([disabled]), a');
    if (t) t.focus();
  });

  A.$('#library').addEventListener('click', function (e) {
    if (e.target.closest('[data-reset]')) {
      resetFilters();
      renderLibrary();
      (A.$('#lib-tools').hidden ? A.$('#to-library') : A.$('#lib-search')).focus();
      return;
    }
    var u = e.target.closest('[data-use]');
    if (u) {
      useMaterial(library.filter(function (m) { return m.id === +u.getAttribute('data-use'); })[0]);
      A.$('#new-set').scrollIntoView({ behavior: 'smooth' });
      A.toast('Материал добавлен в новый набор');
      return;
    }
    var d = e.target.closest('[data-delete-material]');
    if (!d) return;
    var m = library.filter(function (x) { return x.id === +d.getAttribute('data-delete-material'); })[0];
    if (m.questionCount) { A.toast('По материалу уже есть вопросы в банке — удалить его нельзя', true); return; }
    A.confirm({ title: 'Удалить материал?', text: '«' + m.title + '» будет удалён вместе с файлом.', ok: 'Удалить', danger: true }).then(function (yes) {
      if (!yes) return;
      A.api('DELETE', 'materials/' + m.id).then(function () {
        library = library.filter(function (x) { return x.id !== m.id; });
        files = files.filter(function (f) { return f.id !== m.id; });
        render();
        A.toast('Материал удалён');
      });
    });
  });

  // Пришли из карточки материала («Сгенерировать ещё») — материал уже в наборе
  var pre = A.param('material') && library.filter(function (m) { return String(m.id) === A.param('material') && m.hasText; })[0];
  if (pre) { useMaterial(pre); setTimeout(function () { A.$('#new-set').scrollIntoView(); }, 0); }

  A.$('#gen-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var ready = files.filter(function (f) { return f.id && !f.noText && !f.error; });
    if (!ready.length) { A.toast('Добавьте хотя бы один готовый материал'); return; }
    var types = A.$$('input[name="types[]"]:checked').map(function (c) { return c.value; });
    if (!types.length) { A.toast('Выберите хотя бы один тип вопросов'); return; }

    var form = e.target;
    var btn = form.querySelector('[type=submit]');
    btn.disabled = true;
    btn.textContent = 'Агенты работают…';
    btn.setAttribute('aria-busy', 'true');
    A.api('POST', 'generation-runs', {
      material_ids: ready.map(function (f) { return f.id; }),
      types: types,
      count: +form.count.value || 12,
      difficulty: form.difficulty.value,
      bloom: form.bloom.value,
      lang: form.lang.value,
      notes: form.notes.value
    }).then(function (run) {
      location.href = 'pipeline?run=' + run.id;
    }).catch(function () {
      btn.disabled = false;
      btn.removeAttribute('aria-busy');
      btn.textContent = 'Запустить агентов';
    });
  });

  render();
})();
