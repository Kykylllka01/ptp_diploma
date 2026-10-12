<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Test;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCourseData;
use Tests\TestCase;

/** Результаты каждого студента у преподавателя, журнал для Модеуса и вес теста в баллах. */
class GradebookTest extends TestCase
{
    use MakesCourseData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedAccountsAndCourse();
    }

    private function classmate(string $name, string $email): User
    {
        return User::create(['name' => $name, 'email' => $email, 'password' => 'secret-123', 'role' => User::ROLE_STUDENT,
            'group_id' => $this->student()->group_id]);
    }

    /** Тест из двух вопросов с весом 20 баллов; студент сдаёт с одним верным ответом из двух. */
    private function takenTest(): Test
    {
        $test = $this->makeTest('m1', [$this->singleQuestion('Q-1'), $this->singleQuestion('Q-2')],
            ['settings' => ['weight' => 20], 'attempts_allowed' => 2]);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $a = Attempt::firstOrFail();
        $this->postJson("/api/attempts/{$a->id}/submit", ['answers' => ['Q-1' => [0], 'Q-2' => [3]]])->assertOk();

        return $test;
    }

    public function test_teacher_sees_every_participant_with_best_attempt_and_points(): void
    {
        $this->takenTest();
        $this->classmate('Абрамов Б.', 'a@utmn.local');

        $r = $this->actingAs($this->teacher())->get('/teacher/analytics?test=m1')->assertOk()->viewData('appData')['results'];
        $this->assertEquals(20, $r['weight']);
        $this->assertSame(['total' => 2, 'final' => 1, 'pending' => 0, 'none' => 1], $r['counts']);

        $byName = collect($r['students'])->keyBy('name');
        $this->assertSame('none', $byName['Абрамов Б.']['status']);           // не сдавал — тоже в списке
        $this->assertSame(50, $byName['Иванов А.']['percent']);
        $this->assertEquals(10, $byName['Иванов А.']['points']);               // 50 % от 20 баллов
        $this->assertSame('final', $byName['Иванов А.']['status']);

        // Ответы студента: ключи видны преподавателю, апелляции нет
        $page = $this->get('/teacher/attempt?id='.$byName['Иванов А.']['attemptId'])->assertOk()->viewData('appData');
        $this->assertTrue($page['result']['showReview']);
        $this->assertSame('teacher', $page['mode']);
        $this->assertEquals(10, $page['result']['points']);
        $this->assertArrayHasKey('correct', $page['attempt']['questions'][0]);
    }

    public function test_best_attempt_counts_and_journal_lists_group_alphabetically(): void
    {
        $test = $this->takenTest();
        // Вторая попытка лучше — в журнал идёт она
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $second = Attempt::latest('id')->firstOrFail();
        $this->postJson("/api/attempts/{$second->id}/submit", ['answers' => ['Q-1' => [0], 'Q-2' => [0]]])->assertOk();
        $this->classmate('Абрамов Б.', 'a@utmn.local');

        $j = $this->actingAs($this->teacher())->get('/teacher/gradebook')->assertOk()->viewData('appData')['journal'];
        $this->assertSame(['Абрамов Б.', 'Иванов А.'], array_column($j['students'], 'name'));
        $this->assertSame('m1', $j['tests'][0]['id']);
        $ivanov = $j['students'][1];
        $this->assertEquals(20, $ivanov['cells']['m1']['points']);
        $this->assertSame(2, $ivanov['cells']['m1']['attempts']);
        $this->assertEquals(20, $ivanov['total']);
        $this->assertNull($j['students'][0]['cells']['m1']['points']);

        // Отметка «перенесено в Модеус» для группы
        $gid = $this->student()->group_id;
        $this->postJson('/api/tests/m1/transferred', ['group' => $gid, 'done' => true])->assertOk();
        $this->assertNotNull($test->fresh()->settings['transferred'][(string) $gid] ?? null);
        $j = $this->get('/teacher/gradebook?group='.$gid)->viewData('appData')['journal'];
        $this->assertNotNull($j['tests'][0]['transferred']);
    }

    public function test_weight_is_set_in_builder_and_changed_later(): void
    {
        $this->singleQuestion('Q-1');
        $this->actingAs($this->teacher());
        $r = $this->postJson('/api/tests', ['title' => 'Контрольная', 'mode' => 'rules', 'publish' => true, 'topics' => [],
            'weight' => 15, 'matrix' => [['single', 1, 0, 1, 0, 0.7]]])->assertOk()->json();
        $this->assertEquals(15, $r['test']['weight']);

        $code = $r['test']['id'];
        $this->assertEquals(12.5, $this->patchJson('/api/tests/'.$code, ['weight' => 12.5])->assertOk()->json('weight'));
        $this->assertNull($this->patchJson('/api/tests/'.$code, ['weight' => null])->assertOk()->json('weight'));
        $this->patchJson('/api/tests/'.$code, ['weight' => 500])->assertStatus(422);
        $this->assertTrue(Test::where('code', $code)->firstOrFail()->setting('shuffle', false));   // остальные настройки на месте
    }

    public function test_students_cannot_open_teacher_results(): void
    {
        $this->takenTest();
        $id = Attempt::firstOrFail()->id;
        $this->actingAs($this->student());
        $this->get('/teacher/attempt?id='.$id)->assertRedirect();
        $this->get('/teacher/gradebook')->assertRedirect();
        $this->postJson('/api/tests/m1/transferred', ['group' => 0, 'done' => true])->assertForbidden();
    }
}
