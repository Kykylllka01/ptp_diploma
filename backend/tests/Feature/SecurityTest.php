<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCourseData;
use Tests\TestCase;

/** Проблемы из проверки безопасности 11.10.2026: каждая воспроизводится и должна быть закрыта. */
class SecurityTest extends TestCase
{
    use MakesCourseData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedAccountsAndCourse();
    }

    // ------------------------------------------------- 1. SQL-песочница не зависает

    public function test_recursive_query_is_rejected(): void
    {
        $this->actingAs($this->student())
            ->postJson('/api/sql/run', ['sql' => 'WITH RECURSIVE r(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM r) SELECT count(*) FROM r'])
            ->assertOk()->assertJson(['ok' => false]);
    }

    public function test_endless_join_is_stopped_by_timeout(): void
    {
        config(['agents.sql_sandbox_timeout' => 1]);
        $start = microtime(true);

        $r = $this->actingAs($this->student())
            ->postJson('/api/sql/run', ['sql' => 'SELECT count(*) FROM students a, students b, students c, students d, students e'])
            ->assertOk()->assertJson(['ok' => false])->json();

        $this->assertStringContainsString('остановлен', $r['error']);
        $this->assertLessThan(10, microtime(true) - $start);
    }

    public function test_endless_sql_answer_does_not_hang_submission(): void
    {
        config(['agents.sql_sandbox_timeout' => 1]);
        $this->makeTest('m1', [$this->question('Q-1', ['type' => 'sql', 'points' => 2, 'text' => 'Все студенты', 'reference' => 'SELECT * FROM students'])]);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();

        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => [
            'Q-1' => 'SELECT count(*) FROM students a, students b, students c, students d, students e',
        ]])->assertOk();

        $this->assertEquals(0, $attempt->fresh()->score);
    }

    public function test_sql_run_is_rate_limited(): void
    {
        $this->actingAs($this->student());
        foreach (range(1, 30) as $_) {
            $this->postJson('/api/sql/run', ['sql' => 'DELETE FROM students'])->assertOk();
        }
        $this->postJson('/api/sql/run', ['sql' => 'DELETE FROM students'])->assertStatus(429);
    }

    // ------------------------------------------------- 2. Проверка в тренировке не раскрывает ответы идущего теста

    public function test_training_check_is_closed_for_question_of_running_test(): void
    {
        $shared = $this->singleQuestion('Q-1', 2);
        $exam = $this->makeTest('m1', [$shared]);
        $this->makeTest('tr1', [$shared, $this->singleQuestion('Q-2')], ['status' => 'training', 'graded' => false, 'group_name' => null]);
        $this->actingAs($this->student());

        // Вопрос из идущего теста — проверка закрыта, пока тест не сдан
        $this->postJson('/api/questions/Q-1/check', ['answer' => [2], 'test' => 'tr1'])->assertForbidden();
        // Остальные вопросы тренировки проверяются
        $this->postJson('/api/questions/Q-2/check', ['answer' => [0], 'test' => 'tr1'])->assertOk()->assertJson(['correct' => true]);

        // После сдачи единственной попытки — открыта
        $this->get('/student/test?test=m1')->assertOk();
        $this->postJson('/api/attempts/'.Attempt::firstOrFail()->id.'/submit', ['answers' => ['Q-1' => [0]]])->assertOk();
        $this->postJson('/api/questions/Q-1/check', ['answer' => [2], 'test' => 'tr1'])->assertOk()->assertJson(['correct' => true]);

        $this->assertSame('running', $exam->fresh()->status);
    }

    public function test_training_check_requires_the_training_the_question_belongs_to(): void
    {
        $this->makeTest('tr1', [$this->singleQuestion('Q-1')], ['status' => 'training', 'graded' => false]);
        $this->singleQuestion('Q-2');
        $this->makeTest('tr2', [$this->singleQuestion('Q-3')], ['status' => 'training', 'graded' => false, 'group_name' => 'Чужая группа']);
        $this->makeTest('m1', [$this->singleQuestion('Q-4')]);
        $this->actingAs($this->student());

        $this->postJson('/api/questions/Q-1/check', ['answer' => [0]])->assertForbidden();                    // без кода тренировки
        $this->postJson('/api/questions/Q-2/check', ['answer' => [0], 'test' => 'tr1'])->assertForbidden();   // вопроса нет в тренировке
        $this->postJson('/api/questions/Q-3/check', ['answer' => [0], 'test' => 'tr2'])->assertForbidden();   // тренировка чужой группы
        $this->postJson('/api/questions/Q-4/check', ['answer' => [0], 'test' => 'm1'])->assertForbidden();    // контрольный тест
        $this->postJson('/api/questions/Q-1/check', ['answer' => [0], 'test' => 'tr1'])->assertOk();
    }

    // ------------------------------------------------- 3. Тест нельзя открыть в обход группы и статуса

    public function test_student_cannot_open_draft_or_other_group_test(): void
    {
        $this->makeTest('d1', [$this->singleQuestion('Q-1')], ['status' => 'draft']);
        $this->makeTest('g2', [$this->singleQuestion('Q-2')], ['group_name' => 'Чужая группа']);
        $this->actingAs($this->student());

        $this->get('/student/test?test=d1')->assertNotFound();
        $this->get('/student/test?test=d1&mode=training')->assertNotFound();
        $this->get('/student/test?test=g2')->assertNotFound();
        $this->get('/student/test?test=g2&mode=training')->assertNotFound();
        $this->assertSame(0, Attempt::count());
    }

    public function test_running_test_cannot_be_opened_as_training(): void
    {
        $this->makeTest('m1', [$this->singleQuestion('Q-1')]);
        $this->makeTest('tr1', [$this->singleQuestion('Q-2')], ['status' => 'training', 'graded' => false]);
        $this->actingAs($this->student());

        $this->get('/student/test?test=m1&mode=training')->assertForbidden();
        $this->get('/student/test?test=tr1&mode=training')->assertOk();
        $this->get('/student/test?test=m1')->assertOk();
    }

    public function test_default_test_is_from_students_group(): void
    {
        $this->makeTest('g2', [$this->singleQuestion('Q-1')], ['group_name' => 'Чужая группа']);
        $this->actingAs($this->student())->get('/student/test')->assertNotFound();

        $this->makeTest('m1', [$this->singleQuestion('Q-2')]);
        $this->get('/student/test')->assertOk();
        $this->assertSame('m1', Attempt::firstOrFail()->test->code);
    }

    // ------------------------------------------------- 4. Разбор с ответами — только когда пересдавать нельзя

    public function test_review_is_hidden_while_attempts_remain(): void
    {
        $test = $this->makeTest('m1', [$this->singleQuestion('Q-1', 1)], ['attempts_allowed' => 2]);
        $this->actingAs($this->student());

        $first = $this->submit($test, ['Q-1' => [0]]);
        $data = $this->get('/student/result?attempt='.$first->id)->assertOk()->viewData('appData');
        $this->assertFalse($data['result']['showReview']);
        $this->assertNotEmpty($data['result']['reviewNote']);
        $this->assertArrayNotHasKey('correct', $data['attempt']['questions'][0]);
        $this->get('/student/test?mode=training&attempt='.$first->id)->assertForbidden();
        $this->postJson('/api/questions/Q-1/check', ['answer' => [1], 'attempt' => $first->id])->assertForbidden();

        $second = $this->submit($test, ['Q-1' => [0]]);
        $data = $this->get('/student/result?attempt='.$second->id)->viewData('appData');
        $this->assertTrue($data['result']['showReview']);
        $this->assertSame([1], $data['attempt']['questions'][0]['correct']);
        $this->get('/student/test?mode=training&attempt='.$second->id)->assertOk();
        $this->postJson('/api/questions/Q-1/check', ['answer' => [1], 'attempt' => $second->id])->assertOk()->assertJson(['correct' => true]);
    }

    // ------------------------------------------------- 6–7. Длина ответа и срок сдачи

    public function test_answer_length_is_limited(): void
    {
        $test = $this->makeTest('m1', [$this->question('Q-1', ['type' => 'open', 'text' => 'Опишите', 'reference' => 'Эталон'])]);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();

        $this->putJson("/api/attempts/{$attempt->id}", ['answers' => ['Q-1' => str_repeat('а', 10001)]])->assertStatus(422);
        $this->putJson("/api/attempts/{$attempt->id}", ['answers' => ['Q-1' => str_repeat('а', 10000)]])->assertOk();
        $this->assertSame('running', $test->status);
    }

    public function test_answers_are_not_saved_after_deadline(): void
    {
        $test = $this->makeTest('m1', [$this->singleQuestion('Q-1')], ['minutes' => 0]);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();

        $test->update(['deadline_at' => now()->subMinutes(5)]);
        $this->putJson("/api/attempts/{$attempt->id}", ['answers' => ['Q-1' => [0]]])->assertStatus(409);
    }

    private function submit(Test $test, array $answers): Attempt
    {
        $this->get('/student/test?test='.$test->code)->assertOk();
        $attempt = Attempt::where('status', 'in_progress')->latest('id')->firstOrFail();
        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => $answers])->assertOk();

        return $attempt->fresh();
    }
}
