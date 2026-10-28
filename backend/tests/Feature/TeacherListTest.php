<?php

namespace Tests\Feature;

use App\Http\Middleware\OneSessionPerAccount;
use App\Models\Attempt;
use App\Models\Material;
use App\Models\Question;
use App\Models\Test;
use Database\Seeders\AccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCourseData;
use Tests\TestCase;

/** Пожелания преподавателя: разбор без ответов, правка теста, удаление материала с вопросами, один вход. */
class TeacherListTest extends TestCase
{
    use MakesCourseData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedAccountsAndCourse();
    }

    private function submitAs(string $code, array $answers): Attempt
    {
        $this->actingAs($this->student())->get('/student/test?test='.$code)->assertOk();
        $attempt = Attempt::latest('id')->firstOrFail();
        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => $answers])->assertOk();

        return $attempt;
    }

    public function test_review_with_answers_only_hides_keys_and_mistake_practice(): void
    {
        $this->makeTest('m1', [$this->singleQuestion('Q-1', 1)], ['settings' => ['review' => 'answers', 'show_review' => true]]);
        $attempt = $this->submitAs('m1', ['Q-1' => [0]]);

        $data = $this->get('/student/result?attempt='.$attempt->id)->assertOk()->viewData('appData');
        $this->assertTrue($data['result']['showReview']);
        $this->assertSame('answers', $data['result']['reviewMode']);
        $this->assertSame('never', $data['attempt']['questions'][0]['keyHidden']);
        $this->assertArrayNotHasKey('correct', $data['attempt']['questions'][0]);
        $this->assertArrayNotHasKey('explanation', $data['attempt']['questions'][0]);
        $this->assertSame([0], ((array) $data['result']['answers'])['Q-1']);   // свой ответ виден
        // «Работа над ошибками» проверяла бы ответ с ключом — в этом режиме закрыта
        $this->postJson('/api/questions/Q-1/check', ['answer' => [1], 'attempt' => $attempt->id])->assertForbidden();

        // Преподаватель видит ключи всегда
        $teacherView = $this->actingAs($this->teacher())->get('/teacher/attempt?id='.$attempt->id)->assertOk()->viewData('appData');
        $this->assertSame([1], $teacherView['attempt']['questions'][0]['correct']);
    }

    public function test_old_tests_keep_their_review_switch(): void
    {
        $full = $this->makeTest('m1', [$this->singleQuestion('Q-1')], ['settings' => ['show_review' => true]]);
        $none = $this->makeTest('m2', [$this->singleQuestion('Q-2')], ['settings' => ['show_review' => false]]);

        $this->assertSame('full', $full->reviewMode());
        $this->assertSame('none', $none->reviewMode());
    }

    public function test_teacher_edits_published_test_and_its_questions_until_first_attempt(): void
    {
        $test = $this->makeTest('m1', [$this->singleQuestion('Q-1'), $this->singleQuestion('Q-2'), $this->singleQuestion('Q-3')],
            ['minutes' => 45, 'attempts_allowed' => 1, 'user_id' => $this->teacher()->id, 'settings' => ['shuffle' => true]]);
        $this->actingAs($this->teacher());

        $fresh = $this->patchJson('/api/tests/m1', ['minutes' => 30, 'attempts' => 3, 'opens_at' => now()->addHour()->format('Y-m-d\TH:i'),
            'deadline_at' => now()->addDays(2)->format('Y-m-d\TH:i'), 'shuffle' => false, 'review' => 'answers'])->assertOk()->json();
        $test->refresh();
        $this->assertSame(30, $test->minutes);
        $this->assertSame(3, $test->attempts_allowed);
        $this->assertFalse($test->setting('shuffle', true));
        $this->assertSame('answers', $test->reviewMode());
        $this->assertSame('answers', $fresh['settings']['review']);
        $this->assertFalse($fresh['started']);

        $this->patchJson('/api/tests/m1', ['opens_at' => now()->addDays(5)->format('Y-m-d\TH:i')])->assertStatus(422);   // позже срока

        $this->assertSame(['Q-1', 'Q-2', 'Q-3'], array_column($this->getJson('/api/tests/m1/questions')->assertOk()->json('questions'), 'id'));
        $this->deleteJson('/api/tests/m1/questions', ['ids' => ['Q-2']])->assertOk()->assertJson(['removed' => 1]);
        $this->assertSame(['Q-1', 'Q-3'], $test->questions()->pluck('code')->all());
        $this->assertNotNull(Question::where('code', 'Q-2')->first());   // из банка не удалён

        // После первой попытки состав и перемешивание заморожены, а срок и попытки менять можно
        $this->patchJson('/api/tests/m1', ['opens_at' => null])->assertOk();
        $this->submitAs('m1', []);
        $this->actingAs($this->teacher());
        $this->deleteJson('/api/tests/m1/questions', ['ids' => ['Q-1']])->assertStatus(409);
        $this->patchJson('/api/tests/m1', ['shuffle' => true])->assertStatus(409);
        $this->patchJson('/api/tests/m1', ['attempts' => 5])->assertOk()->assertJsonPath('started', true);
    }

    public function test_open_test_keeps_at_least_one_question(): void
    {
        $this->makeTest('m1', [$this->singleQuestion('Q-1')], ['user_id' => $this->teacher()->id]);

        $this->actingAs($this->teacher())->deleteJson('/api/tests/m1/questions', ['ids' => ['Q-1']])->assertStatus(422);
    }

    public function test_material_is_deleted_with_unused_questions_and_used_ones_stay(): void
    {
        $this->actingAs($this->teacher());
        $m = Material::create(['course_id' => 1, 'user_id' => $this->teacher()->id, 'title' => 'Лекция', 'kind' => 'TXT', 'status' => 'ready', 'has_text' => true, 'extracted_text' => 'текст']);
        $unused = $this->singleQuestion('Q-1', 0, ['material_id' => $m->id, 'user_id' => $this->teacher()->id]);
        $inTest = $this->singleQuestion('Q-2', 0, ['material_id' => $m->id, 'user_id' => $this->teacher()->id]);
        $this->makeTest('m1', [$inTest]);

        $this->assertSame(1, $this->getJson('/api/materials/'.$m->id)->assertOk()->json('questions.removable'));
        $this->deleteJson('/api/materials/'.$m->id)->assertOk()->assertExactJson(['deleted' => 1, 'kept' => 1]);

        $this->assertNull(Material::find($m->id));
        $this->assertNull(Question::find($unused->id));
        $this->assertNull($inTest->fresh()->material_id);   // остался в банке и в тесте, без ссылки на материал
        $this->assertSame(1, Test::where('code', 'm1')->firstOrFail()->questions()->count());
    }

    public function test_new_login_ends_the_session_on_the_other_device(): void
    {
        $this->singleQuestion('Q-1');
        $this->post('/login', ['email' => AccountsSeeder::STUDENT['email'], 'password' => AccountsSeeder::STUDENT['password']])->assertRedirect(route('home'));
        $this->get('/student/tests')->assertOk();

        // Тот же логин вошёл на другом устройстве
        OneSessionPerAccount::started($this->student()->fresh());

        $this->postJson('/api/questions/Q-1/check', ['answer' => [0]])->assertUnauthorized()->assertJsonPath('message', OneSessionPerAccount::MESSAGE);
        $this->get('/student/tests')->assertRedirect(route('login'));

        // Войти снова можно — и вернуться туда, куда шёл; уже новое устройство выйдет при следующем входе
        $this->post('/login', ['email' => AccountsSeeder::STUDENT['email'], 'password' => AccountsSeeder::STUDENT['password']])->assertRedirect(url('/student/tests'));
        $this->get('/student/tests')->assertOk();
    }
}
