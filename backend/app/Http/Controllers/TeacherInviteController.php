<?php

namespace App\Http\Controllers;

use App\Models\TeacherInvite;
use App\Models\User;
use App\Support\PersonName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Регистрация преподавателя по ссылке от администратора: /teacher-invite/{token}.
 * Роль задаётся здесь, а не из формы; без действующей ссылки преподавателем не стать.
 */
class TeacherInviteController extends Controller
{
    public function show(string $token)
    {
        $invite = $this->find($token);

        return view('auth.teacher-invite', [
            'title' => $invite?->isUsable() ? 'Регистрация преподавателя' : 'Ссылка недействительна',
            'invite' => $invite?->isUsable() ? $invite : null,
            'reason' => $invite?->status(),
            'user' => Auth::user(),
            'token' => $token,
        ]);
    }

    public function register(Request $request, string $token)
    {
        if (Auth::check()) {
            return redirect()->route('teacher-invite', $token);
        }
        // Сначала ссылка, потом форма: без действующей ссылки нельзя даже узнать, занята ли почта
        if (! $this->find($token)?->isUsable()) {
            return redirect()->route('teacher-invite', $token);
        }
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'last_name' => 'required|string|max:60',
            'first_name' => 'required|string|max:60',
            'middle_name' => 'nullable|string|max:60',
            'email' => 'required|email:rfc|max:150|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'last_name.required' => 'Укажите фамилию',
            'first_name.required' => 'Укажите имя',
            'email.required' => 'Укажите почту',
            'email.email' => 'Почта указана с ошибкой',
            'email.unique' => 'Эта почта уже зарегистрирована — войдите с ней',
            'password.required' => 'Придумайте пароль',
            'password.confirmed' => 'Пароль и повтор не совпадают',
            'password.min' => 'Пароль — не короче 8 символов',
        ]);

        // Одноразовую ссылку не используют двое одновременно: строка приглашения блокируется до конца регистрации
        $user = DB::transaction(function () use ($token, $data) {
            $invite = TeacherInvite::where('token', $token)->lockForUpdate()->first();
            if (! $invite || ! $invite->isUsable()) {
                throw ValidationException::withMessages(['email' => 'Ссылка больше не действует — попросите у администратора новую']);
            }
            $invite->increment('uses');

            return User::create(PersonName::make($data['last_name'], $data['first_name'], $data['middle_name'] ?? '') + [
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => User::ROLE_TEACHER,
                'teacher_invite_id' => $invite->id,
            ]);
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('teacher.dashboard');
    }

    private function find(string $token): ?TeacherInvite
    {
        return preg_match('/^[A-Za-z0-9]{20,64}$/', $token) ? TeacherInvite::where('token', $token)->first() : null;
    }
}
