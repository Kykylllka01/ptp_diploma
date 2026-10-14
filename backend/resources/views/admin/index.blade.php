@extends('layouts.app')

@section('header')
    @include('partials.header-admin')
@endsection

@section('content')
<main class="page">
  <div>
    <div class="lbl">Администрирование</div>
    <h1 class="page-title">Преподаватели</h1>
    <p class="page-sub">Создайте ссылку-приглашение и отправьте её преподавателю: по ней он зарегистрируется сам и сразу попадёт в кабинет преподавателя.</p>
  </div>

  <div class="grid-stats" id="stats"></div>

  <section class="card card--pad">
    <h2 class="card-title">Новая ссылка-приглашение</h2>
    <form id="invite-form" class="invite-form" novalidate>
      <label class="field"><span>Для кого <span class="field__hint">необязательно, видите только вы</span></span>
        <input class="input" id="inv-note" maxlength="120" placeholder="Например, Петрова А. С., кафедра ИБ" autocomplete="off"></label>
      <label class="field">Сколько регистраций
        <select class="select" id="inv-uses"><option value="1" selected>Одна — для одного человека</option><option value="5">До 5 человек</option><option value="10">До 10 человек</option><option value="30">До 30 человек</option></select></label>
      <label class="field">Действует
        <select class="select" id="inv-days"><option value="1">1 день</option><option value="3">3 дня</option><option value="7" selected>7 дней</option><option value="30">30 дней</option></select></label>
      <button type="submit" class="btn btn--primary" id="inv-create">Создать ссылку</button>
    </form>
    <div id="inv-new" style="margin-top: 16px" hidden></div>
  </section>

  <section class="card card--pad">
    <div class="between"><h2 class="card-title">Ссылки-приглашения</h2><div id="inv-filters"></div></div>
    <div class="table-wrap">
      <table class="table table--stack" style="min-width: 760px"><caption class="sr-only">Ссылки-приглашения преподавателей</caption>
        <thead><tr><th>Для кого</th><th>Регистрации</th><th>Действует до</th><th>Статус</th><th class="right"><span class="sr-only">Действия</span></th></tr></thead>
        <tbody id="invites"></tbody>
      </table>
    </div>
  </section>

  <section class="card card--pad" aria-labelledby="llm-title">
    <div class="between"><h2 class="card-title" id="llm-title">Нейросеть</h2><span class="muted small" id="llm-rate"></span></div>
    <p class="muted small" style="margin: 8px 0 0">Сколько раз агенты обращались к нейросети DeepSeek, сколько текста (токенов) это заняло и сколько стоило. Стоимость считается по ценам DeepSeek и переводится в рубли по курсу доллара.</p>
    <div class="grid-stats" id="llm-stats" style="margin-top: 16px"></div>
    <div class="table-wrap">
      <table class="table table--stack" style="min-width: 560px"><caption class="sr-only">Расход нейросети по агентам</caption>
        <thead><tr><th>Агент</th><th class="num-col">Обращений</th><th class="num-col">Токенов</th><th class="num-col">Стоимость</th></tr></thead>
        <tbody id="llm-agents"></tbody>
      </table>
    </div>
  </section>

  <section class="card card--pad">
    <div class="between"><h2 class="card-title">Аккаунты преподавателей</h2>
      <label class="search" id="t-search-box" hidden><span class="sr-only">Найти преподавателя</span><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#5A6070" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input type="search" id="t-search" placeholder="Фамилия или почта" autocomplete="off"></label>
    </div>
    <div class="table-wrap">
      <table class="table table--stack" style="min-width: 820px"><caption class="sr-only">Преподаватели</caption>
        <thead><tr><th>Преподаватель</th><th>Как появился</th><th class="num-col">Материалов</th><th class="num-col">Вопросов</th><th class="num-col">Тестов</th><th>Доступ</th><th class="right"><span class="sr-only">Действия</span></th></tr></thead>
        <tbody id="teachers"></tbody>
      </table>
    </div>
  </section>
</main>
@endsection
