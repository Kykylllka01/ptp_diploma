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
        // Ноль именно за остановленный запрос, а не из-за того, что учебная база не запустилась
        $this->assertStringContainsString('остановлен', json_encode($attempt->answers()->first()->criteria, JSON_UNESCAPED_UNICODE));
    }

    public function test_memory_hungry_query_is_stopped(): void
    {
        $this->actingAs($this->student());
        $r = $this->postJson('/api/sql/run', ['sql' => "SELECT length(printf('%999999999s', 'x'))"])->assertOk()->json();
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('памяти', $r['error']);

        // Цепочка CTE с удвоением строки — тоже упирается в предел кучи SQLite, а не съедает память компьютера
        $ctes = ["a0 AS (SELECT 'xxxxxxxxxxxxxxxx' s)"];
        foreach (range(1, 30) as $i) {
            $ctes[] = "a{$i} AS (SELECT s || s s FROM a".($i - 1).')';
        }
        $r = $this->postJson('/api/sql/run', ['sql' => 'WITH '.implode(', ', $ctes).' SELECT length(s) FROM a30'])->assertOk()->json();
        $this->assertFalse($r['ok']);
    }

    public function test_sandbox_words_inside_string_literals_are_allowed(): void
    {
        $this->actingAs($this->student())
            ->postJson('/api/sql/run', ['sql' => "SELECT count(*) FROM students WHERE name = 'recursive'"])
            ->assertOk()->assertJson(['ok' => true, 'rows' => [[0]]]);
    }

    public function test_sandbox_failure_does_not_give_final_zero(): void
    {
        $this->makeTest('m1', [$this->question('Q-1', ['type' => 'sql', 'points' => 2, 'text' => 'Все студенты', 'reference' => 'SELECT * FROM students'])]);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();

        // Учебная база не запускается (нет php.exe и т. п.) — сдача откатывается с 503, попытку можно сдать снова
        $this->app->instance(\App\Services\SqlSandbox::class, new class extends \App\Services\SqlSandbox
        {
            public function run(string $sql, bool $interactive = false): array
            {
                return ['ok' => false, 'kind' => 'infra', 'error' => 'Учебная база сейчас недоступна'];
            }
        });
        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => ['Q-1' => 'SELECT * FROM students']])->assertStatus(503);
        $this->assertSame('in_progress', $attempt->fresh()->status);
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

    // ------------------------------------------------- Повторная проверка (ревью ecc security-reviewer)

    public function test_shared_question_key_stays_hidden_while_other_test_runs(): void
    {
        $shared = $this->singleQuestion('Q-1', 1);
        $old = $this->makeTest('old', [$shared]);
        $first = $this->submit($old, ['Q-1' => [0]]);
        $old->update(['status' => 'closed']);
        $this->makeTest('exam', [$shared]);   // тот же вопрос в новом идущем тесте

        $data = $this->get('/student/result?attempt='.$first->id)->assertOk()->viewData('appData');
        $this->assertTrue($data['result']['showReview']);
        $this->assertArrayNotHasKey('correct', $data['attempt']['questions'][0]);
        $this->assertTrue($data['attempt']['questions'][0]['keyHidden']);
        $this->postJson('/api/questions/Q-1/check', ['answer' => [1], 'attempt' => $first->id])->assertForbidden();
    }

    public function test_training_check_closed_for_scheduled_and_draft_tests(): void
    {
        $scheduled = $this->singleQuestion('Q-1');
        $drafted = $this->singleQuestion('Q-2');
        $this->makeTest('tr1', [$scheduled, $drafted], ['status' => 'training', 'graded' => false]);
        $this->makeTest('later', [$scheduled], ['opens_at' => now()->addDay()]);
        $this->makeTest('d1', [$drafted], ['status' => 'draft', 'group_name' => null]);
        $this->actingAs($this->student());

        $this->postJson('/api/questions/Q-1/check', ['answer' => [0], 'test' => 'tr1'])->assertForbidden();
        $this->postJson('/api/questions/Q-2/check', ['answer' => [0], 'test' => 'tr1'])->assertForbidden();
    }

    public function test_closing_test_blocks_saving_and_submits_the_attempt_in_progress(): void
    {
        $test = $this->makeTest('m1', [$this->singleQuestion('Q-1', 1)], ['attempts_allowed' => 2]);
        $first = $this->submit($test, ['Q-1' => [0]]);
        $this->get('/student/test?test=m1')->assertOk();
        $second = Attempt::where('status', 'in_progress')->firstOrFail();

        // Пока вторая попытка идёт, разбор первой закрыт
        $this->assertFalse($this->get('/student/result?attempt='.$first->id)->viewData('appData')['result']['showReview']);

        // Преподаватель закрыл приём: дописать ответ нельзя, попытка сдаётся с тем, что было сохранено
        $test->update(['status' => 'closed']);
        $this->putJson("/api/attempts/{$second->id}", ['answers' => ['Q-1' => [1]]])->assertStatus(409);
        $data = $this->get('/student/result?attempt='.$first->id)->viewData('appData');
        $this->assertTrue($second->fresh()->isSubmitted());
        $this->assertEquals(0, $second->fresh()->score);
        // Менять ответы уже нельзя — разбор открыт
        $this->assertTrue($data['result']['showReview']);
    }

    public function test_sql_hints_are_hidden_while_review_is_closed(): void
    {
        $test = $this->makeTest('m1', [$this->question('Q-1', ['type' => 'sql', 'points' => 2, 'text' => 'Все студенты', 'reference' => 'SELECT * FROM students'])], ['attempts_allowed' => 2]);
        $first = $this->submit($test, ['Q-1' => 'SELECT * FROM students WHERE id < 5']);

        $data = $this->get('/student/result?attempt='.$first->id)->viewData('appData');
        $this->assertSame([], $data['result']['results']->{'Q-1'}['criteria']);
    }

    public function test_training_check_is_rate_limited_and_answer_length_limited(): void
    {
        $this->makeTest('tr1', [$this->singleQuestion('Q-1')], ['status' => 'training', 'graded' => false]);
        $this->actingAs($this->student());

        $this->postJson('/api/questions/Q-1/check', ['answer' => str_repeat('а', 10001), 'test' => 'tr1'])->assertStatus(422);
        foreach (range(2, 60) as $_) {
            $this->postJson('/api/questions/Q-1/check', ['answer' => [0], 'test' => 'tr1']);
        }
        $this->postJson('/api/questions/Q-1/check', ['answer' => [0], 'test' => 'tr1'])->assertStatus(429);
    }

    public function test_multiple_and_match_answers_pass_validation(): void
    {
        $this->makeTest('m1', [
            $this->question('Q-1', ['type' => 'multiple', 'text' => 'Выберите', 'options' => [['text' => 'a', 'correct' => true], ['text' => 'b'], ['text' => 'c', 'correct' => true]]]),
            $this->question('Q-2', ['type' => 'match', 'text' => 'Сопоставьте', 'options' => [['text' => '1НФ — атомарность'], ['text' => '2НФ — полная зависимость'], ['text' => 'Лишнее: транзитивность']]]),
        ]);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();

        $this->putJson("/api/attempts/{$attempt->id}", ['answers' => ['Q-1' => [0, 2], 'Q-2' => [1, -1]]])->assertOk();
        $this->putJson("/api/attempts/{$attempt->id}", ['answers' => ['Q-1' => true]])->assertStatus(422);
    }

    private function submit(Test $test, array $answers): Attempt
    {
        $this->actingAs($this->student());
        $this->get('/student/test?test='.$test->code)->assertOk();
        $attempt = Attempt::where('status', 'in_progress')->latest('id')->firstOrFail();
        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => $answers])->assertOk();

        return $attempt->fresh();
    }

    // ------------------------------------------------- Данные страницы встраиваются без раздувания и без выхода из <script>

    public function test_page_data_keeps_cyrillic_and_cannot_close_script_tag(): void
    {
        $this->singleQuestion('XSS', 0, ['text' => 'Нормализация </script><script>alert(1)</script>']);

        $html = $this->actingAs($this->teacher())->get('/teacher/bank')->assertOk()->getContent();

        $this->assertStringContainsString('Нормализация', $html);
        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
        $this->assertStringContainsString('</script>', $html);
    }
}
