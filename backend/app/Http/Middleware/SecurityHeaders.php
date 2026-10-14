<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Защитные заголовки на каждом ответе и переход на HTTPS: сайт с адресом https:// по обычному
 * HTTP не открывается, иначе пароль и cookie сессии шли бы открытым текстом через вузовский Wi-Fi.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $url = (string) config('app.url');
        if (app()->isProduction() && str_starts_with($url, 'https://') && ! $request->secure()) {
            // Адрес берём из настроек, а не из заголовка Host: его присылает клиент
            return redirect()->to(rtrim($url, '/').$request->getRequestUri(), 301);
        }

        $response = $next($request);
        $headers = $response->headers;
        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            // Страницы сайта нельзя встроить в чужую страницу (кликджекинг: подложить кнопку «Завершить тест»)
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "frame-ancestors 'none'; base-uri 'self'; object-src 'none'; form-action 'self'",
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        ];
        if ($request->secure()) {
            // Без includeSubDomains: на соседних поддоменах домена работают другие сайты
            $defaults['Strict-Transport-Security'] = 'max-age=31536000';
        }
        foreach ($defaults as $name => $value) {
            // Свой заголовок ответа (например, CSP sandbox у текстового файла) важнее общего
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        return $response;
    }
}
