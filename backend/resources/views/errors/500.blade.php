@php
    // Сообщение из abort(…, 'текст') показываем, если оно по-русски; иначе — общий текст
    $message = isset($exception) ? trim($exception->getMessage()) : '';
@endphp
@include('errors.layout', [
    'code' => 500,
    'heading' => 'Что-то пошло не так',
    'text' => $message !== '' && preg_match('/\p{Cyrillic}/u', $message) ? $message : 'На сервере произошла ошибка. Попробуйте ещё раз через минуту; если не поможет — напишите преподавателю.',
])
