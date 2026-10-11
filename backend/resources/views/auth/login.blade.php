@extends('layouts.app')

@section('content')
<main class="page" style="min-height: 100vh; justify-content: center; align-items: center">
  <form method="POST" action="{{ route('login') }}" class="card card--pad" style="width: 100%; max-width: 420px; display: flex; flex-direction: column; gap: 18px" novalidate>
    @csrf
    <div class="brand" style="gap: 12px"><img src="{{ asset('assets/img/logo-utmn.svg') }}" alt="ТюмГУ"></div>
    <div>
      <h1 class="page-title" style="font-size: 28px">Вход</h1>
      <p class="page-sub" style="margin-top: 6px">Банк вопросов и тестирование</p>
    </div>

    @if ($errors->any())
      <div class="note note--warn" role="alert">{{ $errors->first() }}</div>
    @endif

    <label class="field">Почта
      <input class="input" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
    </label>
    <label class="field">Пароль
      <input class="input" type="password" name="password" autocomplete="current-password" required>
    </label>
    <label class="ck"><input type="checkbox" name="remember" value="1"> Запомнить меня</label>

    <button type="submit" class="btn btn--primary btn--block">Войти</button>

    @if (app()->isLocal())
      <div class="note xsmall">
        Демо-учётки (видны только при APP_ENV=local):<br>
        преподаватель — <span class="mono">teacher@utmn.local</span> / <span class="mono">teacher123</span><br>
        студент — <span class="mono">student@utmn.local</span> / <span class="mono">student123</span>
      </div>
    @endif
  </form>
</main>
@endsection
