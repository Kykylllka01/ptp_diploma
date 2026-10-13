@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page">
  <div class="page-head">
    <div>
      <div class="lbl">{{ $appData['course']['title'] }}</div>
      <h1 class="page-title">Аналитика</h1>
      <p class="page-sub">Как курс усваивает материал: темы, группы и вопросы по всем тестам. Подробный разбор теста — по клику на тест в списке ниже.</p>
    </div>
    <div class="actions">
      <select class="select" id="test-pick" aria-label="Разбор теста" style="width: auto; min-width: 240px" hidden></select>
    </div>
  </div>

  <div class="grid-stats" id="stats"></div>

  <div class="split split--stretch" id="charts">
    <section class="card card--pad split__main" style="gap: 0">
      <div class="between"><h2 class="card-title">Темы курса</h2><span class="muted small">доля набранных баллов, слабые сверху</span></div>
      <div class="bars" id="topics"></div>
      <div class="note" style="margin-top: 18px" id="topic-note" hidden></div>
    </section>
    <aside class="card card--pad split__side" style="flex-basis: 340px">
      <h2 class="card-title">Группы</h2>
      <div class="bars" id="groups"></div>
    </aside>
  </div>

  <section class="card card--pad">
    <div class="between"><h2 class="card-title">Тесты</h2><span class="muted small">средний балл лучших попыток · откройте тест для разбора</span></div>
    <div class="bars bars--tests" id="tests"></div>
  </section>

  <section class="card card--pad" id="problems-card">
    <div class="between"><h2 class="card-title">Трудные вопросы</h2><span class="muted small">меньше всего полностью верных ответов, от 5 ответов</span></div>
    <div class="table-wrap">
      <table class="table table--stack" style="min-width: 760px"><caption class="sr-only">Трудные вопросы курса</caption>
        <thead><tr><th>Вопрос</th><th>Тема</th><th style="width: 220px">Верно ответили</th><th class="num-col">Ответов</th><th><span class="sr-only">Открыть</span></th></tr></thead>
        <tbody id="problems"></tbody>
      </table>
    </div>
  </section>
</main>
@endsection
