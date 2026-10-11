@php
    $nav = [
        'dashboard' => ['Обзор', route('teacher.dashboard')],
        'materials' => ['Материалы', route('teacher.upload')],
        'bank' => ['Банк вопросов', route('teacher.bank')],
        'tests' => ['Тесты', route('teacher.tests')],
        'analytics' => ['Аналитика', route('teacher.analytics')],
    ];
    $teacher = $appData['teacher'];
@endphp
<header class="site-header">
  <div class="site-header__inner">
    <a href="{{ route('teacher.dashboard') }}" class="brand" aria-label="ТюмГУ — на главную"><img src="{{ asset('assets/img/logo-utmn.svg') }}" alt="ТюмГУ"></a>
    <nav class="nav" aria-label="Основное меню">
      @foreach ($nav as $key => [$label, $href])
        <a href="{{ $href }}" @if ($key === ($active ?? '')) class="on" aria-current="page" @endif>{{ $label }}</a>
      @endforeach
    </nav>
    <div class="user">
      <span class="chip chip--blue">Преподаватель</span>
      <a href="{{ route('profile') }}" class="user__link" title="Личный кабинет" @if (($active ?? '') === 'profile') aria-current="page" @endif><span class="user__name">{{ $teacher['name'] }}</span><span class="avatar">{{ $teacher['initials'] }}</span></a>
      <form method="POST" action="{{ route('logout') }}" style="margin: 0">@csrf<button type="submit" class="btn btn--sm">Выйти</button></form>
    </div>
  </div>
</header>
