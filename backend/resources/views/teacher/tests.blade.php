@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page">
  <div class="page-head">
    <div>
      <div class="lbl">{{ $appData['course']['title'] }}</div>
      <h1 class="page-title">Тесты</h1>
      <p class="page-sub">Публикация, сроки и приём ответов. Оценки студентов — в <a href="gradebook">«Журнале»</a>, разбор результатов — в <a href="analytics">«Аналитике»</a>.</p>
    </div>
    <div class="actions">
      <a href="test-builder" class="btn btn--primary">Собрать тест</a>
    </div>
  </div>

  <section class="card card--pad">
    <div class="toolbar">
      <label class="search"><span class="sr-only">Поиск по названию</span><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#5A6070" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input type="search" id="search" placeholder="Найти тест по названию" autocomplete="off"></label>
      <div id="filters"></div>
      <div class="toolbar__end"><select class="select select--sm" id="group-filter" aria-label="Группа" style="width: auto" hidden></select></div>
    </div>
    <div class="result-line" id="result-line" aria-live="polite"></div>
    <div class="table-wrap">
      <table class="table" style="min-width: 900px">
        <thead id="head"></thead>
        <tbody id="tests"></tbody>
      </table>
    </div>
  </section>
</main>
@endsection
