@php
    // Текст из abort(404, '…') показываем, только если его задали намеренно (по-русски и без исходного исключения внутри):
    // в обёрнутых исключениях бывает SQL с данными студентов
    $message = isset($exception) && $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $exception->getPrevious() === null
        ? trim($exception->getMessage()) : '';
@endphp
@include('errors.layout', [
    'code' => 404,
    'heading' => 'Страница не найдена',
    'text' => $message !== '' && preg_match('/\p{Cyrillic}/u', $message) ? $message : 'Возможно, ссылка устарела или тест, вопрос или материал уже удалили.',
])
