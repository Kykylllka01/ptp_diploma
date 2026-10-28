<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Attempt;
use App\Models\Review;
use App\Models\User;
use App\Services\AttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCourseData;
use Tests\TestCase;

/** Целостность попыток, состава тестов и проверки: гонки, брошенные попытки, неверные данные. */
class IntegrityTest extends TestCase
{
    use MakesCourseData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedAccountsAndCourse();
    }

    private function open(): \App\Models\Question
    {
        return $this->question('Q-9', ['type' => 'open', 'points' => 3, 'text' => 'Что такое 1НФ?', 'reference' => 'Атомарные значения',
            'criteria' => [['Определение', 2], ['Пример', 1]]]);
    }

    public function test_double_submit_grades_once(): void
    {
        $this->makeTest('m1', [$this->singleQuestion('Q-1', 1), $this->open()]);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();
        $answers = ['Q-1' => [1], 'Q-9' => 'Все значения атомарны, например один телефон в ячейке'];

        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => $answers])->assertOk();
        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => $answers])->assertOk();

        $this->assertSame(1, Review::count());
        $this->assertSame(2, Answer::count());
        $this->assertTrue($attempt->fresh()->isSubmitted());
    }

    public function test_autosave_after_submit_does_not_overwrite_graded_answers(): void
    {
        $this->makeTest('m1', [$this->singleQuestion('Q-1', 1)]);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();
        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => ['Q-1' => [1]]])->assertOk();

        app(AttemptService::class)->save($attempt, ['Q-1' => [0]], [], 0);

        $this->assertSame([1], Answer::firstOrFail()->value);
    }

    public function test_abandoned_attempt_is_submitted_when_student_comes_back(): void
    {
        $this->makeTest('m1', [$this->singleQuestion('Q-1', 1)], ['minutes' => 10]);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();
        $this->putJson("/api/attempts/{$attempt->id}", ['answers' => ['Q-1' => [1]]])->assertOk();
        $attempt->update(['started_at' => now()->subMinutes(30)]);   // закрыл вкладку и не вернулся вовремя

        $this->get('/student/tests')->assertOk();

        $attempt->refresh();
        $this->assertTrue($attempt->isSubmitted());
        $this->assertEquals(1, $attempt->score);   // сохранённый ответ засчитан
    }

    public function test_finalize_command_submits_overdue_attempts(): void
    {
        $this->makeTest('m1', [$this->singleQuestion('Q-1', 1)], ['minutes' => 10]);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        Attempt::query()->update(['started_at' => now()->subHour()]);

        $this->artisan('attempts:finalize')->expectsOutputToContain('Сдано попыток: 1')->assertSuccessful();
        $this->assertTrue(Attempt::firstOrFail()->isSubmitted());
    }

    public function test_attempt_stuck_in_grading_is_submitted_again(): void
    {
        $this->makeTest('m1', [$this->singleQuestion('Q-1', 1)], ['minutes' => 45]);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();
        $this->putJson("/api/attempts/{$attempt->id}", ['answers' => ['Q-1' => [1]]])->assertOk();
        // Процесс сдачи сняли посреди оценки: отметка «оценивается» осталась, оценка откатилась
        Attempt::query()->update(['status' => 'grading']);

        $this->artisan('attempts:finalize')->expectsOutputToContain('Сдано попыток: 0')->assertSuccessful();
        $this->assertSame('grading', $attempt->fresh()->status);   // свежая сдача ещё может идти — не трогаем

        $this->travel(11)->minutes();
        $this->artisan('attempts:finalize')->expectsOutputToContain('Сдано попыток: 1')->assertSuccessful();

        $attempt->refresh();
        $this->assertSame('confirmed', $attempt->status);
        $this->assertEquals(1, $attempt->score);
    }

    public function test_timer_ends_at_deadline_when_it_comes_before_attempt_time(): void
    {
        $this->travelTo(today()->setTime(12, 0));   // «сегодня до …» не должно зависеть от того, когда запущены тесты
        $this->makeTest('m1', [$this->singleQuestion('Q-1', 1)], ['minutes' => 45, 'deadline_at' => now()->addMinutes(20)]);
        $this->makeTest('m2', [$this->singleQuestion('Q-2', 1)], ['minutes' => 45, 'deadline_at' => now()->addHours(3)]);
        $this->makeTest('m3', [$this->singleQuestion('Q-3', 1)], ['minutes' => 0]);
        $this->actingAs($this->student());

        $cut = $this->get('/student/test?test=m1')->assertOk()->viewData('appData')['attempt'];
        $this->assertSame(now()->addMinutes(20)->getTimestampMs(), $cut['endsAt']);
        $this->assertTrue($cut['cutByDeadline']);
        $this->assertSame(now()->addMinutes(20)->format('H:i'), $cut['deadlineTime']);   // по часам сервера, не устройства

        $full = $this->get('/student/test?test=m2')->assertOk()->viewData('appData')['attempt'];
        $this->assertSame(now()->addMinutes(45)->getTimestampMs(), $full['endsAt']);
        $this->assertFalse($full['cutByDeadline']);

        $free = $this->get('/student/test?test=m3')->assertOk()->viewData('appData')['attempt'];
        $this->assertNull($free['endsAt']);

        $list = collect($this->get('/student/tests')->assertOk()->viewData('appData')['tests'])->keyBy('id');
        $this->assertSame('Сегодня до '.now()->addMinutes(20)->format('H:i'), $list['m1']['deadline']);
    }

    public function test_answer_of_wrong_shape_does_not_break_submission(): void
    {
        $this->makeTest('m1', [$this->question('Q-1', ['type' => 'sql', 'points' => 2, 'text' => 'Все студенты', 'reference' => 'SELECT * FROM students']),
            $this->singleQuestion('Q-2', 1)]);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();

        // Массив вместо SQL-строки и номера вариантов вне вопроса
        $this->putJson("/api/attempts/{$attempt->id}", ['answers' => ['Q-1' => [1, 2], 'Q-2' => [1, 99, -3]]])->assertOk();
        $this->postJson("/api/attempts/{$attempt->id}/submit")->assertOk();

        $this->assertNull(Answer::whereHas('question', fn ($q) => $q->where('code', 'Q-1'))->value('value'));
        $this->assertSame([1], Answer::whereHas('question', fn ($q) => $q->where('code', 'Q-2'))->first()->value);
    }

    public function test_test_composition_is_frozen_after_attempts_and_only_approved_questions_are_added(): void
    {
        $test = $this->makeTest('m1', [$this->singleQuestion('Q-1')]);
        $this->singleQuestion('Q-2');
        $this->singleQuestion('Q-3', 0, ['status' => 'pending']);
        $this->actingAs($this->teacher());

        $r = $this->postJson('/api/tests/m1/questions', ['ids' => ['Q-2', 'Q-3']])->assertOk()->json();
        $this->assertSame(1, $r['added']);
        $this->assertSame(1, $r['skipped']);

        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $this->actingAs($this->teacher())->postJson('/api/tests/m1/questions', ['ids' => ['Q-3']])->assertStatus(409);
        $this->assertSame(2, $test->questions()->count());
    }

    public function test_empty_test_is_not_published(): void
    {
        $this->actingAs($this->teacher());
        $this->postJson('/api/tests', ['title' => 'Пусто', 'mode' => 'rules', 'publish' => true, 'topics' => [],
            'matrix' => [['single', 1, 0, 3, 0, 0.7]]])->assertStatus(422);
        $this->postJson('/api/tests', ['title' => 'Плохая матрица', 'mode' => 'rules', 'topics' => [],
            'matrix' => [['single', 1, 'много', 3, 0, 0.7]]])->assertStatus(422);

        $draft = $this->makeTest('d1', [], ['status' => 'draft']);
        $this->patchJson('/api/tests/d1', ['status' => 'running'])->assertStatus(422);
        $this->assertSame('draft', $draft->fresh()->status);
    }

    /** Сборщик шлёт матрицу целиком: 6-й столбец — минуты на вопрос (у развёрнутого 4), а не доля до 1. */
    public function test_builder_default_matrix_with_minutes_per_question_is_accepted(): void
    {
        $this->singleQuestion('Q-1', 2);
        $this->actingAs($this->teacher());
        $r = $this->postJson('/api/tests', ['title' => 'Как в сборщике', 'mode' => 'rules', 'publish' => true, 'topics' => [],
            'matrix' => [['single', 1, 0, 1, 0, 0.7], ['multiple', 2, 0, 0, 0, 1], ['tf', 1, 0, 0, 0, 0.5],
                ['match', 2, 0, 0, 0, 1.5], ['sql', 2, 0, 0, 0, 2], ['open', 5, 0, 0, 0, 4]]])->assertOk()->json();
        $this->assertSame(1, $r['picked']);

        $this->postJson('/api/tests', ['title' => 'Минут слишком много', 'mode' => 'rules', 'topics' => [],
            'matrix' => [['open', 5, 0, 1, 0, 500]]])->assertStatus(422);
    }

    public function test_retakes_do_not_count_as_new_students_in_analytics(): void
    {
        $test = $this->makeTest('m1', [$this->singleQuestion('Q-1', 1), $this->singleQuestion('Q-2', 1)], ['attempts_allowed' => 2]);
        $this->actingAs($this->student());
        foreach ([['Q-1' => [0], 'Q-2' => [0]], ['Q-1' => [1], 'Q-2' => [1]]] as $answers) {   // 0 %, потом 100 %
            $this->get('/student/test?test=m1')->assertOk();
            $attempt = Attempt::where('status', 'in_progress')->latest('id')->firstOrFail();
            $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => $answers])->assertOk();
        }

        $this->actingAs($this->teacher());
        $a = $this->get('/teacher/analytics?test=m1')->viewData('appData')['analytics'];
        $this->assertSame(1, $a['submitted']);
        $this->assertSame(100, $a['avg']);
        $row = collect($this->get('/teacher/tests')->viewData('appData')['tests'])->firstWhere('id', 'm1');
        $this->assertSame('100%', $row['avg']);
        $this->assertStringStartsWith('1 / ', $row['passed']);
        $this->assertSame(2, $test->attempts()->count());
    }

    public function test_teacher_scores_are_limited_by_each_criterion_and_appeal_is_single(): void
    {
        $this->makeTest('m1', [$this->open()]);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();
        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => ['Q-9' => 'Атомарные значения, например один телефон в ячейке']])->assertOk();
        $review = Review::firstOrFail();

        $this->actingAs($this->teacher());
        $this->patchJson("/api/reviews/{$review->id}", ['scores' => [3, 0]])->assertStatus(422);   // 3 > 2 у первого критерия
        $this->patchJson("/api/reviews/{$review->id}", ['scores' => [2]])->assertStatus(422);      // критериев два
        $this->patchJson("/api/reviews/{$review->id}", ['scores' => [2, 1]])->assertOk();

        $this->actingAs($this->student());
        $answer = Answer::firstOrFail();
        $this->postJson("/api/answers/{$answer->id}/appeal", ['text' => 'Прошу пересмотреть пример'])->assertOk();
        $this->postJson("/api/answers/{$answer->id}/appeal", ['text' => 'И ещё раз прошу'])->assertStatus(409);
    }

    public function test_answered_question_keeps_its_key_and_only_test_author_confirms_scores(): void
    {
        $q = $this->open();
        $this->makeTest('m1', [$q], ['user_id' => $this->teacher()->id]);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();
        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => ['Q-9' => 'Атомарные значения в каждой ячейке']])->assertOk();
        $review = Review::firstOrFail();

        // На вопрос ответили: эталон и критерии менять нельзя, текст — можно
        $this->actingAs($this->teacher());
        $this->patchJson('/api/questions/Q-9', ['criteria' => [['Определение', 3]]])->assertStatus(409);
        $this->patchJson('/api/questions/Q-9', ['reference' => 'Другой эталон'])->assertStatus(409);
        $this->postJson('/api/questions/Q-9/regenerate')->assertStatus(409);
        $this->patchJson('/api/questions/Q-9', ['text' => 'Что такое первая нормальная форма?', 'criteria' => [['Определение', 2], ['Пример', 1]]])->assertOk();

        // Оценку подтверждает автор теста, а не любой преподаватель курса
        $colleague = User::create(['name' => 'Петрова А. С.', 'email' => 'petrova@utmn.local', 'password' => 'colleague123', 'role' => User::ROLE_TEACHER]);
        $this->actingAs($colleague)->patchJson("/api/reviews/{$review->id}", ['scores' => [2, 1]])->assertForbidden();
        $this->actingAs($this->teacher())->patchJson("/api/reviews/{$review->id}", ['scores' => [2, 1]])->assertOk();
    }
}
