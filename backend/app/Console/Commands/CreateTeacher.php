<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Аккаунт преподавателя. На сайте регистрируются только студенты (по приглашению группы),
 * поэтому преподавателей пилота заводит администратор: php artisan teacher:create почта "Фамилия Имя Отчество"
 * Пароль выдаётся один раз в консоли; преподаватель меняет его в личном кабинете.
 */
class CreateTeacher extends Command
{
    protected $signature = 'teacher:create {email} {name} {--password= : свой пароль вместо случайного}';

    protected $description = 'Создать аккаунт преподавателя и выдать временный пароль';

    public function handle(): int
    {
        $data = ['email' => mb_strtolower(trim($this->argument('email'))), 'name' => trim($this->argument('name'))];
        $v = Validator::make($data, ['email' => 'required|email|max:255|unique:users,email', 'name' => 'required|string|max:255']);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }
        $password = $this->option('password') ?: self::temporaryPassword();
        if (mb_strlen($password) < 8) {
            $this->error('Пароль — не короче 8 символов');

            return self::FAILURE;
        }
        // «Бакановская Людмила Николаевна» → в шапке «Бакановская Л. Н.», обращение «Людмила Николаевна», аватар «БЛ»
        $parts = preg_split('/\s+/u', $data['name']);
        [$last, $first, $middle] = [$parts[0], $parts[1] ?? '', $parts[2] ?? ''];
        $user = User::create([
            'email' => $data['email'],
            'name' => trim($last.($first ? ' '.mb_substr($first, 0, 1).'.' : '').($middle ? ' '.mb_substr($middle, 0, 1).'.' : '')),
            'full_name' => trim($first.' '.$middle) ?: $last,
            'initials' => mb_strtoupper(mb_substr($last, 0, 1).($first ? mb_substr($first, 0, 1) : mb_substr($last, 1, 1))),
            'role' => User::ROLE_TEACHER,
            'password' => $password,
        ]);

        $this->info('Преподаватель создан: '.$user->name.' <'.$user->email.'>');
        if (! $this->option('password')) {
            $this->line('Временный пароль: '.$password);
            $this->line('Передайте его преподавателю лично; сменить пароль можно в личном кабинете.');
        }

        return self::SUCCESS;
    }

    /** Без похожих символов (0/o, 1/l/i): пароль диктуют или переписывают с экрана. */
    public static function temporaryPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 12; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }
}
