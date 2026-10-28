<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Один вход на учётную запись: новый вход выводит из аккаунта на прежнем устройстве.
 * При входе пользователь получает новый ключ (started), он же кладётся в сессию; сессия со старым
 * ключом при следующем запросе заканчивается. Студент, сменивший устройство посреди теста,
 * продолжает с сохранёнными ответами, а под одним логином двое одновременно не работают.
 */
class OneSessionPerAccount
{
    public const MESSAGE = 'Под этой учётной записью вошли с другого устройства — войдите снова';

    /** Вход состоялся (форма входа, приглашение, «запомнить меня»): прежние сессии этого пользователя больше не действуют. */
    public static function started(User $user): void
    {
        $key = Str::random(40);
        $user->forceFill(['session_key' => $key])->saveQuietly();
        if (app()->bound('session.store')) {
            session()->put('session_key.'.$user->getAuthIdentifier(), $key);
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->session_key !== null && $request->hasSession()) {
            $stored = $request->session()->get('session_key.'.$user->getAuthIdentifier());
            if ($stored === null || ! hash_equals($user->session_key, (string) $stored)) {
                Auth::guard()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson()
                    ? response()->json(['message' => self::MESSAGE], 401)
                    : redirect()->route('login')->withErrors(['email' => self::MESSAGE]);
            }
        }

        return $next($request);
    }
}
