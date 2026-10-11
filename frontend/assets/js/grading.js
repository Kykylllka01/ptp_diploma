/* Подсчёт баллов попытки. В реальном приложении это делает сервер:
   вопросы с вариантами — сразу, SQL — на учебной базе, развёрнутые — агент-оценщик (LLM).
   Здесь для развёрнутых ответов стоит простая эвристика по ключевым словам — только для демо. */
(function () {
  function has(text, words) { return words.some(function (w) { return text.indexOf(w) >= 0; }); }

  function gradeChoice(q, ans) {
    var a = (ans || []).slice().sort().join(','), c = q.correct.slice().sort().join(',');
    return { score: a === c ? q.points : 0, max: q.points, final: true };
  }

  function gradeSql(q, ans) {
    var t = (ans || '').toUpperCase().replace(/\s+/g, ' ');
    var s = 0, notes = [];
    if (/COUNT\s*\(/.test(t) && t.indexOf('GROUP BY') >= 0) { s += 1; notes.push([1, 1, 'Есть группировка и подсчёт']); } else notes.push([0, 1, 'Нет GROUP BY с COUNT']);
    if (t.indexOf('LEFT JOIN') >= 0 || t.indexOf('LEFT OUTER JOIN') >= 0) { s += 1; notes.push([1, 1, 'Группы без студентов попадут в результат']); } else notes.push([0, 1, 'Группы без студентов потеряются — нужен LEFT JOIN']);
    if (/ORDER BY .*DESC/.test(t)) { s += 1; notes.push([1, 1, 'Сортировка по убыванию']); } else notes.push([0, 1, 'Нет сортировки по убыванию']);
    return { score: s, max: q.points, final: false, criteria: notes };
  }

  function gradeOpen(q, ans) {
    var t = (ans || '').toLowerCase();
    var c1 = has(t, ['избыточ', 'повтор', 'дублир']) ? 2 : 0;
    var c2 = has(t, ['удален', 'удаля']) ? (has(t, ['сведени', 'информаци', 'данные о']) ? 2 : 1) : 0;
    var c3 = has(t, ['вставк', 'вставить', 'добавить']) ? 1 : 0;
    var crit = q.criteria;
    var notes = [
      [c1, crit[0][1], c1 ? 'Цель нормализации названа верно' : 'Не названа цель — устранение избыточности'],
      [c2, crit[1][1], c2 === 2 ? 'Аномалия удаления описана верно' : c2 ? 'Аномалия удаления — уточните, что теряются сведения о другой сущности' : 'Не описана аномалия удаления'],
      [c3, crit[2][1], c3 ? 'Есть пример аномалии вставки' : 'Не приведён пример аномалии вставки']
    ];
    return { score: c1 + c2 + c3, max: q.points, final: false, criteria: notes };
  }

  function grade(q, ans) {
    if (q.type === 'sql') return gradeSql(q, ans);
    if (q.type === 'open') return gradeOpen(q, ans);
    return gradeChoice(q, ans);
  }

  function isAnswered(q, ans) {
    if (ans == null) return false;
    if (Array.isArray(ans)) return ans.length > 0;
    return String(ans).trim().length > 0;
  }

  window.Grading = { grade: grade, isAnswered: isAnswered };
})();
