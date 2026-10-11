<header class="site-header test-header">
  <div class="site-header__inner">
    <img src="{{ asset('assets/img/logo-utmn.svg') }}" alt="ТюмГУ">
    <span class="test-header__title">{{ $testTitle ?? '' }}</span>
    <span class="timer" id="timer" role="timer" aria-label="Осталось времени"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2M9 2h6"/></svg><span>--:--</span></span>
    <button class="btn" type="button" id="finish">Завершить тест</button>
  </div>
  <div class="test-progress"><div id="test-progress" style="width:0%"></div></div>
</header>
