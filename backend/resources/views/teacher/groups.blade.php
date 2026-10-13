@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page">
  <div class="page-head">
    <div>
      <div class="lbl">{{ $appData['course']['title'] }}</div>
      <h1 class="page-title">Группы</h1>
      <p class="page-sub">Отправьте ссылку группы студентам: по ней они сами зарегистрируются и сразу попадут в свою группу.</p>
    </div>
    <form class="row-wrap" id="new-group" style="gap: 10px" novalidate>
      <label class="sr-only" for="new-group-name">Название новой группы</label>
      <input class="input" id="new-group-name" placeholder="Например, ИСиТ-23-1" maxlength="100" style="width: 240px" autocomplete="off">
      <button type="submit" class="btn btn--primary">Создать группу</button>
    </form>
  </div>

  <div class="note note--info">
    <span>{!! '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0072BC" stroke-width="2" stroke-linecap="round" aria-hidden="true" style="flex:none"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>' !!}</span>
    <span>Тест назначается группам при сборке. Когда все студенты зарегистрировались, закройте регистрацию по ссылке — тогда её нельзя будет переслать посторонним. Если ссылка ушла не туда, выпустите новую: старая перестанет работать.</span>
  </div>

  <div class="grid-groups" id="groups"></div>

  <section class="card card--pad" id="ungrouped-card" hidden>
    <h2 class="card-title card-title--sm">Студенты без группы</h2>
    <p class="muted small" style="margin: 6px 0 0">Видят только тесты для всех студентов курса. Переведите их в группу.</p>
    <div id="ungrouped" style="margin-top: 8px"></div>
  </section>
</main>
@endsection
