@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page">
  <div class="page-head">
    <div>
      <div class="lbl">Базы данных</div>
      <h1 class="page-title">Аналитика</h1>
      <p class="page-sub">Выберите тест, чтобы посмотреть распределение баллов, результаты по темам и проблемные вопросы.</p>
    </div>
    <div class="actions">
      <select class="select" id="filter" aria-label="Какие тесты показать" style="width: auto">
        <option value="all">Все тесты</option>
        <option value="running">Идут</option>
        <option value="closed">Приём закрыт</option>
        <option value="training">Тренировки</option>
      </select>
    </div>
  </div>

  <section class="card card--pad">
    <div class="table-wrap">
      <table class="table" style="min-width: 860px">
        <thead><tr><th>Тест</th><th>Статус</th><th>Сдали</th><th>Средний балл</th><th>Ждут проверки</th><th>Последняя сдача</th><th class="right"></th></tr></thead>
        <tbody id="tests"></tbody>
      </table>
    </div>
  </section>
</main>
@endsection
