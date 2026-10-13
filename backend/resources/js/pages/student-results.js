/* Студент — все сданные попытки: сводка и список с фильтром «контрольные / тренировки». */
(function () {
  var A = window.App, M = A.M;
  var done = M.studentDone || [];
  var graded = done.filter(function (d) { return d.graded; });
  var state = A.recall('student-results', { filter: 'all' });

  if (done.length) {
    A.$('#summary').hidden = false;
    A.$('#s-done').textContent = graded.length;
    A.$('#s-avg').textContent = graded.length ? Math.round(graded.reduce(function (s, d) { return s + d.percent; }, 0) / graded.length) + '%' : '—';
    var pending = graded.filter(function (d) { return d.status[1] === 'amber'; }).length;
    A.$('#s-pending').textContent = pending;
  }

  function render() {
    A.remember('student-results', state);
    var trainings = done.length - graded.length;
    // Фильтр нужен, только когда есть и контрольные, и тренировки
    A.$('#filters').innerHTML = graded.length && trainings
      ? A.filterTabs([['all', 'Все', done.length], ['graded', 'С оценкой', graded.length], ['training', 'Тренировки', trainings]], state.filter, 'Какие попытки')
      : '';
    var list = done.filter(function (d) {
      return !graded.length || !trainings || state.filter === 'all' || (state.filter === 'graded') === d.graded;
    });
    A.$('#done').innerHTML = A.doneRows(list) ||
      '<div class="empty">Пока ничего не сдано. Тесты, которые нужно пройти, — на странице <a href="tests">«Мои тесты»</a>.</div>';
  }
  A.$('#filters').addEventListener('click', function (e) {
    var b = e.target.closest('[data-filter]');
    if (b) { state.filter = b.getAttribute('data-filter'); A.keepFocus(render); }
  });
  render();
})();
