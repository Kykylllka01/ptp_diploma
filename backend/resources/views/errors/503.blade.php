@php
    // Сообщение из abort(…, 'текст') показываем, если оно по-русски; иначе — общий текст
    $message = isset($exception) ? trim($exception->getMessage()) : '';
@endphp
@include('errors.layout', [
    'code' => 503,
    'heading' => 'Сайт на обслуживании',
    'text' => $message !== '' && preg_match('/\p{Cyrillic}/u', $message) ? $message : 'Мы скоро вернёмся. Попробуйте открыть страницу через несколько минут.',
])
