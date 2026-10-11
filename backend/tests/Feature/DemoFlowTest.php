<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Question;
use App\Models\Review;
use App\Models\Test;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Сквозные сценарии на демо-данных: экраны открываются, агенты-заготовки работают, попытка оценивается. */
class DemoFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DemoSeeder::class);
    }

    private function teacher(): User
    {
        return User::where('email', AccountsSeeder::TEACHER['email'])->firstOrFail();
    }

    private function student(): User
    {
        return User::where('email', AccountsSeeder::STUDENT['email'])->firstOrFail();
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
        $this->actingAs($this->student())->get('/teacher/bank')->assertRedirect(route('home'));
        $this->actingAs($this->student())->patchJson('/api/questions/Q-121', ['status' => 'approved'])->assertForbidden();
        $this->actingAs($this->teacher())->get('/student/test')->assertRedirect(route('home'));
        // Проверка ответа доступна только для тренировочных вопросов
        $this->actingAs($this->student())->postJson('/api/questions/Q-044/check', ['answer' => 'x'])->assertForbidden();
    }

    public function test_all_screens_open(): void
    {
        $this->actingAs($this->teacher());
        foreach (['/teacher/dashboard', '/teacher/upload', '/teacher/pipeline', '/teacher/bank',
            '/teacher/question?id=Q-121', '/teacher/test-builder', '/teacher/analytics', '/teacher/review'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->actingAs($this->student());
        foreach (['/student/tests', '/student/test', '/student/test?mode=training', '/student/result'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_generation_pipeline_creates_pending_questions(): void
    {
        $this->actingAs($this->teacher());
        $material = $this->postJson('/api/materials', ['text' => 'Транзакции и уровни изоляции', 'title' => 'Конспект'])
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
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::where('status', 'in_progress')->latest('id')->firstOrFail();

        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => [
            'Q-012' => [1],                                                // верно
            'Q-031' => [0],                                                // неверно
            'Q-027' => "SELECT g.name, COUNT(s.id) AS students FROM groups g LEFT JOIN students s ON s.group_id = g.id GROUP BY g.name ORDER BY students DESC",
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

    public function test_sql_sandbox_allows_only_select(): void
    {
        $this->actingAs($this->student());
        $this->postJson('/api/sql/run', ['sql' => 'DELETE FROM students'])->assertOk()->assertJson(['ok' => false]);
        $this->postJson('/api/sql/run', ['sql' => 'SELECT COUNT(*) FROM students'])->assertOk()->assertJson(['ok' => true, 'rows' => [[125]]]);
    }

    public function test_builder_assembles_test_from_approved_questions(): void
    {
        $this->actingAs($this->teacher());
        $r = $this->postJson('/api/tests', [
            'title' => 'Контрольная', 'mode' => 'rules', 'publish' => true, 'topics' => [],
            'matrix' => [['single', 1, 0, 3, 0, 0.7]],
        ])->assertOk()->json();

        $test = Test::where('code', $r['test']['id'])->firstOrFail();
        $this->assertSame('running', $test->status);
        $this->assertTrue($test->questions->every(fn ($q) => $q->status === 'approved' && $q->type === 'single'));
    }
}
