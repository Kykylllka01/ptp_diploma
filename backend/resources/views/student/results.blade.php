@extends('layouts.app')

@section('header')
    @include('partials.header-student')
@endsection

@section('content')
<main class="page page--narrow">
  <div>
    <div class="lbl">{{ collect([$appData['course']['title'], $appData['course']['semester']])->filter()->implode(' · ') }}</div>
    <h1 class="page-title">Результаты</h1>
    <p class="page-sub">Все сданные тесты и тренировки. Нажмите на строку — откроется разбор попытки.</p>
  </div>

  <div class="grid-stats" id="summary" hidden>
    <div class="card stat"><div class="lbl">Сдано тестов</div><div class="stat__value" id="s-done">0</div><div class="stat__hint">с оценкой, без тренировок</div></div>
    <div class="card stat"><div class="lbl">Средний результат</div><div class="stat__value" id="s-avg">—</div><div class="stat__hint">по сданным тестам</div></div>
    <div class="card stat"><div class="lbl">Ждут проверки</div><div class="stat__value" id="s-pending">0</div><div class="stat__hint">развёрнутые ответы проверяет преподаватель</div></div>
  </div>

  <section class="stack" style="gap: 14px">
    <div class="between"><h2 class="card-title">Сданные попытки</h2><div class="row-wrap" style="gap: 6px" id="filters" role="group" aria-label="Какие попытки показать"></div></div>
    <div class="card card--clip" id="done"></div>
  </section>
</main>
@endsection
