@extends('layouts.app')

@section('header')
    @include('partials.header-student')
@endsection

@section('content')
<main class="page" style="gap: 32px">
  @if (session('status'))
    <div class="note note--info" role="status">{{ session('status') }}</div>
  @endif
  <div>
    <div class="lbl">Базы данных · осенний семестр</div>
    <h1 class="page-title">Мои тесты</h1>
  </div>

  <section class="stack" style="gap: 14px">
    <h2 class="card-title">Нужно пройти</h2>
    <div class="grid-tests" id="todo"></div>
  </section>

  <section class="card card--pad card--dark row-wrap" id="training" style="gap: 20px" hidden>
    <div style="flex: 1; min-width: 280px">
      <h2 style="font-size: 22px">Тренировка по материалам курса</h2>
      <p style="margin: 8px 0 0; font-size: 15px; line-height: 1.5">Вопросы из банка с подсказкой после каждого ответа. Без оценки.</p>
      <div class="row-wrap" style="margin-top: 14px" id="train-topics" role="group" aria-label="Тренировка"></div>
    </div>
    <a href="#" class="btn btn--white" id="train-start">Начать тренировку</a>
  </section>

  <section class="stack" style="gap: 14px">
    <h2 class="card-title">Пройдено</h2>
    <div class="card card--clip" id="done"></div>
  </section>
</main>
@endsection
