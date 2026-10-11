<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Курс и карточки агентов — без вопросов, тестов и результатов.
 * Банк вопросов наполняют агенты по загруженным материалам.
 */
class CourseSeeder extends Seeder
{
    public const TOPICS = ['Реляционная модель', 'ER-моделирование', 'SQL-запросы', 'Нормализация', 'Транзакции'];

    private const AGENTS = [
        ['analyst', 'Аналитик', 'Разбор материалов'],
        ['writer', 'Составитель', 'Генерация вопросов'],
        ['critic', 'Критик', 'Контроль качества'],
        ['grader', 'Оценщик', 'Проверка ответов'],
        ['catalog', 'Каталогизатор', 'Теги, сложность, дубли с банком'],
        ['examiner', 'Экзаменатор', 'Сборка и адаптивный режим тестов'],
    ];

    public function run(): void
    {
        $teacher = User::where('email', AccountsSeeder::TEACHER['email'])->first();

        Course::firstOrCreate(['title' => 'Базы данных'], [
            'semester' => 'осенний семестр',
            'teacher_id' => $teacher?->id,
            'topics' => self::TOPICS,
        ]);

        foreach (self::AGENTS as [$code, $name, $role]) {
            Agent::updateOrCreate(['code' => $code], ['name' => $name, 'role' => $role, 'state' => 'wait', 'summary' => 'Ещё не запускался']);
        }
    }
}
