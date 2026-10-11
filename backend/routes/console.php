<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

/*
| Создание учётной записи из консоли:
|   php artisan user:create petrov@utmn.local "Петров П." --role=student --group="Группа 1"
| Пароль спрашивается отдельно и не попадает в историю команд.
*/
Artisan::command('user:create {email} {name} {--role=student : teacher или student} {--group= : учебная группа студента}', function () {
    $data = [
        'email' => $this->argument('email'),
        'name' => $this->argument('name'),
        'role' => $this->option('role'),
    ];
    $v = Validator::make($data, [
        'email' => 'required|email|unique:users,email',
        'name' => 'required|string|max:255',
        'role' => 'required|in:teacher,student',
    ]);
    if ($v->fails()) {
        foreach ($v->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    $password = $this->secret('Пароль (не меньше 8 символов)');
    if (mb_strlen((string) $password) < 8) {
        $this->error('Пароль слишком короткий');

        return 1;
    }

    preg_match_all('/\p{Lu}/u', $data['name'], $caps);
    User::create($data + [
        'initials' => implode('', array_slice($caps[0], 0, 2)),
        'group_name' => $data['role'] === 'student' ? $this->option('group') : null,
        'password' => $password,
    ]);

    $this->info('Учётная запись создана: '.$data['email'].' ('.($data['role'] === 'teacher' ? 'преподаватель' : 'студент').')');

    return 0;
})->purpose('Создать учётную запись преподавателя или студента');
