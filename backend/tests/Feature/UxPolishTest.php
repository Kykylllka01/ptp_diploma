<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Attempt;
use Database\Seeders\AccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesCourseData;
use Tests\TestCase;

/** Доработки интерфейса: тема вопроса, пары через дефис, временный пароль, очередь проверки по тесту, русские ошибки. */
class UxPolishTest extends TestCase
{
    use MakesCourseData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedAccountsAndCourse();
    }

    public function test_question_topic_can_be_changed_and_saved_without_status(): void
    {
        $q = $this->singleQuestion('Q-1', 0, ['status' => 'approved']);
        $r = $this->actingAs($this->teacher())->patchJson('/api/questions/Q-1', ['topic' => 'Транзакции', 'text' => 'Новый текст'])->assertOk()->json();
        $this->assertSame('Транзакции', $r['topic']);
        $this->assertSame('approved', $q->fresh()->status);   // просто «Сохранить» не меняет статус
    }

    public function test_match_pairs_accept_hyphen_and_en_dash(): void
    {
        $q = $this->question('Q-2', ['type' => 'match', 'text' => 'Сопоставьте', 'options' => [
            ['text' => '1НФ - атомарность', 'correct' => true], ['text' => '2НФ – полная зависимость', 'correct' => true], ['text' => 'Лишнее: транзитивность'],
        ]]);
        $parts = $q->matchParts();
        $this->assertSame(['1НФ', '2НФ'], $parts['terms']);
        $this->assertEqualsCanonicalizing(['атомарность', 'полная зависимость', 'транзитивность'], $parts['definitions']);
    }

    public function test_teacher_issues_temporary_password_to_student(): void
    {
        $student = $this->student();
        $student->forceFill(['remember_token' => 'old-token'])->save();
        $r = $this->actingAs($this->teacher())->postJson('/api/students/'.$student->id.'/password')->assertOk()->json();
        $this->assertMatchesRegularExpression('/^[a-z2-9]{10}$/', $r['password']);
        $this->assertTrue(Hash::check($r['password'], $student->fresh()->password));
        $this->assertNull($student->fresh()->remember_token);

        // Только студентам и только преподавателем
        $this->postJson('/api/students/'.$this->teacher()->id.'/password')->assertNotFound();
        $this->actingAs($student->fresh())->postJson('/api/students/'.$student->id.'/password')->assertForbidden();

        // Старый пароль больше не подходит, новый — подходит
        auth()->logout();
        $this->post('/login', ['email' => $student->email, 'password' => AccountsSeeder::STUDENT['password']])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $student->email, 'password' => $r['password']])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($student);
    }

    public function test_review_queue_knows_test_and_attempt_of_each_answer(): void
    {
        $this->makeTest('m1', [$this->question('Q-3', ['type' => 'open', 'points' => 2, 'text' => 'Что такое 1НФ?',
            'reference' => 'Атомарные значения', 'criteria' => [['Определение', 2]]])]);
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();
        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => ['Q-3' => 'Значения атомарны']])->assertOk();

        $review = $this->actingAs($this->teacher())->get('/teacher/review?test=m1')->assertOk()->viewData('appData')['reviews'][0];
        $this->assertSame('m1', $review['test']);
        $this->assertSame('Тест m1', $review['testTitle']);
        $this->assertSame($attempt->id, $review['attemptId']);
    }

    public function test_deadline_and_groups_can_be_changed_after_publishing(): void
    {
        $test = $this->makeTest('m1', [$this->singleQuestion('Q-1')]);
        $other = $this->group('ИСиТ-2');
        $this->actingAs($this->teacher());

        $local = now()->addDays(3)->setTime(18, 30)->format('Y-m-d\TH:i');
        $r = $this->patchJson('/api/tests/m1', ['deadline_at' => $local, 'groups' => [$other->id]])->assertOk()->json();
        $this->assertSame($local, $r['deadlineLocal']);                 // тот же час, без сдвига поясов
        $this->assertSame([$other->id], $r['groups']);
        $this->assertSame('groups', $test->fresh()->audience);

        $this->patchJson('/api/tests/m1', ['deadline_at' => now()->subDay()->format('Y-m-d\TH:i')])
            ->assertStatus(422)->assertJsonPath('errors.deadline_at.0', 'Срок сдачи уже прошёл — выберите время в будущем');
        // Ни одной группы — тест снова для всех студентов курса
        $this->patchJson('/api/tests/m1', ['groups' => []])->assertOk();
        $this->assertSame('all', $test->fresh()->audience);
    }

    public function test_groups_of_closed_test_with_past_deadline_can_be_changed(): void
    {
        $past = now()->subDays(2)->setTime(12, 0);
        $test = $this->makeTest('m2', [$this->singleQuestion('Q-1')], ['status' => 'closed', 'deadline_at' => $past]);
        $other = $this->group('ИСиТ-2');

        // Окно присылает прежний срок — он уже в прошлом, но не изменился, поэтому не мешает сохранить группы
        $this->actingAs($this->teacher())->patchJson('/api/tests/m2', ['deadline_at' => $past->format('Y-m-d\TH:i'), 'groups' => [$other->id]])->assertOk();
        $this->assertSame([$other->id], $test->fresh()->groups()->pluck('student_groups.id')->all());
    }

    public function test_only_unused_question_can_be_deleted(): void
    {
        $this->actingAs($this->teacher());
        $code = $this->postJson('/api/questions', ['type' => 'single'])->assertOk()->json('id');   // «Добавить вручную»
        $this->deleteJson('/api/questions/'.$code)->assertNoContent();
        $this->getJson('/api/questions/'.$code)->assertNotFound();

        $this->makeTest('m1', [$this->singleQuestion('Q-9')]);
        $this->deleteJson('/api/questions/Q-9')->assertStatus(409);           // уже в тесте
        $this->actingAs($this->student())->deleteJson('/api/questions/Q-9')->assertForbidden();
    }

    public function test_question_with_answers_cannot_be_deleted_even_outside_tests(): void
    {
        $q = $this->singleQuestion('Q-7');
        $test = $this->makeTest('m3', [$q]);
        $attempt = Attempt::create(['test_id' => $test->id, 'user_id' => $this->student()->id, 'status' => 'submitted', 'question_ids' => [$q->id]]);
        Answer::create(['attempt_id' => $attempt->id, 'question_id' => $q->id, 'value' => [0], 'score' => 1, 'max_score' => 1, 'is_final' => true]);
        $test->questions()->detach();                                       // из теста убрали, но ответы студентов остались

        $this->actingAs($this->teacher())->deleteJson('/api/questions/Q-7')->assertStatus(409);
        $this->assertNotNull($q->fresh());
    }

    public function test_uploaded_docx_downloads_as_docx(): void
    {
        Storage::fake('local');
        $this->actingAs($this->teacher());
        $m = $this->post('/api/materials', ['file' => UploadedFile::fake()->create('Лекция 3.docx', 12)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame('DOCX', $m['kind']);

        $disposition = $this->get('/teacher/materials/'.$m['id'].'/file')->assertOk()->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertMatchesRegularExpression('/\.docx"?$/', $disposition);
    }

    public function test_error_pages_are_in_russian(): void
    {
        $this->get('/no-such-page')->assertNotFound()->assertSee('Страница не найдена')->assertSee('На главную');
        $this->actingAs($this->teacher())->get('/teacher/attempt?id=999999')->assertNotFound()->assertSee('Страница не найдена');
    }

    public function test_server_error_page_never_shows_exception_details(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_boom', fn () => throw new \RuntimeException('Ошибка: пароль базы secret-123'));

        $this->get('/_boom')->assertStatus(500)->assertSee('Что-то пошло не так')
            ->assertDontSee('secret-123')->assertDontSee('RuntimeException');
    }
}
