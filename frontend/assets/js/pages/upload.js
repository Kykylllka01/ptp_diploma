/* Экран 2. Загрузка материалов и параметры генерации */
(function () {
  var A = window.App, esc = A.esc;

  // Стартовый список — как в прототипе: готовый файл, файл в загрузке, скан без текста
  var files = [
    { name: 'Лекция 4. Нормализация отношений.pdf', kind: 'PDF', size: '6,1 МБ', info: '42 стр.', progress: 100 },
    { name: 'Семинар 4 — задачи на нормализацию.pptx', kind: 'PPTX', size: '3,4 МБ', info: '16 слайдов', progress: 62 },
    { name: 'Скан_учебник_гл7.pdf', kind: 'PDF', size: '11,8 МБ', progress: 100, noText: true }
  ];

  function kindOf(name) {
    var ext = (name.split('.').pop() || '').toUpperCase();
    return ['PDF', 'DOCX', 'PPTX', 'TXT', 'MP4'].indexOf(ext) >= 0 ? ext : 'FILE';
  }
  function sizeOf(bytes) { return (bytes / 1048576).toFixed(1).replace('.', ',') + ' МБ'; }

  function render() {
    A.$('#files').innerHTML = files.map(function (f, i) {
      if (f.noText) {
        return '<div class="file-row file-row--err">' +
          '<span class="filetype filetype--err">' + f.kind + '</span>' +
          '<div class="file-row__main"><div class="strong">' + esc(f.name) + '</div><div class="t-red xsmall">' +
          (f.ocr ? 'Распознаём текст…' : 'Нет текстового слоя — агент не сможет прочитать файл') + '</div></div>' +
          '<button type="button" class="btn btn--sm" data-ocr="' + i + '"' + (f.ocr ? ' disabled' : '') + '>Распознать (OCR)</button>' +
        '</div>';
      }
      var done = f.progress >= 100;
      return '<div class="file-row">' +
        '<span class="filetype">' + f.kind + '</span>' +
        '<div class="file-row__main"><div class="strong">' + esc(f.name) + '</div>' +
          (done
            ? '<div class="muted xsmall">' + (f.info ? esc(f.info) + ' · ' : '') + f.size + ' · текст распознан</div>'
            : '<div class="progress" style="margin-top: 8px"><div style="width:' + f.progress + '%"></div></div>') +
        '</div>' +
        (done ? '<span class="chip chip--green">' + A.icons.check.replace(/18/g, '14') + 'Готов</span>'
              : '<span class="muted xsmall">Загрузка ' + Math.round(f.progress) + '%</span>') +
        '<button type="button" class="btn btn--icon" data-remove="' + i + '" aria-label="' + (done ? 'Удалить файл' : 'Отменить загрузку') + '">' + A.icons.x + '</button>' +
      '</div>';
    }).join('') || '<div class="muted small">Файлы ещё не добавлены</div>';
  }

  // Имитация загрузки: прогресс растёт, пока не дойдёт до 100
  setInterval(function () {
    var changed = false;
    files.forEach(function (f) {
      if (f.progress < 100) { f.progress = Math.min(100, f.progress + 4 + Math.random() * 8); changed = true; }
      if (f.ocr && f.progress >= 100 && !f.ocrDone) {
        f.ocrTicks = (f.ocrTicks || 0) + 1;
        if (f.ocrTicks > 8) { f.noText = false; f.ocrDone = true; f.info = 'OCR'; changed = true; }
      }
    });
    if (changed) render();
  }, 400);

  function addFiles(list) {
    Array.prototype.forEach.call(list, function (file) {
      files.push({ name: file.name, kind: kindOf(file.name), size: sizeOf(file.size), progress: 0 });
    });
    render();
  }

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
    if (rm) { files.splice(+rm.getAttribute('data-remove'), 1); render(); }
    var ocr = e.target.closest('[data-ocr]');
    if (ocr) { files[+ocr.getAttribute('data-ocr')].ocr = true; render(); }
  });

  A.$$('[data-add]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-add');
      if (t === 'URL') {
        var link = prompt('Ссылка на страницу с материалом', 'https://postgrespro.ru/docs/postgresql/16/mvcc');
        if (link) { files.push({ name: link, kind: 'URL', size: 'веб-страница', progress: 30 }); render(); }
      } else if (t === 'TXT') {
        files.push({ name: 'Текст из буфера обмена.txt', kind: 'TXT', size: '0,1 МБ', progress: 100 }); render();
      } else {
        files.push({ name: 'Лекция 3. SQL: соединения и подзапросы.pptx', kind: 'PPTX', size: '4,2 МБ', info: '28 слайдов', progress: 100 }); render();
      }
    });
  });

  A.$('#save-draft').addEventListener('click', function () { A.toast('Черновик сохранён'); });

  A.$('#gen-form').addEventListener('submit', function (e) {
    var ready = files.filter(function (f) { return f.progress >= 100 && !f.noText; }).length;
    if (!ready) { e.preventDefault(); A.toast('Добавьте хотя бы один готовый материал'); return; }
    if (!A.$$('input[name="types[]"]:checked').length) { e.preventDefault(); A.toast('Выберите хотя бы один тип вопросов'); return; }
    e.preventDefault();
    location.href = 'pipeline.html';
  });

  render();
})();
