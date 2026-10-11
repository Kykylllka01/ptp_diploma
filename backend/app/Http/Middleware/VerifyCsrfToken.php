<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;

/**
 * CSRF-защита. С Laravel 13 базовый класс — PreventRequestForgery: кроме токена он проверяет
 * заголовок Sec-Fetch-Site (запрос пришёл с того же сайта). Имя класса оставлено прежним,
 * на него ссылается app/Http/Kernel.php.
 */
class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];
}
