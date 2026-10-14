<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пароль сменили (в кабинете или преподаватель выдал временный) — сессии на других устройствах заканчиваются.
 * В сессии хранится отпечаток пароля пользователя; при следующем запросе со старым отпечатком — выход.
 * В отличие от AuthenticateSession, отпечаток свой у каждого пользователя: сессия, где сначала был один
 * пользователь, а затем вошёл другой, не считается «чужой».
 * Так же заканчиваются сессии аккаунта, которому администратор закрыл доступ.
 */
class EndSessionAfterPasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Администратор закрыл доступ — уже открытые сессии тоже заканчиваются
        if ($user && $user->disabled_at !== null && $request->hasSession()) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'Доступ к аккаунту закрыт администратором'], 401)
                : redirect()->route('login')->withErrors(['email' => 'Доступ к аккаунту закрыт администратором']);
        }
        if ($user && $request->hasSession()) {
            $stored = $request->session()->get('password_print.'.$user->getAuthIdentifier());
            if ($stored !== null && ! hash_equals($stored, $this->print($user->getAuthPassword()))) {
                Auth::guard()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson()
                    ? response()->json(['message' => 'Пароль изменён — войдите заново'], 401)
                    : redirect()->route('login')->withErrors(['email' => 'Пароль изменён — войдите с новым паролем']);
            }
        }

        $response = $next($request);

        // После запроса: пароль могли сменить в этом же запросе — тогда текущая сессия остаётся с новым отпечатком
        $current = Auth::guard()->user();
        if ($current && $request->hasSession()) {
            $request->session()->put('password_print.'.$current->getAuthIdentifier(), $this->print($current->getAuthPassword()));
        }

        return $response;
    }

    private function print(string $hash): string
    {
        return hash_hmac('sha256', $hash, (string) config('app.key'));
    }
}
