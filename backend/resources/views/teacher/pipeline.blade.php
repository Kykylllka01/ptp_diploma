@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page" style="gap: 28px">
  <div class="page-head">
    <div>
      <div class="lbl" id="run-label">Генерация вопросов</div>
      <h1 class="page-title" id="title">Агенты работают над вопросами</h1>
      <p class="page-sub" id="eta"></p>
    </div>
    <div class="actions">
      <button type="button" class="btn" id="stop">Остановить</button>
      <a href="bank" class="btn btn--primary">Открыть банк вопросов</a>
    </div>
  </div>

  <div class="card" style="padding: 20px 24px">
    <div class="between small muted"><span>Общий прогресс</span><span class="mono" id="total-label">0 / 0</span></div>
    <div class="progress progress--lg" style="margin-top: 10px"><div id="total-bar" style="width: 0%"></div></div>
  </div>

  <div class="grid-stats" id="agents"></div>

  <div class="split">
    <section class="card card--pad split__main" style="gap: 0">
      <h2 class="card-title">Только что созданы</h2>
      <div class="stack-sm" style="margin-top: 14px; gap: 12px" id="fresh"></div>
    </section>
    <aside class="card log split__side" style="flex-basis: 340px">
      <div class="log__head"><span>Журнал агентов</span><span class="mono xsmall" style="color: #9AA1B0" id="live">обновляется</span></div>
      <div class="log__body" id="log" aria-live="polite"></div>
    </aside>
  </div>
</main>
@endsection
