<?php

namespace App\Console\Commands;

use App\Models\User;

/**
 * Аккаунт администратора: php artisan admin:create почта "Фамилия Имя Отчество".
 * Только из консоли — на сайте администратором стать нельзя. Администратор открывает /admin:
 * выдаёт преподавателям ссылки-приглашения, выдаёт им новые пароли и закрывает доступ.
 */
class CreateAdmin extends CreateTeacher
{
    protected $signature = 'admin:create {email} {name} {--password= : свой пароль вместо случайного}';

    protected $description = 'Создать аккаунт администратора (приглашения преподавателей)';

    protected string $role = User::ROLE_ADMIN;

    protected string $label = 'Администратор';
}
