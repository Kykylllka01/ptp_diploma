@php
    // Сообщение из abort(…, 'текст') показываем, если оно по-русски; иначе — общий текст
    $message = isset($exception) ? trim($exception->getMessage()) : '';
@endphp
@include('errors.layout', [
    'code' => 403,
    'heading' => 'Нет доступа',
    'text' => $message !== '' && preg_match('/\p{Cyrillic}/u', $message) ? $message : 'Эта страница недоступна для вашей учётной записи. Если вы считаете, что это ошибка, напишите преподавателю.',
])
