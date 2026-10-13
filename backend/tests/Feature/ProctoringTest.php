<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\ProctorEvent;
use App\Models\Test;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCourseData;
use Tests\TestCase;

/** Жёсткий прокторинг: включается при сборке теста, уходы со страницы пишутся в попытку и видны преподавателю. */
class ProctoringTest extends TestCase
{
    use MakesCourseData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedAccountsAndCourse();
    }

    private function proctoredTest(bool $on = true): Test
    {
        return $this->makeTest('m1', [$this->singleQuestion('Q-1'), $this->singleQuestion('Q-2')], ['settings' => ['proctoring' => $on]]);
    }

    /** Студент открывает тест — начинается попытка. */
    private function startAttempt(): Attempt
    {
        $page = $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk()->viewData('appData');
        $this->assertTrue($page['attempt']['proctoring']);

        return Attempt::firstOrFail();
    }

    public function test_builder_switches_proctoring_on_and_training_ignores_it(): void
    {
        $this->singleQuestion('Q-1');
        $this->actingAs($this->teacher());
        $payload = ['title' => 'Контрольная', 'mode' => 'rules', 'publish' => true, 'topics' => [], 'matrix' => [['single', 1, 0, 1, 0, 1]]];

        $on = $this->postJson('/api/tests', $payload + ['settings' => ['proctoring' => true]])->assertOk()->json('test');
        $this->assertTrue($on['proctoring']);
        $this->assertTrue(Test::where('code', $on['id'])->first()->isProctored());

        $off = $this->postJson('/api/tests', $payload)->assertOk()->json('test');
        $this->assertFalse($off['proctoring']);

        // В тренировке попыток нет — прокторинг не включается
        $training = $this->postJson('/api/tests', $payload + ['training' => true, 'settings' => ['proctoring' => true]])->assertOk()->json('test');
        $this->assertFalse($training['proctoring']);
        $this->assertFalse(Test::where('code', $training['id'])->first()->setting('proctoring'));
    }

    public function test_teacher_toggles_proctoring_on_published_test(): void
    {
        $test = $this->proctoredTest(false);
        $this->actingAs($this->teacher())->patchJson('/api/tests/m1', ['proctoring' => true])->assertOk()->assertJsonPath('proctoring', true);
        $this->assertTrue($test->fresh()->isProctored());
        // Остальные настройки не теряются
        $this->patchJson('/api/tests/m1', ['weight' => 20])->assertOk()->assertJsonPath('proctoring', true);
        $this->patchJson('/api/tests/m1', ['proctoring' => false])->assertOk()->assertJsonPath('proctoring', false)->assertJsonPath('weight', 20);
    }

    public function test_leaving_the_page_is_recorded_with_server_time(): void
    {
        $this->proctoredTest();
        $attempt = $this->startAttempt();

        $this->travel(3)->minutes();
        $this->postJson("/api/attempts/{$attempt->id}/proctor", ['kind' => 'hidden'])->assertOk()->assertJsonPath('count', 1);
        $this->travel(42)->seconds();
        $this->postJson("/api/attempts/{$attempt->id}/proctor/back")->assertOk();
        $this->postJson("/api/attempts/{$attempt->id}/proctor", ['kind' => 'blur'])->assertOk()->assertJsonPath('count', 2);

        $events = $attempt->proctorEvents()->get();
        $this->assertSame(['hidden', 'blur'], $events->pluck('kind')->all());
        $this->assertSame(42, $events[0]->seconds());
        $this->assertNull($events[1]->seconds());

        // Неизвестный вид ухода не принимается
        $this->postJson("/api/attempts/{$attempt->id}/proctor", ['kind' => 'copy'])->assertUnprocessable();
    }

    public function test_reopened_page_closes_leave_only_when_student_is_back_at_questions(): void
    {
        $this->proctoredTest();
        $attempt = $this->startAttempt();
        $this->postJson("/api/attempts/{$attempt->id}/proctor", ['kind' => 'close'])->assertOk();
        $this->travel(5)->minutes();

        // Просто открыть страницу мало: новая вкладка с правилами уход не закрывает
        $page = $this->get('/student/test?test=m1')->assertOk()->viewData('appData');
        $this->assertSame(1, $page['attempt']['proctorCount']);
        $this->assertNull($attempt->proctorEvents()->first()->seconds());
        $this->assertSame(1, Attempt::count());

        $this->postJson("/api/attempts/{$attempt->id}/proctor/back")->assertOk();
        $this->assertSame(300, $attempt->proctorEvents()->first()->seconds());
    }

    public function test_leaves_after_time_is_up_are_not_recorded(): void
    {
        $this->proctoredTest();
        $attempt = $this->startAttempt();
        $this->travel(47)->minutes();
        $this->postJson("/api/attempts/{$attempt->id}/proctor", ['kind' => 'hidden'])->assertStatus(409);
        $this->assertSame(0, ProctorEvent::count());
    }

    public function test_events_are_refused_without_proctoring_for_other_students_and_after_submit(): void
    {
        $this->proctoredTest(false);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();
        $this->postJson("/api/attempts/{$attempt->id}/proctor", ['kind' => 'hidden'])->assertUnprocessable();

        $attempt->test->update(['settings' => ['proctoring' => true]]);
        $other = User::create(['name' => 'Другой студент', 'email' => 'other@utmn.local', 'role' => User::ROLE_STUDENT, 'password' => 'secret123',
            'group_id' => $this->student()->group_id]);
        $this->actingAs($other)->postJson("/api/attempts/{$attempt->id}/proctor", ['kind' => 'hidden'])->assertForbidden();
        $this->actingAs($other)->postJson("/api/attempts/{$attempt->id}/proctor/back")->assertForbidden();
        // Преподаватель не пишет уходы за студента
        $this->actingAs($this->teacher())->postJson("/api/attempts/{$attempt->id}/proctor", ['kind' => 'hidden'])->assertForbidden();

        $this->actingAs($this->student())->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => ['Q-1' => [0]]])->assertOk();
        $this->postJson("/api/attempts/{$attempt->id}/proctor", ['kind' => 'hidden'])->assertStatus(409);
        $this->assertSame(0, ProctorEvent::count());
    }

    public function test_teacher_sees_leaves_in_answers_analytics_and_gradebook(): void
    {
        $this->proctoredTest();
        $attempt = $this->startAttempt();
        $this->postJson("/api/attempts/{$attempt->id}/proctor", ['kind' => 'hidden'])->assertOk();
        $this->travel(10)->seconds();
        $this->postJson("/api/attempts/{$attempt->id}/proctor/back")->assertOk();
        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => ['Q-1' => [0], 'Q-2' => [0]]])->assertOk();

        // Студент свой журнал прокторинга в результате не получает
        $own = $this->get('/student/result?attempt='.$attempt->id)->assertOk()->viewData('appData');
        $this->assertNull($own['result']['proctor']);

        $this->actingAs($this->teacher());
        $page = $this->get('/teacher/attempt?id='.$attempt->id)->assertOk()->viewData('appData');
        $this->assertSame(1, $page['result']['proctor']['count']);
        $this->assertSame(10, $page['result']['proctor']['seconds']);
        $this->assertSame('hidden', $page['result']['proctor']['events'][0]['kind']);

        $results = $this->get('/teacher/analytics?test=m1')->assertOk()->viewData('appData')['results'];
        $this->assertTrue($results['proctoring']);
        $this->assertSame(1, $results['counts']['proctor']);
        $row = collect($results['students'])->firstWhere('attemptId', $attempt->id);
        $this->assertSame(1, $row['violations']);

        $journal = $this->get('/teacher/gradebook')->assertOk()->viewData('appData')['journal'];
        $me = collect($journal['students'])->firstWhere('id', $this->student()->id);
        $this->assertSame(1, $me['cells']['m1']['violations']);
    }
}
