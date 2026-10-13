@extends('layouts.app')
@php($title = $heading)

{{-- Страница ошибки в стиле сайта: понятный текст по-русски и дорога назад вместо английской заглушки Laravel --}}
@section('content')
<main class="page auth-page">
  <div class="card card--pad auth-card" style="text-align: center; align-items: center">
    <a href="{{ url('/') }}" class="brand auth-card__brand" aria-label="ТюмГУ — на главную"><img src="{{ asset('assets/img/logo-utmn.svg') }}" alt="ТюмГУ"></a>
    <div class="error-code" aria-hidden="true">{{ $code }}</div>
    <div>
      <h1 class="page-title auth-card__title">{{ $heading }}</h1>
      <p class="page-sub" style="margin-top: 8px">{{ $text }}</p>
    </div>
    <div class="row-wrap" style="justify-content: center; gap: 10px">
      @if ($code == 419)
        <a href="{{ url()->previous() }}" class="btn btn--primary">Вернуться и обновить</a>
      @else
        <a href="{{ url('/') }}" class="btn btn--primary">На главную</a>
        @if (url()->previous() !== url()->current())
          <a href="{{ url()->previous() }}" class="btn">Назад</a>
        @endif
      @endif
    </div>
  </div>
</main>
@endsection
