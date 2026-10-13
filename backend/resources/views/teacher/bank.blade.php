@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page">
  <div class="page-head">
    <div>
      <div class="lbl">{{ $appData['course']['title'] }}</div>
      <h1 class="page-title">Банк вопросов</h1>
    </div>
    <div class="actions">
      <a href="/api/questions-export" class="btn" id="export" title="Одобренные вопросы в формате Moodle XML: их можно импортировать в курс LMS">Экспорт в Moodle</a>
      <button type="button" class="btn" id="add-manual">Добавить вручную</button>
      <a href="upload" class="btn btn--ai">Сгенерировать ещё</a>
    </div>
  </div>

  <div class="row-wrap" style="justify-content: space-between; gap: 12px">
    <div class="tabs" role="group" aria-label="Статус вопросов" id="tabs"></div>
    <div id="owner"></div>
  </div>

  <div class="split">
    <aside class="card split__nav" style="max-width: 100%; padding: 20px; display: flex; flex-direction: column; gap: 22px">
      <div>
        <div class="lbl">Тип</div>
        <div style="margin-top: 8px" id="f-types"></div>
      </div>
      <div>
        <div class="lbl">Сложность</div>
        <div class="row-wrap" style="margin-top: 10px; gap: 6px" id="f-diff"></div>
      </div>
      <div>
        <div class="lbl">Тема</div>
        <div style="margin-top: 8px" id="f-topics"></div>
      </div>
      <div>
        <label class="lbl" for="f-source">Источник</label>
        <select class="select" id="f-source" style="margin-top: 10px"></select>
      </div>
      <button type="button" class="btn btn--sm" id="f-reset">Сбросить фильтры</button>
    </aside>

    <section class="split__main" style="flex-basis: 640px; gap: 14px">
      <div class="row-wrap" style="gap: 12px">
        <label class="search"><span id="search-icon"></span><input type="search" id="search" placeholder="Поиск по тексту вопроса" aria-label="Поиск по тексту вопроса"></label>
        <select class="select" id="sort" aria-label="Сортировка" style="width: auto"><option value="flag">Сначала спорные</option><option value="new">Новые</option><option value="topic">По теме</option></select>
        <button type="button" class="btn btn--sm btn--accent" id="select-clean" title="Отметить все показанные вопросы, которые Критик проверил без замечаний, — затем одобрить их одной кнопкой">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
          Выбрать без замечаний Критика <span class="btn__count" id="clean-count">0</span>
        </button>
      </div>

      <div class="bulk" id="bulk" hidden>
        <span id="bulk-count">Выбрано 0</span>
        <span style="flex: 1"></span>
        <button type="button" class="btn btn--xs btn--ghost-dark" data-bulk="approve">Одобрить</button>
        <button type="button" class="btn btn--xs btn--ghost-dark" data-bulk="reject">Отклонить</button>
        <button type="button" class="btn btn--xs btn--ghost-dark btn--ai-soft" data-bulk="regen">Перегенерировать</button>
        <button type="button" class="btn btn--xs btn--ghost-dark" data-bulk="test">Добавить в тест</button>
        <button type="button" class="btn btn--xs btn--ghost-dark" data-bulk="export">В Moodle XML</button>
      </div>

      <div class="card card--clip" id="list"></div>
      <div class="between small muted"><span id="shown"></span><button type="button" class="btn btn--sm" id="more">Показать ещё</button></div>
    </section>
  </div>
</main>
@endsection
