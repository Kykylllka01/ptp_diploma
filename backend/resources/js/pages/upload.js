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
  function sizeOf(bytes) { return (bytes / 1048576).toFixed(1).replace('.', ',') + ' МБ'; }

  function fromServer(f, m) {
    f.id = m.id; f.kind = m.kind; f.info = m.meta; f.progress = 100;
    f.noText = !m.hasText;
    if (m.size) f.size = m.size;
  }

  function render() {
    A.$('#files').innerHTML = files.map(function (f, i) {
      if (f.error) {
        return '<div class="file-row file-row--err"><span class="filetype filetype--err">' + f.kind + '</span>' +
          '<div class="file-row__main"><div class="strong">' + esc(f.name) + '</div><div class="t-red xsmall">' + esc(f.error) + '</div></div>' +
          '<button type="button" class="btn btn--icon" data-remove="' + i + '" aria-label="Убрать">' + A.icons.x + '</button></div>';
      }
      if (f.noText) {
        return '<div class="file-row file-row--err">' +
          '<span class="filetype filetype--err">' + f.kind + '</span>' +
          '<div class="file-row__main"><div class="strong">' + esc(f.name) + '</div><div class="t-red xsmall">' +
          esc(f.info || 'Текст не найден') + '. Агенты не смогут прочитать файл: загрузите версию с текстовым слоем (например, сохранённую из Word в PDF)' + '</div></div>' +
          '<button type="button" class="btn btn--icon" data-remove="' + i + '" aria-label="Убрать">' + A.icons.x + '</button>' +
        '</div>';
      }
      var done = f.progress >= 100 && f.id;
      return '<div class="file-row">' +
        '<span class="filetype">' + f.kind + '</span>' +
        '<div class="file-row__main"><div class="strong">' + esc(f.name) + '</div>' +
          (done
            ? '<div class="muted xsmall">' + (f.info ? esc(f.info) + ' · ' : '') + (f.size || '') + ' · текст распознан</div>'
            : '<div class="progress" style="margin-top: 8px"><div style="width:' + f.progress + '%"></div></div>') +
        '</div>' +
        (done ? '<span class="chip chip--green">' + A.icons.check.replace(/18/g, '14') + 'Готов</span>'
              : '<span class="muted xsmall">Загрузка ' + Math.round(f.progress) + '%</span>') +
        '<button type="button" class="btn btn--icon" data-remove="' + i + '" aria-label="' + (done ? 'Убрать из набора' : 'Отменить загрузку') + '">' + A.icons.x + '</button>' +
      '</div>';
    }).join('') || '<div class="muted small">Файлы ещё не добавлены</div>';
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
        var link = prompt('Ссылка на страницу с материалом (https://…)');
        if (link) addMaterial({ url: link }, link, 'URL');
      } else if (t === 'TXT') {
        var text = prompt('Вставьте текст материала');
        if (text && text.trim()) addMaterial({ text: text, title: 'Текст из буфера обмена' }, 'Текст из буфера обмена', 'TXT');
      } else {
        // Из уже загруженных: берём материалы курса, которых ещё нет в наборе
        var have = files.map(function (f) { return f.id; });
        var rest = (M.materials || []).filter(function (m) { return have.indexOf(m.id) < 0 && m.hasText; });
        if (!rest.length) { A.toast('Все материалы курса уже в наборе'); return; }
        var list = rest.map(function (m, i) { return (i + 1) + '. ' + m.title; }).join('\n');
        var n = parseInt(prompt('Номер материала:\n' + list, '1'), 10);
        var m = rest[n - 1];
        if (m) { var f = { name: m.title, size: '' }; fromServer(f, m); files.push(f); render(); }
      }
    });
  });


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
      btn.textContent = 'Запустить агентов';
    });
  });

  render();
})();
