@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page">
  <div class="page-head">
    <div>
      <div class="lbl">{{ $appData['course']['title'] }}</div>
      <h1 class="page-title">Журнал</h1>
      <p class="page-sub">Баллы лучшей попытки каждого студента. Список идёт по алфавиту, как группа в Модеусе, — столбец теста можно скопировать и вставить туда целиком.</p>
    </div>
    <div class="actions" style="align-items: center">
      <select class="select" id="group" aria-label="Группа" style="width: auto; min-width: 200px"></select>
      <button type="button" class="btn" id="export">Ведомость в Excel</button>
    </div>
  </div>

  <details class="note note--info how" id="how">
    <summary class="strong">Как перенести баллы в Модеус без интеграции</summary>
    <ol>
      <li>Дождитесь, пока все баллы теста станут итоговыми: жёлтые — предварительные, развёрнутые ответы ещё не подтверждены (<a href="review">проверить</a>).</li>
      <li>Нажмите <b>«Копировать»</b> в заголовке теста — баллы скопируются по одному на строку, в порядке списка группы.</li>
      <li>В Модеусе откройте журнал группы, встаньте в первую ячейку этого контрольного мероприятия и вставьте (Ctrl+V). Если вставка столбцом не сработает, вносите по одному — порядок фамилий тот же, номер в столбце «№».</li>
      <li>Сверьте 2–3 фамилии и в меню «⋯» теста выберите <b>«Отметить: перенесено в Модеус»</b> — дата останется в журнале, чтобы не перенести дважды.</li>
    </ol>
  </details>

  <section class="card card--pad" id="journal-card">
    <div class="toolbar" id="journal-tools">
      <label class="search"><span class="sr-only">Поиск студента</span><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#5A6070" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input type="search" id="search" placeholder="Найти студента" autocomplete="off"></label>
      <div id="filters"></div>
    </div>
    <div class="result-line" id="result-line" aria-live="polite"></div>
    <div class="table-wrap journal-wrap">
      <table class="journal" id="journal"></table>
    </div>
    <div class="row-wrap xsmall muted journal-legend" id="legend"></div>
  </section>
</main>
@endsection
