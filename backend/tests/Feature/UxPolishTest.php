<?php

namespace Tests\Feature;

use App\Models\Attempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
        $r = $this->actingAs($this->teacher())->postJson('/api/students/'.$student->id.'/password')->assertOk()->json();
        $this->assertMatchesRegularExpression('/^[a-z2-9]{10}$/', $r['password']);
        $this->assertTrue(Hash::check($r['password'], $student->fresh()->password));
        $this->assertNull($student->fresh()->remember_token);

        // Только студентам и только преподавателем
        $this->postJson('/api/students/'.$this->teacher()->id.'/password')->assertNotFound();
        $this->actingAs($student->fresh())->postJson('/api/students/'.$student->id.'/password')->assertForbidden();
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

    public function test_error_pages_are_in_russian(): void
    {
        $this->get('/no-such-page')->assertNotFound()->assertSee('Страница не найдена')->assertSee('На главную');
        $this->actingAs($this->teacher())->get('/teacher/attempt?id=999999')->assertNotFound()->assertSee('Страница не найдена');
    }
}
