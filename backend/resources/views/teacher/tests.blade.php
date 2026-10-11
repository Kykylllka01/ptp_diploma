@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page">
  <div class="page-head">
    <div>
      <div class="lbl">Базы данных</div>
      <h1 class="page-title">Тесты</h1>
      <p class="page-sub">Пройдите тест сами, чтобы проверить вопросы до студентов. Приём ответов можно закрыть и открыть снова.</p>
    </div>
    <div class="actions">
      <a href="test-builder" class="btn btn--primary">Собрать тест</a>
    </div>
  </div>

  <section class="card card--pad">
    <div class="table-wrap">
      <table class="table" style="min-width: 860px">
        <thead><tr><th>Название</th><th>Вопросов</th><th>Статус</th><th>Сроки</th><th>Сдали</th><th>Средний балл</th><th class="right">Действия</th></tr></thead>
        <tbody id="tests"></tbody>
      </table>
    </div>
  </section>
</main>
@endsection
