@extends('layouts.app')

@section('header')
    @include('partials.header-student')
@endsection

@section('content')
<main class="page page--narrow">
  <section class="card row-wrap" style="padding: 32px; gap: 32px">
    <div class="donut" id="donut"><div class="donut__in"><div><div class="donut__value" id="percent"></div><div class="muted xsmall" style="margin-top: 4px" id="score"></div></div></div></div>
    <div style="flex: 1; min-width: 260px">
      <div class="lbl" id="test-title"></div>
      <h1 class="page-title" style="font-size: 30px">Тест сдан</h1>
      <div class="row-wrap" style="margin-top: 12px" id="chips"></div>
      <p style="margin: 14px 0 0; color: var(--text-2); line-height: 1.55">Итоговый балл может измениться после того, как преподаватель проверит открытые ответы.</p>
    </div>
  </section>

  <div class="split split--stretch">
    <section class="card card--pad" style="flex: 1 1 380px; min-width: 0">
      <h2 class="card-title" style="font-size: 19px">По темам</h2>
      <div class="stack small" style="margin-top: 16px; gap: 14px" id="topics"></div>
    </section>
    <section class="card card--pad card--dark" style="flex: 1 1 380px; min-width: 0; display: flex; flex-direction: column; gap: 12px">
      <h2 class="card-title" style="font-size: 19px">Что повторить</h2>
      <p style="margin: 0; line-height: 1.55; font-size: 15px" id="advice"></p>
      <div class="row-wrap" style="gap: 10px; margin-top: auto">
        <a href="#" class="btn btn--white" id="train-btn">Тренировка по ошибкам</a>
      </div>
    </section>
  </div>

  <section class="card card--pad" id="review-card">
    <div class="between">
      <h2 class="card-title" style="font-size: 19px">Разбор ответов</h2>
      <div class="row-wrap" style="gap: 6px" id="filters" role="group" aria-label="Фильтр разбора"></div>
    </div>
    <div id="review"></div>
  </section>
</main>
@endsection
