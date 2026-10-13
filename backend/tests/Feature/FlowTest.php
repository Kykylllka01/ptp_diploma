<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Question;
use App\Models\Review;
use App\Models\Test;
use Database\Seeders\AccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesCourseData;
use Tests\TestCase;

/** Сквозные сценарии: вход и роли, экраны, генерация агентами-заготовками, оценка попытки, сборка теста. */
class FlowTest extends TestCase
{
    use MakesCourseData;
    use RefreshDatabase;

    private const SQL_REFERENCE = 'SELECT g.name, COUNT(s.id) AS students FROM groups g LEFT JOIN students s ON s.group_id = g.id GROUP BY g.name ORDER BY students DESC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedAccountsAndCourse();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/teacher/dashboard')->assertRedirect('/login');
        $this->getJson('/api/questions')->assertUnauthorized();
    }

    public function test_login_redirects_each_role_to_its_screens(): void
    {
        $this->post('/login', ['email' => 'teacher@utmn.local', 'password' => 'неверный'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', AccountsSeeder::TEACHER)->assertRedirect('/');
        $this->get('/')->assertRedirect(route('teacher.dashboard'));
        $this->post('/logout')->assertRedirect('/login');

        $this->post('/login', AccountsSeeder::STUDENT)->assertRedirect('/');
        $this->get('/')->assertRedirect(route('student.tests'));
    }

    public function test_roles_cannot_open_each_others_screens(): void
    {
        $this->singleQuestion('Q-1');

        $this->actingAs($this->student())->get('/teacher/bank')->assertRedirect(route('home'));
        $this->actingAs($this->student())->patchJson('/api/questions/Q-1', ['status' => 'approved'])->assertForbidden();
        $this->actingAs($this->teacher())->get('/student/test')->assertRedirect(route('home'));
    }

    public function test_all_screens_open(): void
    {
        $q = $this->singleQuestion('Q-1', 0, ['status' => 'pending']);
        $this->makeTest('m1', [$q]);
        $this->makeTest('tr1', [$this->singleQuestion('Q-2')], ['status' => 'training', 'graded' => false]);

        $this->actingAs($this->teacher());
        foreach (['/teacher/dashboard', '/teacher/upload', '/teacher/pipeline', '/teacher/bank', '/teacher/question?id=Q-1',
            '/teacher/test-builder', '/teacher/tests', '/teacher/preview?test=m1', '/teacher/analytics', '/teacher/analytics?test=m1',
            '/teacher/review', '/profile'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->actingAs($this->student());
        foreach (['/student/tests', '/student/test', '/student/test?mode=training', '/student/result', '/student/results', '/profile'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_generation_pipeline_creates_pending_questions(): void
    {
        Storage::fake();
        $this->actingAs($this->teacher());
        $text = 'Транзакция — группа операций, которая выполняется целиком или не выполняется вовсе. '
            .'Уровни изоляции определяют, какие изменения параллельных транзакций видны друг другу.';
        $material = $this->postJson('/api/materials', ['text' => $text, 'title' => 'Конспект'])
            ->assertOk()->json();

        $run = $this->postJson('/api/generation-runs', [
            'material_ids' => [$material['id']], 'types' => ['single', 'open'], 'count' => 4, 'difficulty' => 'mixed',
        ])->assertOk()->json();

        $this->assertSame('done', $run['status']);
        $this->assertSame(4, $run['checked']);
        $this->assertSame(4 - $run['rejected'], Question::where('generation_run_id', $run['id'])->where('status', 'pending')->count());
    }

    public function test_student_attempt_is_graded_and_review_confirmed(): void
    {
        $this->makeTest('m1', [
            $this->singleQuestion('Q-012', 1),
            $this->singleQuestion('Q-031', 2),
            $this->question('Q-027', ['type' => 'sql', 'points' => 3, 'text' => 'Число студентов в группах', 'reference' => self::SQL_REFERENCE]),
            $this->question('Q-044', ['type' => 'open', 'points' => 5, 'text' => 'Зачем нужна нормализация?',
                'reference' => 'Устраняет избыточность и аномалии', 'criteria' => [['Цель', 2], ['Аномалии', 2], ['Пример', 1]]]),
        ]);

        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::where('status', 'in_progress')->latest('id')->firstOrFail();

        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => [
            'Q-012' => [1],                                                // верно
            'Q-031' => [0],                                                // неверно
            'Q-027' => self::SQL_REFERENCE,
            'Q-044' => 'Нормализация устраняет избыточность. Аномалия удаления: теряются сведения о группе.',
        ]])->assertOk();

        $attempt->refresh();
        $this->assertSame('submitted', $attempt->status);
        $answers = $attempt->answers()->with('question')->get()->keyBy(fn ($a) => $a->question->code);
        $this->assertEquals(1, $answers['Q-012']->score);
        $this->assertEquals(0, $answers['Q-031']->score);
        $this->assertEquals(3, $answers['Q-027']->score);
        $this->assertFalse($answers['Q-044']->is_final);

        $this->actingAs($this->teacher());
        $review = Review::where('answer_id', $answers['Q-044']->id)->firstOrFail();
        $this->patchJson("/api/reviews/{$review->id}", ['scores' => [2, 2, 0], 'comment' => 'Нет примера вставки'])->assertOk();

        $this->assertEquals(4, $answers['Q-044']->fresh()->score);
        $this->assertSame('confirmed', $attempt->fresh()->status);
    }

    public function test_analytics_lists_tests_and_opens_the_chosen_one(): void
    {
        $open = $this->question('Q-2', ['type' => 'open', 'points' => 2, 'text' => 'Опишите 1НФ', 'reference' => 'Атомарные значения']);
        $this->makeTest('m1', [$this->singleQuestion('Q-1'), $open]);
        $this->makeTest('m2', [$this->singleQuestion('Q-3')]);
        $this->makeTest('d1', [$this->singleQuestion('Q-4')], ['status' => 'draft']);

        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();
        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => ['Q-1' => [0], 'Q-2' => 'Каждое значение атомарно']])->assertOk();

        $this->actingAs($this->teacher());
        $list = $this->get('/teacher/analytics')->assertOk()->assertViewIs('teacher.analytics-tests')->viewData('appData');
        $tests = collect($list['overview']['tests'])->keyBy('id');
        $this->assertSame(['m1', 'm2'], $tests->keys()->sort()->values()->all());   // черновик d1 не показывается
        $this->assertSame(1, $tests['m1']['pending']);                         // развёрнутый ответ ждёт преподавателя
        $this->assertSame(1, $list['overview']['pending']);
        $this->assertNotNull($tests['m1']['last']);
        $this->assertNull($tests['m2']['last']);                               // по m2 сдач нет
        $this->assertSame('m1', $list['overview']['tests'][0]['id']);          // свежие сдачи сверху

        $detail = $this->get('/teacher/analytics?test=m1')->assertOk()->assertViewIs('teacher.analytics')->viewData('appData');
        $this->assertSame('m1', $detail['analytics']['testId']);
        $this->assertSame(1, $detail['analytics']['submitted']);
        // По одной сдаче выводов «слабее всего тема…» не делаем
        $this->assertFalse($detail['analytics']['insightReady']);
        $this->assertStringContainsString('выводы появятся', $detail['analytics']['insight']);
        // Тест без сдач: тем с «0 %» нет — по ним просто нет данных
        $this->assertSame([], $this->get('/teacher/analytics?test=m2')->viewData('appData')['analytics']['topics']);
        $this->get('/teacher/analytics?test=nope')->assertNotFound();
    }

    public function test_sql_sandbox_allows_only_select(): void
    {
        $this->actingAs($this->student());
        $this->postJson('/api/sql/run', ['sql' => 'DELETE FROM students'])->assertOk()->assertJson(['ok' => false]);
        $this->postJson('/api/sql/run', ['sql' => 'SELECT 1; DELETE FROM students'])->assertOk()->assertJson(['ok' => false]);
        $this->postJson('/api/sql/run', ['sql' => 'SELECT COUNT(*) FROM students'])->assertOk()->assertJson(['ok' => true, 'rows' => [[125]]]);
        $this->postJson('/api/sql/run', ['sql' => 'SELECT nope FROM students'])->assertOk()->assertJson(['ok' => false]);
    }

    public function test_builder_assembles_test_from_approved_questions(): void
    {
        foreach (range(1, 4) as $i) {
            $this->singleQuestion('Q-'.$i);
        }
        $this->singleQuestion('Q-9', 0, ['status' => 'pending']);

        $this->actingAs($this->teacher());
        $r = $this->postJson('/api/tests', [
            'title' => 'Контрольная', 'mode' => 'rules', 'publish' => true, 'topics' => [],
            'matrix' => [['single', 1, 0, 3, 0, 0.7]],
        ])->assertOk()->json();

        $test = Test::where('code', $r['test']['id'])->firstOrFail();
        $this->assertSame('running', $test->status);
        $this->assertNotEmpty($test->questions);
        $this->assertTrue($test->questions->every(fn ($q) => $q->status === 'approved' && $q->type === 'single'));
    }
}
