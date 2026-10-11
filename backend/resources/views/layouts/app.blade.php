<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ?? 'Банк вопросов' }} — ТюмГУ</title>
<link rel="icon" href="{{ asset('assets/img/logo.png') }}">
@isset($appData)
<script>window.APP_DATA = @json($appData);</script>
@endisset
@vite(array_filter(['resources/css/app.css', 'resources/js/app.js', isset($script) ? 'resources/js/pages/'.$script.'.js' : null]))
</head>
<body>
@yield('header')

@yield('content')
</body>
</html>
