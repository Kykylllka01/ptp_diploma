<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Начальные данные: две учётки для входа, курс с темами и карточки агентов.
 * Вопросы, тесты и результаты появляются только из работы системы.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AccountsSeeder::class);
        $this->call(CourseSeeder::class);
    }
}
