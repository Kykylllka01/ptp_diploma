@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page page--narrow">
  <div class="page-head">
    <div>
      <a href="{{ route('teacher.analytics', ['test' => $appData['attempt']['testId']]) }}" class="back">← Все студенты теста</a>
      <div class="lbl" style="margin-top: 10px" id="test-title"></div>
      <h1 class="page-title">{{ $appData['who']['name'] }}</h1>
      <p class="page-sub">{{ collect([$appData['who']['group'], $appData['who']['email']])->filter()->implode(' · ') }}</p>
    </div>
    <div class="actions" id="attempt-switch"></div>
  </div>

  <section class="card row-wrap" style="padding: 28px; gap: 32px">
    <div class="donut" id="donut"><div class="donut__in"><div><div class="donut__value" id="percent"></div><div class="muted xsmall" style="margin-top: 4px" id="score"></div></div></div></div>
    <div style="flex: 1; min-width: 260px" class="stack-sm">
      <div class="row-wrap" id="chips"></div>
      <div id="points-line" class="small"></div>
      <div id="pending-line"></div>
    </div>
  </section>

  <section class="card card--pad" id="proctor-card" hidden>
    <div class="between"><h2 class="card-title" style="font-size: 19px">Прокторинг</h2><span id="proctor-sum"></span></div>
    <p class="muted small" style="margin: 6px 0 0" id="proctor-note"></p>
    <ol class="proctor-log" id="proctor-log"></ol>
  </section>

  <div class="split split--stretch">
    <section class="card card--pad" style="flex: 1 1 380px; min-width: 0">
      <h2 class="card-title" style="font-size: 19px">По темам</h2>
      <div class="stack small" style="margin-top: 16px; gap: 14px" id="topics"></div>
    </section>
    <section class="card card--pad" style="flex: 1 1 380px; min-width: 0; display: flex; flex-direction: column; gap: 12px">
      <h2 class="card-title" style="font-size: 19px">Где ошибки</h2>
      <p style="margin: 0; line-height: 1.55; font-size: 15px" id="advice"></p>
      <a href="#" id="train-btn" hidden></a>
    </section>
  </div>

  <section class="card card--pad" id="review-card">
    <div class="between">
      <h2 class="card-title" style="font-size: 19px">Ответы</h2>
      <div class="row-wrap" style="gap: 6px" id="filters" role="group" aria-label="Фильтр ответов"></div>
    </div>
    <div id="review"></div>
  </section>
</main>
@endsection
