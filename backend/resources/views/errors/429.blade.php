@php
    // Текст из abort(429, '…') показываем, только если его задали намеренно (по-русски и без исходного исключения внутри):
    // в обёрнутых исключениях бывает SQL с данными студентов
    $message = isset($exception) && $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $exception->getPrevious() === null
        ? trim($exception->getMessage()) : '';
@endphp
@include('errors.layout', [
    'code' => 429,
    'heading' => 'Слишком много попыток',
    'text' => $message !== '' && preg_match('/\p{Cyrillic}/u', $message) ? $message : 'Подождите минуту и попробуйте снова.',
])
