<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** Вход и выход по сессии (стандартный guard web). */
class AuthController extends Controller
{
    public function show()
    {
        return view('auth.login', ['title' => 'Вход']);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ], [
            'email.required' => 'Введите почту',
            'email.email' => 'Почта указана с ошибкой',
            'password.required' => 'Введите пароль',
        ]);

        // Подбор паролей с одной сети: считаются только неудачные входы, поэтому вся группа за одним
        // Wi-Fi входит без помех, а сотни ошибок в минуту с одного IP останавливаются
        $failures = 'login-failures:'.$request->ip();
        if (RateLimiter::tooManyAttempts($failures, 300)) {
            throw ValidationException::withMessages(['email' => 'Слишком много неудачных входов из этой сети — подождите минуту'])->status(429);
        }
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($failures, 60);
            throw ValidationException::withMessages(['email' => 'Неверная почта или пароль']);
        }
        // Доступ закрыт администратором — пароль верный, но войти нельзя
        if (Auth::user()->disabled_at !== null) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'Доступ к аккаунту закрыт администратором']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** Главная: каждой роли — свой первый экран. */
    public function home(Request $request)
    {
        return match (true) {
            $request->user()->isAdmin() => redirect()->route('admin.index'),
            $request->user()->isTeacher() => redirect()->route('teacher.dashboard'),
            default => redirect()->route('student.tests'),
        };
    }
}
