@extends('layouts.app')

@section('content')
<main class="page auth-page">
  <div class="card card--pad auth-card">
    <a href="{{ route('login') }}" class="brand auth-card__brand" aria-label="ТюмГУ — ко входу"><img src="{{ asset('assets/img/logo-utmn.svg') }}" alt="ТюмГУ"></a>

    @if (! $invite)
      <div>
        <h1 class="page-title auth-card__title">Ссылка не работает</h1>
        <p class="page-sub">
          @switch($reason)
            @case('used') По этой ссылке уже зарегистрировались. @break
            @case('expired') Срок действия ссылки истёк. @break
            @case('revoked') Администратор отозвал эту ссылку. @break
            @default В ссылке ошибка.
          @endswitch
          Попросите у администратора новую.
        </p>
      </div>
      <a href="{{ route('login') }}" class="btn btn--block">У меня уже есть аккаунт</a>
    @elseif ($user)
      <div>
        <h1 class="page-title auth-card__title">Вы уже вошли</h1>
        <p class="page-sub">Вы вошли как {{ $user->name }}. Ссылка нужна новому преподавателю: чтобы зарегистрироваться по ней, сначала выйдите.</p>
      </div>
      <div class="stack-sm">
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="btn btn--primary btn--block">Выйти</button></form>
        <a href="{{ route('home') }}" class="btn btn--block">Остаться в своём аккаунте</a>
      </div>
    @else
      <div>
        <div class="lbl">Регистрация преподавателя</div>
        <h1 class="page-title auth-card__title">Добро пожаловать</h1>
        <p class="page-sub">После регистрации вы попадёте в кабинет преподавателя: загрузите лекции, агенты составят по ним вопросы, а вы соберёте тесты для своих групп.</p>
      </div>

      @if ($errors->any())
        <div class="note note--warn" role="alert">{{ $errors->first() }}</div>
      @endif

      <form method="POST" action="{{ route('teacher-invite', $token) }}" class="stack" style="gap: 14px" novalidate>
        @csrf
        <label class="field">Фамилия<input class="input" name="last_name" value="{{ old('last_name') }}" autocomplete="family-name" maxlength="60" required autofocus></label>
        <div class="grid-fields" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px">
          <label class="field">Имя<input class="input" name="first_name" value="{{ old('first_name') }}" autocomplete="given-name" maxlength="60" required></label>
          <label class="field"><span>Отчество <span class="field__hint">если есть</span></span><input class="input" name="middle_name" value="{{ old('middle_name') }}" autocomplete="additional-name" maxlength="60"></label>
        </div>
        <label class="field"><span>Почта <span class="field__hint">для входа</span></span><input class="input" type="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="150" required></label>
        <div class="grid-fields" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px">
          <label class="field"><span>Пароль <span class="field__hint">от 8 символов</span></span><input class="input" type="password" name="password" autocomplete="new-password" minlength="8" required></label>
          <label class="field">Повтор пароля<input class="input" type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required></label>
        </div>
        <button type="submit" class="btn btn--primary btn--block">Зарегистрироваться</button>
      </form>
      <p class="muted small" style="margin: 0; text-align: center">Уже есть аккаунт? <a href="{{ route('login') }}">Войти</a></p>
    @endif
  </div>
</main>
@endsection
