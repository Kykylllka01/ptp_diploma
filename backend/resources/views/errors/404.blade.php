@php
    // Сообщение из abort(…, 'текст') показываем, если оно по-русски; иначе — общий текст
    $message = isset($exception) ? trim($exception->getMessage()) : '';
@endphp
@include('errors.layout', [
    'code' => 404,
    'heading' => 'Страница не найдена',
    'text' => $message !== '' && preg_match('/\p{Cyrillic}/u', $message) ? $message : 'Возможно, ссылка устарела или тест, вопрос или материал уже удалили.',
])
