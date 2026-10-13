@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page" style="padding-top: 32px; gap: 20px">
  <div class="between">
    <div>
      <a href="analytics" class="back">← Аналитика</a>
      <h1 class="page-title">Проверка развёрнутых ответов</h1>
    </div>
    <div class="row-wrap" style="gap: 12px"><span class="muted" style="font-size: 15px" id="progress" aria-live="polite"></span>
      <select class="select select--sm" id="test-filter" aria-label="Тест" style="width: auto; min-width: 200px" hidden></select></div>
  </div>

  <div class="split">
    <aside class="card card--clip split__nav">
      <div style="padding: 14px 16px 10px" class="strong">Очередь</div>
      <div style="padding: 0 12px 8px" id="owner"></div>
      <div style="padding: 0 12px 12px" id="queue-filters"></div>
      <div id="queue"></div>
    </aside>

    <div class="split__main" style="flex-basis: 640px; gap: 20px">
      <section class="card card--pad">
        <div class="lbl" id="q-label" tabindex="-1"></div>
        <div id="q-context"></div>
        <p style="margin: 10px 0 0; font-size: 17px; line-height: 1.55; font-weight: 500" id="q-text"></p>
        <details style="margin-top: 14px; font-size: 14px; color: var(--text-2)">
          <summary style="cursor: pointer; color: var(--tsu-blue); font-weight: 500">Эталонный ответ</summary>
          <p style="margin: 10px 0 0; line-height: 1.6" id="q-ref"></p>
        </details>
      </section>

      <section class="card card--pad">
        <div class="between"><h2 class="card-title card-title--sm">Ответ студента</h2><span class="muted xsmall" id="a-meta"></span></div>
        <p class="answer-box" id="a-text"></p>
        <div class="note note--warn" id="appeal" style="margin-top: 12px" hidden></div>
      </section>

      <section class="card" style="padding: 8px 24px 20px">
        <div class="between" style="padding: 14px 0 6px"><h2 class="card-title card-title--sm">Оценка по критериям</h2><span id="confidence"></span></div>
        <div id="criteria"></div>
        <label class="field" style="margin-top: 6px">
          <span>Комментарий студенту <span class="field__hint">черновик агента — можно править</span></span>
          <textarea class="textarea" rows="3" id="comment"></textarea>
        </label>
        <div class="between" style="margin-top: 18px">
          <div style="font-size: 18px" aria-live="polite"><b style="font-weight: 700" id="total">0</b> <span class="muted" id="max"></span></div>
          <div class="actions actions--sm"><button type="button" class="btn" id="skip">Пропустить</button><button type="button" class="btn btn--primary" id="confirm">Подтвердить и далее</button></div>
        </div>
      </section>
    </div>
  </div>
</main>
@endsection
