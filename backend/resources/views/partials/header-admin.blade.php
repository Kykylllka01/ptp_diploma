@php($me = auth()->user())
<header class="site-header">
  <div class="site-header__inner">
    <a href="{{ route('admin.index') }}" class="brand" aria-label="ТюмГУ — админ-панель"><img src="{{ asset('assets/img/logo-utmn.svg') }}" alt="ТюмГУ"></a>
    <nav class="nav" aria-label="Основное меню">
      <a href="{{ route('admin.index') }}" @if (($active ?? '') === 'admin') class="on" aria-current="page" @endif>Преподаватели</a>
    </nav>
    <div class="user">
      <span class="chip chip--amber">Администратор</span>
      <a href="{{ route('profile') }}" class="user__link" title="Личный кабинет" @if (($active ?? '') === 'profile') aria-current="page" @endif><span class="user__name">{{ $me->name }}</span><span class="avatar avatar--gray">{{ $me->initials }}</span></a>
      <form method="POST" action="{{ route('logout') }}" style="margin: 0">@csrf<button type="submit" class="btn btn--sm">Выйти</button></form>
    </div>
  </div>
</header>
