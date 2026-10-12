@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page">
  <div class="page-head">
    <div>
      <div class="lbl">Базы данных</div>
      <h1 class="page-title">Журнал</h1>
      <p class="page-sub">Баллы лучшей попытки каждого студента. Студенты по алфавиту — как в списке группы в Модеусе, поэтому столбец теста можно скопировать и вставить в Модеус целиком.</p>
    </div>
    <div class="actions" style="align-items: center">
      <select class="select" id="group" aria-label="Группа" style="width: auto; min-width: 200px"></select>
      <button type="button" class="btn" id="export">Ведомость в Excel</button>
    </div>
  </div>

  <details class="note note--info how" id="how">
    <summary class="strong">Как перенести баллы в Модеус без интеграции</summary>
    <ol>
      <li>Дождитесь, пока все ячейки теста станут «итог»: жёлтые — это предварительные баллы, развёрнутые ответы ещё не подтверждены (<a href="review">проверить</a>).</li>
      <li>Нажмите <b>«Копировать столбец»</b> у теста — баллы скопируются по одному на строку в порядке списка.</li>
      <li>В Модеусе откройте журнал группы, встаньте в первую ячейку этого контрольного мероприятия и вставьте (Ctrl+V). Если вставка столбцом не сработает, вносите по столбцу «Баллы» отсюда — порядок фамилий тот же.</li>
      <li>Сверьте 2–3 фамилии и отметьте <b>«Перенесено»</b> — дата останется в журнале, чтобы не перенести дважды.</li>
    </ol>
  </details>

  <section class="card card--pad">
    <div class="row-wrap" style="gap: 14px; margin-bottom: 4px" id="legend"></div>
    <div class="table-wrap journal-wrap">
      <table class="table journal" id="journal"></table>
    </div>
  </section>
</main>
@endsection
