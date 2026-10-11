<?php

namespace Tests\Concerns;

use App\Models\Course;
use App\Models\Question;
use App\Models\Test;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\CourseSeeder;

/**
 * Записи для автотестов создаются прямо в тесте, во временной базе SQLite в памяти.
 * Из рабочих сидеров берутся только учётки и курс.
 */
trait MakesCourseData
{
    protected function seedAccountsAndCourse(): void
    {
        $this->seed([AccountsSeeder::class, CourseSeeder::class]);
    }

    protected function teacher(): User
    {
        return User::where('email', AccountsSeeder::TEACHER['email'])->firstOrFail();
    }

    protected function student(): User
    {
        return User::where('email', AccountsSeeder::STUDENT['email'])->firstOrFail();
    }

    /** Вопрос с одним верным вариантом из четырёх. */
    protected function singleQuestion(string $code, int $correct = 0, array $attrs = []): Question
    {
        return $this->question($code, $attrs + [
            'type' => 'single',
            'text' => 'Вопрос '.$code,
            'options' => array_map(fn ($i) => ['text' => 'Вариант '.($i + 1), 'correct' => $i === $correct], range(0, 3)),
        ]);
    }

    protected function question(string $code, array $attrs): Question
    {
        return Question::create($attrs + [
            'code' => $code, 'course_id' => Course::firstOrFail()->id, 'difficulty' => 'medium',
            'topic' => CourseSeeder::TOPICS[3], 'source' => 'Лекция', 'points' => 1, 'status' => 'approved',
        ]);
    }

    /** Тест с вопросами в заданном порядке. По умолчанию — идущий тест группы студента. */
    protected function makeTest(string $code, array $questions, array $attrs = []): Test
    {
        $test = Test::create($attrs + [
            'code' => $code, 'course_id' => Course::firstOrFail()->id, 'title' => 'Тест '.$code,
            'status' => 'running', 'group_name' => $this->student()->group_name, 'minutes' => 45, 'attempts_allowed' => 1,
        ]);
        foreach (array_values($questions) as $i => $q) {
            $test->questions()->attach($q->id, ['position' => $i]);
        }

        return $test;
    }
}
