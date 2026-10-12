<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * Регистрация студента по ссылке-приглашению группы: /join/{код}. Без кода зарегистрироваться нельзя —
 * так в систему попадают только студенты, которым преподаватель дал ссылку, и сразу в свою группу.
 * Перейти в другую группу сам студент не может (иначе по чужой ссылке он увидел бы чужие тесты) — это делает преподаватель.
 */
class JoinController extends Controller
{
    /** Ввод кода вручную (если ссылку продиктовали). */
    public function enterCode(Request $request)
    {
        $code = $this->normalize((string) $request->query('code', ''));
        if ($code !== '') {
            return redirect()->route('join', $code);
        }

        return view('auth.register', ['title' => 'Регистрация', 'wrong' => $request->filled('code')]);
    }

    public function show(string $code)
    {
        $group = $this->openGroup($code);

        return view('auth.join', [
            'title' => $group ? 'Регистрация в группе '.$group->name : 'Ссылка недействительна',
            'group' => $group,
            'user' => Auth::user(),
            'code' => $code,
        ]);
    }

    public function register(Request $request, string $code)
    {
        $group = $this->openGroup($code);
        if (! $group) {
            return redirect()->route('join', $code);
        }
        // Почта хранится в нижнем регистре — и проверяется на занятость в нём же
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

        $last = $this->word($data['last_name']);
        $first = $this->word($data['first_name']);
        $middle = $this->word($data['middle_name'] ?? '');
        // Роль и группа задаются здесь, а не из формы: зарегистрироваться преподавателем нельзя
        $user = User::create([
            'name' => $last.' '.mb_substr($first, 0, 1).'.'.($middle !== '' ? ' '.mb_substr($middle, 0, 1).'.' : ''),
            'full_name' => trim($first.' '.$middle),
            'initials' => mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1)),
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => User::ROLE_STUDENT,
            'group_id' => $group->id,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('student.tests')->with('status', 'Вы зарегистрированы в группе «'.$group->name.'»');
    }

    /** Студент без группы (создан из консоли) вступает в группу по ссылке. Сменить группу так нельзя. */
    public function accept(Request $request, string $code)
    {
        $group = $this->openGroup($code);
        $user = $request->user();
        abort_unless($group && $user->role === User::ROLE_STUDENT && $user->group_id === null, 403);
        $user->update(['group_id' => $group->id]);

        return redirect()->route('student.tests')->with('status', 'Вы в группе «'.$group->name.'»');
    }

    private function openGroup(string $code): ?Group
    {
        $code = $this->normalize($code);

        return $code === '' ? null : Group::where('invite_code', $code)->where('invite_open', true)->first();
    }

    /** Код или вставленная целиком ссылка: берём последний фрагмент, только буквы и цифры. */
    private function normalize(string $code): string
    {
        $code = mb_strtolower(trim($code));
        $slash = strrpos($code, '/');
        $code = $slash === false ? $code : substr($code, $slash + 1);

        return preg_match('/^[a-z0-9]{6,32}$/', $code) ? $code : '';
    }

    /** «иВАНОВ» → «Иванов», «анна-мария» → «Анна-Мария». */
    private function word(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', trim($s));

        return mb_convert_case(mb_strtolower($s), MB_CASE_TITLE);
    }
}
