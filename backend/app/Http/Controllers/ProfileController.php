<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\LlmCall;
use App\Models\Material;
use App\Models\Question;
use App\Models\Review;
use App\Models\Test;
use App\Services\FrontendData;
use App\Support\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** Личный кабинет: данные пользователя, смена пароля, сводка по своей работе. */
class ProfileController extends Controller
{
    public function __construct(private FrontendData $data)
    {
    }

    public function show()
    {
        $user = CurrentUser::user();
        $course = CurrentUser::course();

        if ($user->isTeacher()) {
            $stats = [
                ['Материалов', Material::where('course_id', $course->id)->count()],
                ['Вопросов в банке', Question::where('course_id', $course->id)->count()],
                ['Одобрено вопросов', Question::where('course_id', $course->id)->where('status', 'approved')->count()],
                ['Тестов', Test::where('course_id', $course->id)->count()],
                ['Сданных попыток', Attempt::whereHas('test', fn ($t) => $t->where('course_id', $course->id))->where('status', '!=', 'in_progress')->count()],
                ['Ответов ждут проверки', Review::where('status', 'pending')->count()],
            ];
            $llm = [
                'calls' => LlmCall::count(),
                'tokens' => (int) LlmCall::sum('tokens_in') + (int) LlmCall::sum('tokens_out'),
                'byAgent' => LlmCall::selectRaw('agent, count(*) as n, sum(tokens_in + tokens_out) as t')->groupBy('agent')->get(),
            ];
            $appData = $this->data->teacher();
        } else {
            $stats = [];
            $llm = null;
            $appData = $this->data->student();
        }

        $attempts = $user->isTeacher() ? collect() : Attempt::with('test')->where('user_id', $user->id)
            ->where('status', '!=', 'in_progress')->latest('submitted_at')->get();

        return view('profile', [
            'title' => 'Личный кабинет',
            'active' => 'profile',
            'user' => $user,
            'course' => $course,
            'stats' => $stats,
            'llm' => $llm,
            'attempts' => $attempts,
            'appData' => $appData,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'full_name' => 'nullable|string|max:150',
        ], ['name.required' => 'Укажите, как вас подписывать']);

        $user = CurrentUser::user();
        preg_match_all('/\p{Lu}/u', $data['name'], $caps);
        $user->update($data + ['initials' => mb_substr(implode('', $caps[0]), 0, 2) ?: mb_strtoupper(mb_substr($data['name'], 0, 1))]);

        return back()->with('status', 'Данные сохранены');
    }

    public function password(Request $request)
    {
        $request->validate([
            'current_password' => 'required|current_password',
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'current_password.required' => 'Введите текущий пароль',
            'current_password.current_password' => 'Текущий пароль указан неверно',
            'password.required' => 'Введите новый пароль',
            'password.confirmed' => 'Новый пароль и повтор не совпадают',
            'password.min' => 'Новый пароль — не короче 8 символов',
        ]);

        // Поле password в модели User хешируется приведением типа 'hashed'
        CurrentUser::user()->update(['password' => $request->password]);

        return back()->with('status', 'Пароль изменён');
    }
}
