@extends('layouts.app')

@section('header')
    @include('partials.header-test')
@endsection

@section('content')
<main class="page" style="flex-direction: row; flex-wrap: wrap; align-items: flex-start">
  <section class="card player split__main" style="flex-basis: 600px; gap: 0">
    <div class="between">
      <span class="lbl" id="q-label"></span>
      <button type="button" class="btn btn--sm btn--flag" id="flag" aria-pressed="false"></button>
    </div>
    <div id="q-context"></div>
    <h1 class="player__q" id="q-text" tabindex="-1"></h1>
    <div id="q-body" style="margin-top: 28px"></div>
    <div id="feedback" style="margin-top: 16px" aria-live="polite" hidden></div>
    <div class="between" style="margin-top: 32px">
      <button type="button" class="btn" id="prev">← Назад</button>
      <button type="button" class="btn btn--primary" id="next">Следующий вопрос →</button>
    </div>
  </section>

  <aside class="card split__side" style="flex-basis: 300px; padding: 22px">
    <div class="strong">Навигация</div>
    <div class="qnav" id="qnav"></div>
    <div class="legend" id="legend"></div>
    <div class="note xsmall save-status" style="margin-top: 18px" id="autosave" role="status">Ответы сохраняются автоматически</div>
  </aside>
</main>
@endsection
