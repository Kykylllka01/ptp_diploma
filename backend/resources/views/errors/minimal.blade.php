{{-- Ошибка сервера: без Vite, сессии и данных приложения — откроется, даже если сломана сборка или база --}}
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $heading }} — ТюмГУ</title>
<style>
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #F3F4F6; color: #14161B; font: 16px/1.5 system-ui, -apple-system, 'Segoe UI', sans-serif; }
  main { max-width: 440px; margin: 16px; padding: 32px 24px; background: #fff; border: 1px solid #E1E4EA; border-radius: 14px; text-align: center; }
  .code { font-size: 56px; font-weight: 700; color: #E1F2FB; -webkit-text-stroke: 1.5px #0072BC; line-height: 1; }
  h1 { font-size: 26px; margin: 16px 0 8px; }
  p { color: #5A6070; margin: 0 0 20px; }
  a { display: inline-block; padding: 11px 20px; border-radius: 10px; background: #0072BC; color: #fff; text-decoration: none; font-weight: 500; }
</style>
</head>
<body>
<main>
  <div class="code" aria-hidden="true">{{ $code }}</div>
  <h1>{{ $heading }}</h1>
  <p>{{ $text }}</p>
  <a href="/">На главную</a>
</main>
</body>
</html>
