@extends('layouts.app')

@section('header')
    @include('partials.header-teacher')
@endsection

@section('content')
<main class="page" style="gap: 28px">
  <div class="page-head">
    <div>
      <div class="lbl">{{ $appData['course']['title'] }}</div>
      <h1 class="page-title">Материалы</h1>
      <p class="page-sub">Лекции и конспекты курса. По ним агенты составляют вопросы для банка.</p>
    </div>
    <div class="actions"><a href="#library-card" class="btn" id="to-library">Загруженные материалы</a></div>
  </div>

  <div id="new-set" style="scroll-margin-top: 24px">
    <h2 class="card-title">Новый набор вопросов</h2>
    <ol class="steps" style="margin-top: 16px" aria-label="Шаги">
      <li class="is-current" aria-current="step"><span>1</span>Материалы</li>
      <li class="is-current"><span>2</span>Параметры генерации</li>
      <li><span>3</span>Работа агентов</li>
      <li><span>4</span>Проверка вопросов</li>
    </ol>
  </div>

  <form class="split" id="gen-form">
    <div class="split__main">
      <section class="card card--pad">
        <h2 class="card-title">1. Материалы</h2>
        <label class="dropzone" id="dropzone">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#0072BC" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M4 20h16"/></svg>
          <span style="font-weight: 600; font-size: 17px">Перетащите файлы или выберите на компьютере</span>
          <span class="muted small">Только PDF, DOCX, PPTX и TXT · до 50 МБ на файл</span>
          <input type="file" multiple id="file-input" accept=".pdf,.docx,.pptx,.txt">
        </label>
        <div class="row-wrap" style="margin-top: 12px; gap: 10px">
          <button type="button" class="btn btn--sm" data-add="URL">Добавить ссылку</button>
          <button type="button" class="btn btn--sm" data-add="TXT">Вставить текст</button>
          <button type="button" class="btn btn--sm" data-add="old">Из уже загруженных</button>
        </div>
        <div class="stack-sm" style="margin-top: 20px" id="files"></div>
      </section>

      <section class="card card--pad">
        <h2 class="card-title">2. Какие вопросы создать</h2>
        <div class="lbl" style="margin-top: 20px">Типы вопросов</div>
        <div style="margin-top: 10px; display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px">
          <label class="opt"><input type="checkbox" name="types[]" value="single" checked><span><b class="strong">Один верный ответ</b><br><span class="opt__desc">4 варианта, правдоподобные дистракторы</span></span></label>
          <label class="opt"><input type="checkbox" name="types[]" value="multiple" checked><span><b class="strong">Несколько верных</b><br><span class="opt__desc">2–3 верных из 5–6</span></span></label>
          <label class="opt"><input type="checkbox" name="types[]" value="tf"><span><b class="strong">Верно / неверно</b><br><span class="opt__desc">Проверка утверждений</span></span></label>
          <label class="opt"><input type="checkbox" name="types[]" value="match"><span><b class="strong">Сопоставление</b><br><span class="opt__desc">Термин ↔ определение</span></span></label>
          <label class="opt"><input type="checkbox" name="types[]" value="sql" checked><span><b class="strong">SQL-запрос</b><br><span class="opt__desc">Проверка на учебной базе</span></span></label>
          <label class="opt"><input type="checkbox" name="types[]" value="open" checked><span><b class="strong">Развёрнутый ответ</b><br><span class="opt__desc">С эталоном и критериями оценки</span></span></label>
        </div>

        <div class="grid-fields" style="margin-top: 24px">
          <label class="field">Количество вопросов<input class="input" type="number" name="count" value="12" min="5" max="300"></label>
          <label class="field">Сложность<select class="select" name="difficulty"><option value="mixed">Сбалансированно</option><option value="easy">Больше простых</option><option value="hard">Больше сложных</option></select></label>
          <label class="field">Уровень мышления<select class="select" name="bloom"><option>Знание + понимание + применение</option><option>Только знание</option><option>Применение и анализ</option></select></label>
          <label class="field">Язык<select class="select" name="lang"><option value="ru">Русский</option><option value="en">English</option></select></label>
        </div>

        <label class="field" style="margin-top: 20px">
          <span>Указания для агентов <span class="field__hint">необязательно</span></span>
          <textarea class="textarea" name="notes" rows="3" placeholder="Например: больше задач на применение, не спрашивать историю СУБД"></textarea>
        </label>
      </section>
    </div>

    <aside class="card card--pad split__side" style="display: flex; flex-direction: column; gap: 16px">
      <h2 class="card-title--sm card-title">Что произойдёт дальше</h2>
      <div class="stack" style="gap: 14px; font-size: 15px">
        <div style="display: flex; gap: 12px"><span class="mono muted" style="width: 20px">01</span><div><b class="strong">Аналитик</b> разберёт материалы на темы и понятия</div></div>
        <div style="display: flex; gap: 12px"><span class="mono muted" style="width: 20px">02</span><div><b class="strong">Составитель</b> напишет вопросы и эталонные ответы</div></div>
        <div style="display: flex; gap: 12px"><span class="mono muted" style="width: 20px">03</span><div><b class="strong">Критик</b> проверит однозначность, опору на источник и дубли</div></div>
        <div style="display: flex; gap: 12px"><span class="mono muted" style="width: 20px">04</span><div>Спорные вопросы попадут к вам на проверку</div></div>
      </div>
      @if (config('agents.driver') === 'fake')
      <div class="note">Сейчас агенты работают как заготовки без нейросети: вопросы строятся по шаблонам.</div>
      @else
      <div class="note">Агенты работают на нейросети: запуск по одной лекции занимает несколько минут, ход работы виден на следующем экране.</div>
      @endif
      <button type="submit" class="btn btn--ai btn--block">Запустить агентов</button>
    </aside>
  </form>

  <section class="card card--pad" id="library-card" style="scroll-margin-top: 24px">
    <div class="between"><h2 class="card-title">Загруженные материалы</h2><span class="muted small" id="library-count"></span></div>
    <div class="toolbar" id="lib-tools" style="margin-top: 16px" hidden>
      <label class="search"><span class="sr-only">Поиск по названию</span><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#5A6070" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input type="search" id="lib-search" placeholder="Найти материал по названию" autocomplete="off"></label>
      <div id="lib-owner"></div>
      <div id="lib-filters"></div>
      <div class="toolbar__end">
        <select class="select select--sm" id="lib-kind" aria-label="Тип файла" style="width: auto" hidden></select>
        <select class="select select--sm" id="lib-sort" aria-label="Порядок" style="width: auto">
          <option value="new">Сначала новые</option>
          <option value="old">Сначала старые</option>
          <option value="title">По названию</option>
          <option value="questions">Больше вопросов</option>
          <option value="size">Крупнее</option>
        </select>
      </div>
    </div>
    <div class="result-line" id="lib-result" aria-live="polite"></div>
    <div id="library" style="margin-top: 8px"></div>
    <div class="between small muted" id="lib-more-row" style="margin-top: 12px" hidden><span id="lib-shown"></span><button type="button" class="btn btn--sm" id="lib-more">Показать ещё</button></div>
  </section>
</main>
@endsection
