@extends('layouts.app')

@section('content')
<main class="page auth-page">
  <div class="card card--pad auth-card">
    <a href="{{ route('login') }}" class="brand auth-card__brand" aria-label="ТюмГУ — ко входу"><img src="{{ asset('assets/img/logo-utmn.svg') }}" alt="ТюмГУ"></a>

    @if (! $group)
      <div>
        <h1 class="page-title auth-card__title">Ссылка не работает</h1>
        <p class="page-sub">Приём по этой ссылке закрыт, или в ней ошибка. Попросите у преподавателя новую ссылку или код группы.</p>
      </div>
      <div class="stack-sm">
        <a href="{{ route('register') }}" class="btn btn--primary btn--block">Ввести код группы</a>
        <a href="{{ route('login') }}" class="btn btn--block">У меня уже есть аккаунт</a>
      </div>
    @elseif ($user && $user->isTeacher())
      <div>
        <h1 class="page-title auth-card__title">Это ссылка для студентов</h1>
        <p class="page-sub">По ней студенты регистрируются в группе «{{ $group->name }}». Отправьте её группе — сами по ней переходить не нужно.</p>
      </div>
      <a href="{{ route('teacher.groups') }}" class="btn btn--primary btn--block">К группам</a>
    @elseif ($user && $user->group_id === null)
      <div>
        <h1 class="page-title auth-card__title">Группа «{{ $group->name }}»</h1>
        <p class="page-sub">Вы вошли как {{ $user->name }} и пока не состоите в группе. Вступите, чтобы видеть тесты группы.</p>
      </div>
      <form method="POST" action="{{ route('join.accept', $code) }}">@csrf
        <button type="submit" class="btn btn--primary btn--block">Вступить в группу</button>
      </form>
    @elseif ($user)
      <div>
        <h1 class="page-title auth-card__title">Вы уже в группе</h1>
        <p class="page-sub">Вы вошли как {{ $user->name }}, группа «{{ $user->group?->name }}». Перевести вас в группу «{{ $group->name }}» может преподаватель.</p>
      </div>
      <a href="{{ route('home') }}" class="btn btn--primary btn--block">К моим тестам</a>
    @else
      <div>
        <div class="lbl">Регистрация студента</div>
        <h1 class="page-title auth-card__title">Группа «{{ $group->name }}»</h1>
        <p class="page-sub">Курс «{{ $group->course->title }}». После регистрации вы сразу увидите тесты своей группы.</p>
      </div>

      @if ($errors->any())
        <div class="note note--warn" role="alert">{{ $errors->first() }}</div>
      @endif

      <form method="POST" action="{{ url('join/'.$code) }}" class="stack" style="gap: 14px" novalidate>
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
