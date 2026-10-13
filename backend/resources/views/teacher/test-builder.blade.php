@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page">
  <div>
    <div class="lbl">Новый тест · черновик</div>
    <h1 class="page-title" id="title-preview">Новый тест</h1>
  </div>

  <form class="split" id="builder">
    <div class="split__main" style="flex-basis: 600px">
      <section class="card card--pad">
        <label class="field" style="font-size: 15px">Название теста
          <input class="input input--lg" id="test-title" maxlength="255" placeholder="Например, Контрольная по нормализации" autocomplete="off">
        </label>
      </section>

      <section class="card card--pad">
        <h2 class="card-title">Как собрать</h2>
        <div class="row-wrap" style="margin-top: 16px; gap: 10px; align-items: stretch">
          <label class="seg"><span class="row-wrap"><input type="radio" name="mode" value="rules" checked><b class="strong" style="font-size: 15px">По правилам</b></span><span class="muted">Экзаменатор подбирает одобренные вопросы банка по темам и сложности</span></label>
          <label class="seg"><span class="row-wrap"><input type="radio" name="mode" value="manual"><b class="strong" style="font-size: 15px">Вручную</b></span><span class="muted">Вы выбираете конкретные вопросы из банка</span></label>
        </div>
        <div class="note" style="margin-top: 14px" id="mode-note" hidden></div>
      </section>

      <section class="card card--pad" id="composition">
        <div class="between"><h2 class="card-title">Состав</h2><span class="muted small" id="bank-total"></span></div>
        <div class="table-wrap">
          <table class="table" style="min-width: 560px">
            <thead><tr><th>Тип вопроса</th><th>Лёгкие</th><th>Средние</th><th>Сложные</th><th class="right">Баллы за вопрос</th></tr></thead>
            <tbody id="matrix"></tbody>
          </table>
        </div>
        <div class="row-wrap" style="margin-top: 16px">
          <span class="small" style="font-weight: 500; margin-right: 4px">Темы:</span>
          <span id="topics" class="row-wrap"></span>
          <select class="select" id="add-topic" style="width: auto; min-height: 28px; height: 28px; font-size: 13px; border-radius: 999px; padding: 0 8px" aria-label="Добавить тему"></select>
        </div>
      </section>

      <section class="card card--pad">
        <h2 class="card-title">Проведение</h2>
        <div class="grid-fields" style="margin-top: 16px">
          <label class="field">Открыт с<input class="input" type="datetime-local" id="opens-at" value="{{ now()->format('Y-m-d\TH:i') }}"></label>
          <label class="field">Закрывается<input class="input" type="datetime-local" id="deadline-at" value="{{ now()->addDays(10)->format('Y-m-d') }}T23:59"></label>
          <label class="field">Время на попытку<select class="select" id="minutes"><option value="15">15 минут</option><option value="20">20 минут</option><option value="25">25 минут</option><option value="30">30 минут</option><option value="45" selected>45 минут</option><option value="60">60 минут</option><option value="90">90 минут</option><option value="0">Без ограничения</option></select></label>
          <label class="field">Попыток<select class="select" id="attempts"><option value="1">1</option><option value="2">2</option><option value="99">Без ограничений</option></select></label>
          <label class="field"><span>Баллов за тест <span class="field__hint">в Модеус</span></span>
            <input class="input" type="number" id="weight" min="0" max="100" step="0.5" inputmode="decimal" placeholder="например, 20">
          </label>
        </div>
        <p class="muted xsmall" style="margin: 10px 0 0">Баллы студента = процент выполнения × баллы за тест (85 % при весе 20 → 17 баллов). В зачёт идёт лучшая попытка. Вес можно поменять и после публикации — на странице «Тесты».</p>
        <div style="margin-top: 12px">
          <div class="tg"><div><div>Свой вариант каждому студенту</div><div class="muted xsmall" style="margin-top: 2px">Вопросы по той же матрице, но из всего подходящего пула банка — у соседей разные задания</div></div><button type="button" class="sw" id="sw-individual" aria-label="Свой вариант каждому студенту" aria-pressed="false"><span></span></button></div>
          <div class="tg"><span>Перемешивать вопросы и варианты</span><button type="button" class="sw" id="sw-shuffle" aria-label="Перемешивать вопросы и варианты" aria-pressed="true"><span></span></button></div>
          <div class="tg"><div><div>Показывать разбор после сдачи</div><div class="muted xsmall" style="margin-top: 2px">Открытые ответы — после подтверждения преподавателем</div></div><button type="button" class="sw" id="sw-review" aria-label="Показывать разбор после сдачи" aria-pressed="true"><span></span></button></div>
          <div class="tg"><div><div>Открытые ответы: автоматическая оценка без подтверждения</div><div class="muted xsmall" style="margin-top: 2px">Если уверенность оценщика не ниже 90%</div></div><button type="button" class="sw" id="sw-auto" aria-label="Открытые ответы: автоматическая оценка без подтверждения" aria-pressed="false"><span></span></button></div>
          <div class="tg"><div><div>Жёсткий прокторинг</div><div class="muted xsmall" style="margin-top: 2px" id="proctoring-hint">Нельзя уходить на другие вкладки и окна или сворачивать браузер: вопросы скрываются, а каждый уход попадает в ответы студента — вы увидите попытки списывания</div></div><button type="button" class="sw" id="sw-proctoring" aria-label="Жёсткий прокторинг" aria-describedby="proctoring-hint" aria-pressed="false"><span></span></button></div>
          <div class="tg"><span>Режим тренировки (без оценки, с подсказками)</span><button type="button" class="sw" id="training" aria-label="Режим тренировки (без оценки, с подсказками)" aria-pressed="false"><span></span></button></div>
        </div>
      </section>
    </div>

    <aside class="card card--pad split__side" style="display: flex; flex-direction: column; gap: 18px">
      <h2 class="card-title card-title--sm">Итог</h2>
      <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px">
        <div class="tile"><div class="tile__value" id="s-count">0</div><div class="tile__label" id="s-count-l">вопросов</div></div>
        <div class="tile"><div class="tile__value" id="s-points">0</div><div class="tile__label" id="s-points-l">баллов максимум</div></div>
        <div class="tile"><div class="tile__value" id="s-time">0</div><div class="tile__label">минут в среднем</div></div>
        <div class="tile"><div class="tile__value" id="s-open">0</div><div class="tile__label" id="s-open-l">проверяет преподаватель или SQL</div></div>
      </div>
      <div>
        <div class="lbl">Сложность</div>
        <div class="diff-bar"><div id="d-easy" style="background: #8FD3F4"></div><div id="d-med" style="background: #0072BC"></div><div id="d-hard" style="background: #0B2E4F"></div></div>
        <div class="between xsmall muted" style="margin-top: 8px"><span class="swatch-l"><i style="background: #8FD3F4"></i><span id="l-easy"></span></span><span class="swatch-l"><i style="background: #0072BC"></i><span id="l-med"></span></span><span class="swatch-l"><i style="background: #0B2E4F"></i><span id="l-hard"></span></span></div>
      </div>
      <div class="note note--warn" id="shortage"></div>
      <div>
        <div class="lbl" id="groups-label">Участники</div>
        <div class="pick" id="groups" role="group" aria-labelledby="groups-label" style="margin-top: 8px"></div>
        <div class="muted xsmall" id="groups-hint" style="margin-top: 8px"></div>
      </div>
      <button type="submit" class="btn btn--primary" id="publish">Опубликовать тест</button>
    </aside>
  </form>
</main>
@endsection
