@php
    // Сообщение из abort(…, 'текст') показываем, если оно по-русски; иначе — общий текст
    $message = isset($exception) ? trim($exception->getMessage()) : '';
@endphp
@include('errors.layout', [
    'code' => 419,
    'heading' => 'Страница устарела',
    'text' => $message !== '' && preg_match('/\p{Cyrillic}/u', $message) ? $message : 'Вы долго не обновляли страницу, и сессия истекла. Обновите её и повторите действие — введённые данные, возможно, придётся заполнить заново.',
])
