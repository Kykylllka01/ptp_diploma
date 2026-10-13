@php
    // Текст из abort(403, '…') показываем, только если его задали намеренно (по-русски и без исходного исключения внутри):
    // в обёрнутых исключениях бывает SQL с данными студентов
    $message = isset($exception) && $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $exception->getPrevious() === null
        ? trim($exception->getMessage()) : '';
@endphp
@include('errors.layout', [
    'code' => 403,
    'heading' => 'Нет доступа',
    'text' => $message !== '' && preg_match('/\p{Cyrillic}/u', $message) ? $message : 'Эта страница недоступна для вашей учётной записи. Если вы считаете, что это ошибка, напишите преподавателю.',
])
