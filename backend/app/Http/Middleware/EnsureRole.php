<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пускает только пользователей с нужной ролью: role:teacher, role:student или role:teacher,student.
 * Страницы чужой роли перенаправляют на свою главную, запросы к API получают 403.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user && in_array($user->role, $roles, true)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Недостаточно прав');
        }

        return redirect()->route('home');
    }
}
