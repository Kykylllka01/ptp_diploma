<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Material;
use App\Models\Question;
use App\Models\Test;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesCourseData;
use Tests\TestCase;

/** Несколько преподавателей на одном курсе: у записей есть автор, по умолчанию каждый видит своё, удаляет только автор. */
class MultiTeacherTest extends TestCase
{
    use MakesCourseData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedAccountsAndCourse();
        Storage::fake();
    }

    private function colleague(): User
    {
        $this->artisan('teacher:create', ['email' => 'Petrova@UTMN.local', 'name' => 'Петрова Анна Сергеевна', '--password' => 'colleague123'])->assertExitCode(0);

        return User::where('email', 'petrova@utmn.local')->firstOrFail();
    }

    public function test_admin_creates_teacher_account_from_console(): void
    {
        $t = $this->colleague();
        $this->assertSame(User::ROLE_TEACHER, $t->role);
        $this->assertSame('Петрова А. С.', $t->name);
        $this->assertSame('Анна Сергеевна', $t->full_name);
        $this->assertSame('ПА', $t->initials);
        $this->assertTrue(Hash::check('colleague123', $t->password));

        // Та же почта второй раз и короткий пароль — отказ
        $this->artisan('teacher:create', ['email' => 'petrova@utmn.local', 'name' => 'Кто-то'])->assertExitCode(1);
        $this->artisan('teacher:create', ['email' => 'new@utmn.local', 'name' => 'Новый', '--password' => 'short'])->assertExitCode(1);
        // Без --password выдаётся случайный и печатается один раз
        $this->artisan('teacher:create', ['email' => 'ivanov@utmn.local', 'name' => 'Иванов Пётр'])
            ->expectsOutputToContain('Временный пароль:')->assertExitCode(0);
        $this->post('/login', ['email' => 'petrova@utmn.local', 'password' => 'colleague123'])->assertRedirect(route('home'));
    }

    public function test_records_get_author_and_lists_mark_own_and_others(): void
    {
        $colleague = $this->colleague();
        $this->singleQuestion('Q-1');

        // Коллега загружает материал, генерирует вопросы и собирает тест
        $this->actingAs($colleague);
        $m = $this->postJson('/api/materials', ['text' => str_repeat('Нормализация отношений устраняет избыточность. ', 10), 'title' => 'Лекция коллеги'])->assertOk()->json();
        $run = $this->postJson('/api/generation-runs', ['material_ids' => [$m['id']], 'types' => ['single'], 'count' => 2, 'difficulty' => 'mixed'])->assertOk()->json();
        $this->assertTrue(Question::where('generation_run_id', $run['id'])->get()->every(fn ($q) => $q->user_id === $colleague->id));
        $manual = $this->postJson('/api/questions', ['type' => 'single'])->assertOk()->json();
        $t = $this->postJson('/api/tests', ['title' => 'Тест коллеги', 'mode' => 'rules', 'publish' => true, 'topics' => [], 'matrix' => [['single', 1, 0, 1, 0, 1]]])
            ->assertOk()->json('test');
        $this->assertSame($colleague->id, Material::find($m['id'])->user_id);
        $this->assertSame($colleague->id, Question::where('code', $manual['id'])->value('user_id'));
        $this->assertSame($colleague->id, Test::where('code', $t['id'])->value('user_id'));
        $this->assertTrue($t['mine']);

        // Первый преподаватель видит чужое как чужое — с фамилией автора
        $this->actingAs($this->teacher());
        $page = $this->get('/teacher/tests')->assertOk()->viewData('appData');
        $this->assertSame(2, $page['teacherCount']);
        $row = collect($page['tests'])->firstWhere('id', $t['id']);
        $this->assertFalse($row['mine']);
        $this->assertSame('Петрова А. С.', $row['author']);
        $bank = collect($this->get('/teacher/bank')->assertOk()->viewData('appData')['questions'])->keyBy('id');
        $this->assertTrue($bank['Q-1']['mine'] === true || $bank['Q-1']['author'] === null);
        $this->assertFalse($bank[$manual['id']]['mine']);
        $materials = collect($this->getJson('/api/materials')->assertOk()->json())->keyBy('id');
        $this->assertFalse($materials[$m['id']]['mine']);

        // Студенту фамилии преподавателей-авторов не отдаются
        $this->actingAs($this->student());
        $tests = $this->get('/student/tests')->assertOk()->viewData('appData')['tests'];
        $this->assertNotEmpty($tests);
        $this->assertArrayNotHasKey('author', $tests[0]);
    }

    public function test_only_author_deletes_test_material_and_question(): void
    {
        $colleague = $this->colleague();
        $this->actingAs($colleague);
        $m = $this->postJson('/api/materials', ['text' => 'Короткий конспект о ключах и индексах.', 'title' => 'Конспект'])->assertOk()->json();
        $q = $this->postJson('/api/questions', ['type' => 'single'])->assertOk()->json();
        $this->singleQuestion('Q-1');
        $t = $this->postJson('/api/tests', ['title' => 'Контрольная', 'mode' => 'rules', 'publish' => true, 'topics' => [], 'matrix' => [['single', 1, 0, 1, 0, 1]]])
            ->assertOk()->json('test');

        $this->actingAs($this->teacher());
        $this->deleteJson('/api/tests/'.$t['id'])->assertForbidden()->assertJsonPath('message', 'Удалить тест может только его автор: Петрова А. С.');
        $this->deleteJson('/api/materials/'.$m['id'])->assertForbidden();
        $this->deleteJson('/api/questions/'.$q['id'])->assertForbidden();
        // Менять чужое тоже нельзя: закрыть приём, переименовать тест, править или одобрить вопрос
        $this->patchJson('/api/tests/'.$t['id'], ['title' => 'Контрольная (общая)'])->assertForbidden()
            ->assertJsonPath('message', 'Менять тест может только автор: Петрова А. С.');
        $this->patchJson('/api/questions/'.$q['id'], ['text' => 'Чужая правка'])->assertForbidden();
        $this->postJson('/api/questions/bulk', ['ids' => [$q['id']], 'action' => 'approve'])->assertOk()->assertJson(['updated' => 0, 'foreign' => 1]);
        // Пользоваться можно: пройти чужой тест самому, посмотреть вопрос
        $this->getJson('/api/questions/'.$q['id'])->assertOk();

        $this->actingAs($colleague);
        $this->deleteJson('/api/tests/'.$t['id'])->assertNoContent();
        $this->deleteJson('/api/materials/'.$m['id'])->assertOk();
        $this->deleteJson('/api/questions/'.$q['id'])->assertNoContent();
    }

    public function test_single_teacher_sees_no_owner_switch(): void
    {
        $page = $this->actingAs($this->teacher())->get('/teacher/tests')->assertOk()->viewData('appData');
        $this->assertSame(1, $page['teacherCount']);
    }

    public function test_bulk_delete_removes_only_own_unused_questions_of_this_course(): void
    {
        $me = $this->teacher();
        $this->singleQuestion('Q-1', 0, ['user_id' => $me->id]);
        $this->singleQuestion('Q-2', 0, ['user_id' => $me->id]);
        $this->makeTest('t1', [$this->singleQuestion('Q-3', 0, ['user_id' => $me->id])]);    // уже в тесте
        $this->singleQuestion('Q-4', 0, ['user_id' => $this->colleague()->id]);             // чужой
        $other = Course::create(['title' => 'Другой курс']);
        $this->singleQuestion('Q-5', 0, ['user_id' => $me->id, 'course_id' => $other->id]); // другой курс

        $r = $this->actingAs($me)->postJson('/api/questions/bulk', ['ids' => ['Q-1', 'Q-2', 'Q-3', 'Q-4', 'Q-5'], 'action' => 'delete'])
            ->assertOk()->json();

        $this->assertEqualsCanonicalizing(['Q-1', 'Q-2'], $r['deleted']);
        $this->assertSame(1, $r['used']);
        $this->assertSame(1, $r['foreign']);
        $this->assertEqualsCanonicalizing(['Q-3', 'Q-4', 'Q-5'], Question::pluck('code')->all());
    }

    public function test_bulk_approve_does_not_touch_other_courses(): void
    {
        $other = Course::create(['title' => 'Другой курс']);
        $q = $this->singleQuestion('Q-9', 0, ['course_id' => $other->id, 'status' => 'pending']);

        $this->actingAs($this->teacher())->postJson('/api/questions/bulk', ['ids' => ['Q-9'], 'action' => 'approve'])
            ->assertOk()->assertJson(['updated' => 0]);
        $this->assertSame('pending', $q->fresh()->status);
    }
}
