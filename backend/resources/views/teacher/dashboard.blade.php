@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page" style="gap: 32px">
  <div class="page-head">
    <div>
      <div class="lbl">Курс · Базы данных</div>
      <h1 class="page-title" style="font-size: 36px">Добрый день, <span id="teacher-name"></span></h1>
      <p class="page-sub" id="greeting-sub"></p>
    </div>
    <div class="actions">
      <a href="upload" class="btn" id="btn-upload">Загрузить материалы</a>
      <a href="test-builder" class="btn btn--primary" id="btn-build">Собрать тест</a>
    </div>
  </div>

  <div class="grid-stats" id="stats"></div>

  <div class="split">
    <section class="card card--pad split__main" style="gap: 0">
      <div class="between">
        <h2 class="card-title">Материалы курса</h2>
        <a href="upload" class="small" style="font-weight: 500">Добавить</a>
      </div>
      <div style="margin-top: 16px" id="materials"></div>
    </section>

    <aside class="card card--pad split__side" style="flex-basis: 340px">
      <div class="between">
        <h2 class="card-title">Агенты</h2>
        <a href="pipeline" class="small" style="font-weight: 500">Журнал</a>
      </div>
      <div class="stack" style="margin-top: 18px; gap: 14px" id="agents"></div>
    </aside>
  </div>

  <section class="card card--pad">
    <div class="between">
      <h2 class="card-title">Тесты</h2>
      <a href="tests" class="small" style="font-weight: 500">Все тесты</a>
    </div>
    <div class="table-wrap">
      <table class="table" style="min-width: 720px">
        <thead><tr><th>Название</th><th>Вопросов</th><th>Статус</th><th>Прошли</th><th>Средний балл</th><th></th></tr></thead>
        <tbody id="tests"></tbody>
      </table>
    </div>
  </section>
</main>
@endsection
