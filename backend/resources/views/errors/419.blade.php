@php
    // Текст из abort(419, '…') показываем, только если его задали намеренно (по-русски и без исходного исключения внутри):
    // в обёрнутых исключениях бывает SQL с данными студентов
    $message = isset($exception) && $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $exception->getPrevious() === null
        ? trim($exception->getMessage()) : '';
@endphp
@include('errors.layout', [
    'code' => 419,
    'heading' => 'Страница устарела',
    'text' => $message !== '' && preg_match('/\p{Cyrillic}/u', $message) ? $message : 'Вы долго не обновляли страницу, и сессия истекла. Обновите её и повторите действие — введённые данные, возможно, придётся заполнить заново.',
])
