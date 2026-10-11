<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'Неверная почта или пароль']);
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
        return $request->user()->isTeacher()
            ? redirect()->route('teacher.dashboard')
            : redirect()->route('student.tests');
    }
}
