@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page" style="padding-top: 32px">
  <div class="between" style="gap: 16px">
    <div class="row-wrap" style="gap: 14px">
      <a href="bank" class="back" id="back">← Банк вопросов</a>
      <span class="mono muted small" id="pos"></span>
      <span id="status-chip"></span>
    </div>
    <div class="actions actions--sm">
      <span class="muted small" id="dirty" aria-live="polite"></span>
      <button type="button" class="btn" id="reject">Отклонить</button>
      <button type="button" class="btn" id="regen">Перегенерировать</button>
      <button type="button" class="btn" id="save">Сохранить</button>
      <button type="button" class="btn btn--primary" id="approve">Одобрить и далее</button>
    </div>
  </div>

  <div class="split">
    <form class="card split__main" id="form" style="padding: 28px; gap: 22px; flex-basis: 600px">
      <div class="row-wrap" style="gap: 12px; align-items: flex-end">
        <label class="field">Тип<select class="select" id="type"></select></label>
        <label class="field">Сложность<select class="select" id="difficulty"></select></label>
        <label class="field">Тема<select class="select" id="topic"></select></label>
        <label class="field">Баллы<input class="input" type="number" id="points" min="1" max="10" style="width: 90px"></label>
      </div>

      <label class="field"><span>Исходные данные <span class="field__hint">видны студенту над вопросом: схема, зависимости, строки таблицы</span></span><textarea class="textarea mono" id="context" rows="3"></textarea></label>

      <label class="field">Формулировка<textarea class="textarea textarea--lg" id="text" rows="3"></textarea></label>

      <fieldset id="options-box" style="border: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px">
        <legend class="small" style="font-weight: 500; margin-bottom: 8px">Варианты ответа <span class="muted" style="font-weight: 400" id="options-hint">· отметьте верный</span></legend>
        <div class="stack-sm" id="options"></div>
        <button type="button" class="btn btn--sm" style="align-self: flex-start" id="add-option">+ Вариант</button>
      </fieldset>

      <div class="field" id="reference-box" hidden>
        <label for="reference" id="reference-label">Эталонный ответ</label>
        <textarea class="textarea mono" id="reference" rows="4"></textarea>
        <div class="row-wrap" id="sql-tools" hidden><button type="button" class="btn btn--sm" id="run-ref">Выполнить эталон на учебной базе</button><span class="muted small" id="run-ref-status"></span></div>
        <div class="sql-result" id="run-ref-result"></div>
      </div>

      <fieldset id="criteria-box" hidden style="border: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px">
        <legend class="small" style="font-weight: 500; margin-bottom: 8px">Критерии оценки <span class="muted" style="font-weight: 400">· по ним Оценщик ставит баллы, сумма = баллам за вопрос</span></legend>
        <div class="stack-sm" id="criteria"></div>
        <button type="button" class="btn btn--sm" style="align-self: flex-start" id="add-criterion">+ Критерий</button>
      </fieldset>

      <label class="field">Пояснение для студента после ответа<textarea class="textarea" id="explanation" rows="2"></textarea></label>

      <div>
        <div class="small" style="font-weight: 500">Теги</div>
        <div class="row-wrap" style="margin-top: 8px" id="tags"></div>
      </div>
    </form>

    <aside class="split__side stack" style="flex-basis: 360px">
      <div class="card" style="padding: 22px">
        <div class="between"><h2 class="card-title card-title--xs">Заключение критика</h2><span class="mono xsmall muted" id="critic-score"></span></div>
        <ul class="checks" id="critic"></ul>
        <button type="button" class="btn btn--sm btn--block" style="margin-top: 16px" id="apply-fix" hidden>Применить предложение критика</button>
      </div>
      <div class="card" style="padding: 22px">
        <div class="between"><h2 class="card-title card-title--xs">Источник</h2><a href="bank?status=all" class="small" id="source"></a></div>
        <blockquote class="quote" id="quote"></blockquote>
      </div>
      <div class="card" style="padding: 22px">
        <h2 class="card-title card-title--xs">История</h2>
        <div class="stack-sm small" style="margin-top: 12px; gap: 8px; color: var(--text-2)" id="history"></div>
      </div>
    </aside>
  </div>
</main>
@endsection
