@php
    // Сообщение из abort(…, 'текст') показываем, если оно по-русски; иначе — общий текст
    $message = isset($exception) ? trim($exception->getMessage()) : '';
@endphp
@include('errors.layout', [
    'code' => 429,
    'heading' => 'Слишком много попыток',
    'text' => $message !== '' && preg_match('/\p{Cyrillic}/u', $message) ? $message : 'Подождите минуту и попробуйте снова.',
])
