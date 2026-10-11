<?php

namespace App\Support;

use App\Models\Course;
use App\Models\User;

/**
 * Текущий пользователь и курс. Роль проверяет middleware role:teacher / role:student
 * на маршрутах, поэтому здесь достаточно вернуть вошедшего пользователя.
 */
class CurrentUser
{
    public static function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    public static function teacher(): User
    {
        return self::user();
    }

    public static function student(): User
    {
        return self::user();
    }

    /** Пока курс один; когда их станет несколько, курс будет выбираться по преподавателю и группе. */
    /** Курс один; за запрос его спрашивают 4–6 раз — запоминаем (once() сбрасывается между запросами и тестами). */
    public static function course(): Course
    {
        return once(fn () => Course::firstOrFail());
    }
}
