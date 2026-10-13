<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        // Вся группа сидит за одним вузовским Wi-Fi (один внешний IP), поэтому лимиты считаются
        // по пользователю или по почте, а общий лимит на IP рассчитан на поток в 200+ студентов.
        // API: автосохранение, прокторинг и навигация активного студента — до нескольких запросов в секунду.
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(300)->by('user:'.$request->user()->id)
                : Limit::perMinute(120)->by('ip:'.$request->ip());
        });
        // Вход: подбор пароля к одной почте ограничен жёстко, а сам IP — с запасом на всю аудиторию
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('login:'.mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(600)->by('login-ip:'.$request->ip()),
        ]);
        // Регистрация по приглашению: группа регистрируется разом с одного IP
        RateLimiter::for('join', fn (Request $request) => [
            Limit::perMinute(10)->by('join:'.mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(400)->by('join-ip:'.$request->ip()),
        ]);

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
