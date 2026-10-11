@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page">
  <div class="page-head">
    <div>
      <div class="lbl" id="test-status">Тест</div>
      <h1 class="page-title" id="title"></h1>
    </div>
    <div class="actions">
      <select class="select" id="test-switch" aria-label="Другой тест" style="width: auto" hidden></select>
      <button type="button" class="btn" id="export">Экспорт в Excel</button>
      <a href="review" class="btn btn--primary" id="to-review"></a>
    </div>
  </div>

  <div class="grid-stats" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))" id="stats"></div>

  <div class="split split--stretch">
    <section class="card card--pad split__main" style="gap: 0">
      <h2 class="card-title">Распределение баллов</h2>
      <div class="hist" id="hist" role="img"></div>
      <div class="hist-axis" id="hist-axis"></div>
    </section>
    <aside class="card card--pad split__side" style="flex-basis: 340px">
      <h2 class="card-title">По темам</h2>
      <div class="stack small" style="margin-top: 18px" id="topics"></div>
      <div class="note" style="margin-top: 20px; color: var(--text)"><b class="strong">Вывод агента:</b> <span id="insight"></span>
        <div style="margin-top: 10px"><a href="test-builder?training=1" class="strong">Создать тренировку</a></div></div>
    </aside>
  </div>

  <section class="card card--pad">
    <div class="between"><h2 class="card-title">Вопросы</h2><span class="muted small">Отсортировано: сначала проблемные</span></div>
    <div class="table-wrap">
      <table class="table" style="min-width: 820px">
        <thead><tr><th>Вопрос</th><th>Тип</th><th style="width: 200px">Верно ответили</th><th>Сигнал</th><th></th></tr></thead>
        <tbody id="problems"></tbody>
      </table>
    </div>
  </section>
</main>
@endsection
