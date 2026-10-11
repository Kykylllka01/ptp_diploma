@php
    $nav = [
        'tests' => ['Мои тесты', route('student.tests')],
        'results' => ['Результаты', route('student.result')],
    ];
    $student = $appData['student'];
@endphp
<header class="site-header">
  <div class="site-header__inner">
    <a href="{{ route('student.tests') }}" class="brand" aria-label="ТюмГУ — на главную"><img src="{{ asset('assets/img/logo-utmn.svg') }}" alt="ТюмГУ"></a>
    <nav class="nav" aria-label="Основное меню">
      @foreach ($nav as $key => [$label, $href])
        <a href="{{ $href }}" @if ($key === ($active ?? '')) class="on" aria-current="page" @endif>{{ $label }}</a>
      @endforeach
    </nav>
    <div class="user">
      <span class="chip chip--green">Студент</span>
      <a href="{{ route('profile') }}" class="user__link" title="Личный кабинет" @if (($active ?? '') === 'profile') aria-current="page" @endif><span class="user__name">{{ $student['name'] }}</span><span class="avatar avatar--gray">{{ $student['initials'] }}</span></a>
      <form method="POST" action="{{ route('logout') }}" style="margin: 0">@csrf<button type="submit" class="btn btn--sm">Выйти</button></form>
    </div>
  </div>
</header>
