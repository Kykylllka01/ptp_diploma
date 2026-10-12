@extends('layouts.app')

@section('header')
    @include($user->isTeacher() ? 'partials.header-teacher' : 'partials.header-student')
@endsection

@section('content')
<main class="page">
  <div class="page-head">
    <div>
      <div class="lbl">{{ $user->isTeacher() ? 'Преподаватель' : 'Студент' }} · {{ $course->title }}</div>
      <h1 class="page-title">Личный кабинет</h1>
    </div>
  </div>

  @if (session('status'))
    <div class="note note--info" role="status">{{ session('status') }}</div>
  @endif
  @if ($errors->any())
    <div class="note note--warn" role="alert">{{ $errors->first() }}</div>
  @endif

  <div class="split">
    <div class="split__main stack" style="flex-basis: 560px">
      <section class="card card--pad">
        <h2 class="card-title">Профиль</h2>
        <div class="avatar-edit">
          @if ($user->avatarUrl())
            <img class="avatar avatar--xl avatar--img" src="{{ $user->avatarUrl() }}" alt="Ваш аватар">
          @else
            <span class="avatar avatar--xl">{{ $user->initials }}</span>
          @endif
          <div class="stack-sm" style="gap: 8px">
            <div class="row-wrap">
              <form method="POST" action="{{ route('profile.avatar') }}" enctype="multipart/form-data" id="avatar-form">
                @csrf
                <label class="btn btn--sm btn--file">{{ $user->avatar_path ? 'Заменить фото' : 'Загрузить фото' }}<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" class="sr-only" onchange="this.form.submit()"></label>
              </form>
              @if ($user->avatar_path)
                <form method="POST" action="{{ route('profile.avatar.delete') }}">
                  @csrf @method('DELETE')
                  <button type="submit" class="btn btn--sm">Удалить</button>
                </form>
              @endif
            </div>
            <div class="muted xsmall">JPG, PNG или WebP до 5 МБ. Фото обрежется до квадрата по центру.</div>
          </div>
        </div>
        <form method="POST" action="{{ route('profile.update') }}" class="stack" style="margin-top: 18px; gap: 16px">
          @csrf
          <div class="grid-fields">
            <label class="field">Как подписывать<input class="input" name="name" value="{{ old('name', $user->name) }}" required maxlength="100"></label>
            <label class="field"><span>Обращение <span class="field__hint">в приветствии</span></span><input class="input" name="full_name" value="{{ old('full_name', $user->full_name) }}" maxlength="150"></label>
          </div>
          <div class="grid-fields">
            <label class="field">Почта для входа<input class="input" value="{{ $user->email }}" disabled></label>
            @unless ($user->isTeacher())
              <label class="field">Группа<input class="input" value="{{ $user->group?->name ?: '—' }}" disabled></label>
            @endunless
          </div>
          <button type="submit" class="btn btn--primary" style="align-self: flex-start">Сохранить</button>
        </form>
      </section>

      <section class="card card--pad">
        <h2 class="card-title">Смена пароля</h2>
        <form method="POST" action="{{ route('profile.password') }}" class="stack" style="margin-top: 18px; gap: 16px">
          @csrf
          <label class="field">Текущий пароль<input class="input" type="password" name="current_password" autocomplete="current-password" required></label>
          <div class="grid-fields">
            <label class="field"><span>Новый пароль <span class="field__hint">от 8 символов</span></span><input class="input" type="password" name="password" autocomplete="new-password" required minlength="8"></label>
            <label class="field">Повтор<input class="input" type="password" name="password_confirmation" autocomplete="new-password" required minlength="8"></label>
          </div>
          <button type="submit" class="btn" style="align-self: flex-start">Изменить пароль</button>
        </form>
      </section>
    </div>

    <aside class="split__side stack" style="flex-basis: 380px">
      @if ($user->isTeacher())
        <section class="card card--pad">
          <h2 class="card-title">Курс «{{ $course->title }}»</h2>
          <div class="stack-sm" style="margin-top: 14px">
            @foreach ($stats as [$label, $value])
              <div class="between"><span class="muted">{{ $label }}</span><b class="strong">{{ $value }}</b></div>
            @endforeach
          </div>
          <div class="row-wrap" style="margin-top: 16px">
            <a href="{{ route('teacher.tests') }}" class="btn btn--sm">Тесты</a>
            <a href="{{ route('teacher.bank') }}" class="btn btn--sm">Банк вопросов</a>
          </div>
        </section>
        <section class="card card--pad">
          <h2 class="card-title">Нейросеть</h2>
          <p class="muted small" style="margin: 8px 0 0">Обращения агентов к модели и расход токенов — для экспериментальной главы.</p>
          <div class="stack-sm" style="margin-top: 14px">
            <div class="between"><span class="muted">Обращений</span><b class="strong">{{ $llm['calls'] }}</b></div>
            <div class="between"><span class="muted">Токенов</span><b class="strong">{{ number_format($llm['tokens'], 0, ',', ' ') }}</b></div>
            @foreach ($llm['byAgent'] as $row)
              <div class="between small"><span class="muted">{{ \Illuminate\Support\Str::ucfirst(\App\Models\AgentLog::LABELS[$row->agent] ?? $row->agent) }}</span><span>{{ $row->n }} · {{ number_format((int) $row->t, 0, ',', ' ') }} ток.</span></div>
            @endforeach
          </div>
        </section>
      @else
        <section class="card card--pad">
          <h2 class="card-title">Мои результаты</h2>
          <div class="stack-sm" style="margin-top: 14px">
            @forelse ($attempts as $a)
              <a href="{{ route('student.result', ['attempt' => $a->id]) }}" class="between" style="text-decoration: none; color: inherit">
                <span>{{ $a->test->title }}<br><span class="muted xsmall">{{ $a->submitted_at?->translatedFormat('j F, H:i') }} · {{ $a->status === 'confirmed' ? 'итог подтверждён' : 'предварительно' }}</span></span>
                <b class="strong">{{ $a->percent }}%</b>
              </a>
            @empty
              <div class="muted small">Сданных тестов пока нет</div>
            @endforelse
          </div>
        </section>
      @endif
    </aside>
  </div>
</main>
@endsection
