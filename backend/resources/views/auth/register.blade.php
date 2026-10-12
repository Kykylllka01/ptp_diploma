@extends('layouts.app')

@section('content')
<main class="page auth-page">
  <form method="GET" action="{{ route('register') }}" class="card card--pad auth-card" novalidate>
    <a href="{{ route('login') }}" class="brand auth-card__brand" aria-label="ТюмГУ — ко входу"><img src="{{ asset('assets/img/logo-utmn.svg') }}" alt="ТюмГУ"></a>
    <div>
      <h1 class="page-title auth-card__title">Регистрация</h1>
      <p class="page-sub">Зарегистрироваться можно по ссылке или коду группы — их выдаёт преподаватель. Если у вас ссылка, просто откройте её.</p>
    </div>

    @if ($wrong)
      <div class="note note--warn" role="alert">Код не подходит: проверьте, что ввели его без ошибок.</div>
    @endif

    <label class="field"><span>Код группы <span class="field__hint">12 букв и цифр</span></span>
      <input class="input mono" name="code" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="200" placeholder="например, k7mxq2va9tre" required autofocus>
    </label>
    <button type="submit" class="btn btn--primary btn--block">Продолжить</button>
    <p class="muted small" style="margin: 0; text-align: center">Уже есть аккаунт? <a href="{{ route('login') }}">Войти</a></p>
  </form>
</main>
@endsection
