<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Учётные записи для входа. Можно запускать отдельно, не трогая остальные данные:
 *   php artisan db:seed --class=AccountsSeeder
 * Если запись уже есть, обновятся имя, роль и пароль.
 */
class AccountsSeeder extends Seeder
{
    public const TEACHER = ['email' => 'teacher@utmn.local', 'password' => 'teacher123'];

    public const STUDENT = ['email' => 'student@utmn.local', 'password' => 'student123'];

    public function run(): void
    {
        User::updateOrCreate(['email' => self::TEACHER['email']], [
            'name' => 'Бакановская Л. Н.',
            'full_name' => 'Людмила Николаевна',
            'initials' => 'БЛ',
            'role' => User::ROLE_TEACHER,
            'password' => self::TEACHER['password'],
        ]);

        User::updateOrCreate(['email' => self::STUDENT['email']], [
            'name' => 'Иванов А.',
            'initials' => 'АИ',
            'role' => User::ROLE_STUDENT,
            'password' => self::STUDENT['password'],
        ]);
    }
}
