/* Аналитика: список тестов курса, из него выбирается тест для подробного разбора */
(function () {
  var A = window.App, M = A.M, esc = A.esc;
  var extra = M.analyticsTests || {};
  var tone = { running: 'blue', training: 'blue', closed: 'gray' };

  // Черновики не показываем: результатов у них не бывает
  var tests = M.tests.filter(function (t) { return t.status !== 'draft'; }).map(function (t) {
    var x = extra[t.id] || {};
    return Object.assign({}, t, { pending: x.pending || 0, lastSubmitted: x.lastSubmitted, lastTs: x.lastTs || 0 });
  });
  // Сначала тесты со свежими сдачами, затем остальные — новые выше
  var order = M.tests.map(function (t) { return t.id; });
  tests.sort(function (a, b) { return b.lastTs - a.lastTs || order.indexOf(b.id) - order.indexOf(a.id); });

  function row(t) {
    var url = 'analytics?test=' + encodeURIComponent(t.id);
    var meta = [t.count + ' ' + A.plural(t.count, 'вопрос', 'вопроса', 'вопросов'), t.group || 'все студенты курса'].join(' · ');
    var pending = t.pending
      ? A.chip(t.pending + ' ' + A.plural(t.pending, 'ответ', 'ответа', 'ответов'), 'amber')
      : '<span class="muted">—</span>';
    return '<tr class="is-clickable" data-href="' + url + '">' +
      '<td><a href="' + url + '" class="strong">' + esc(t.title) + '</a><div class="muted xsmall">' + esc(meta) + '</div></td>' +
      '<td>' + A.chip(t.statusLabel, tone[t.status] || 'gray') + '</td>' +
      '<td>' + esc(t.passed) + '</td>' +
      '<td>' + esc(t.avg) + '</td>' +
      '<td>' + pending + '</td>' +
      '<td class="small">' + (t.lastSubmitted ? esc(t.lastSubmitted) : '<span class="muted">пока никто не сдал</span>') + '</td>' +
      '<td class="right"><a href="' + url + '" class="btn btn--xs">Открыть</a></td></tr>';
  }

  function render() {
    var f = A.$('#filter').value;
    var list = tests.filter(function (t) { return f === 'all' || t.status === f; });
    A.$('#tests').innerHTML = list.map(row).join('') || (tests.length
      ? '<tr><td colspan="7" class="empty">Нет тестов с таким статусом.</td></tr>'
      : '<tr><td colspan="7" class="empty">Здесь появятся тесты, когда вы их опубликуете. <a href="test-builder">Собрать тест</a></td></tr>');
  }

  A.$('#filter').addEventListener('change', render);
  // Клик по строке открывает тест; ссылки и кнопки внутри работают как обычно
  A.$('#tests').addEventListener('click', function (e) {
    var tr = e.target.closest('tr[data-href]');
    if (tr && !e.target.closest('a, button')) location.href = tr.dataset.href;
  });
  render();
})();
