<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCourseData;
use Tests\TestCase;

/** Группы без Модеуса: регистрация по ссылке-приглашению, тесты для нескольких групп, перевод студентов. */
class GroupsTest extends TestCase
{
    use MakesCourseData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedAccountsAndCourse();
    }

    private function form(array $over = []): array
    {
        return $over + ['last_name' => 'петров', 'first_name' => 'иван', 'middle_name' => 'сергеевич',
            'email' => 'Petrov@Utmn.local', 'password' => 'secret-123', 'password_confirmation' => 'secret-123'];
    }

    public function test_student_registers_by_invite_and_lands_in_the_group(): void
    {
        $g = $this->group('ИСиТ-23-1');
        $this->get('/join/'.$g->invite_code)->assertOk()->assertSee('ИСиТ-23-1');

        $this->post('/join/'.$g->invite_code, $this->form() + ['role' => 'teacher'])->assertRedirect('/student/tests');

        $u = User::where('email', 'petrov@utmn.local')->firstOrFail();
        $this->assertSame(User::ROLE_STUDENT, $u->role);          // роль из формы не принимается
        $this->assertSame($g->id, $u->group_id);
        $this->assertSame('Петров И. С.', $u->name);
        $this->assertSame('ИП', $u->initials);
        $this->assertAuthenticatedAs($u);
    }

    public function test_closed_or_unknown_invite_does_not_register(): void
    {
        $g = $this->group('Закрытая');
        $g->update(['invite_open' => false]);

        $this->get('/join/'.$g->invite_code)->assertOk()->assertSee('Ссылка не работает');
        $this->post('/join/'.$g->invite_code, $this->form())->assertRedirect('/join/'.$g->invite_code);
        $this->post('/join/zzzzzzzzzzzz', $this->form())->assertRedirect('/join/zzzzzzzzzzzz');
        $this->assertDatabaseMissing('users', ['email' => 'petrov@utmn.local']);

        // Код вводят вручную или вставляют ссылку целиком
        $open = $this->group('Открытая');
        $this->get('/register?code='.urlencode(' '.strtoupper($open->invite_code).' '))->assertRedirect('/join/'.$open->invite_code);
        $this->get('/register?code='.urlencode('http://ptp.localhost/join/'.$open->invite_code))->assertRedirect('/join/'.$open->invite_code);
        $this->get('/register?code=%3Cscript%3E')->assertOk()->assertSee('Код не подходит');
    }

    public function test_student_cannot_switch_group_by_someone_elses_link(): void
    {
        $other = $this->group('Чужая группа');
        $this->actingAs($this->student())->get('/join/'.$other->invite_code)->assertOk()->assertSee('Вы уже в группе');
        $this->post('/join/'.$other->invite_code.'/accept')->assertForbidden();
        $this->assertNotSame($other->id, $this->student()->fresh()->group_id);

        // Студент без группы (создан из консоли) вступает сам
        $loose = User::create(['name' => 'Без группы', 'email' => 'loose@utmn.local', 'password' => 'secret-123', 'role' => User::ROLE_STUDENT]);
        $this->actingAs($loose)->post('/join/'.$other->invite_code.'/accept')->assertRedirect('/student/tests');
        $this->assertSame($other->id, $loose->fresh()->group_id);
    }

    public function test_test_for_several_groups_is_visible_only_to_them(): void
    {
        $this->singleQuestion('Q-1');
        $a = $this->student()->group;
        $b = $this->group('Группа 2');
        $c = $this->group('Группа 3');
        $inC = User::create(['name' => 'Третий', 'email' => 'c@utmn.local', 'password' => 'secret-123', 'role' => User::ROLE_STUDENT, 'group_id' => $c->id]);

        $r = $this->actingAs($this->teacher())->postJson('/api/tests', ['title' => 'Пробный', 'mode' => 'rules', 'publish' => true,
            'topics' => [], 'groups' => [$a->id, $b->id], 'matrix' => [['single', 1, 0, 1, 0, 0.7]]])->assertOk()->json();
        $this->assertSame($a->name.', '.$b->name, $r['test']['group']);
        $code = $r['test']['id'];

        $this->actingAs($this->student())->get('/student/test?test='.$code)->assertOk();
        $this->actingAs($inC)->get('/student/test?test='.$code)->assertNotFound();

        // Несуществующая группа — отказ
        $this->actingAs($this->teacher())->postJson('/api/tests', ['title' => 'x', 'mode' => 'rules', 'topics' => [],
            'groups' => [999], 'matrix' => [['single', 1, 0, 1, 0, 0.7]]])->assertStatus(422);

        // Добавили третью группу к тесту — теперь видит и она
        $this->patchJson('/api/tests/'.$code, ['groups' => [$a->id, $b->id, $c->id]])->assertOk();
        $this->actingAs($inC)->get('/student/test?test='.$code)->assertOk();
    }

    public function test_group_management_by_teacher(): void
    {
        $this->actingAs($this->teacher());
        $g = $this->postJson('/api/groups', ['name' => ' ИСиТ-23-2 '])->assertOk()->json();
        $this->assertSame('ИСиТ-23-2', $g['name']);
        $this->postJson('/api/groups', ['name' => 'ИСиТ-23-2'])->assertStatus(422);
        $this->assertMatchesRegularExpression('/^[a-z2-9]{12}$/', $g['code']);

        $closed = $this->patchJson('/api/groups/'.$g['id'], ['invite_open' => false])->assertOk()->json();
        $this->assertFalse($closed['open']);

        $fresh = $this->postJson('/api/groups/'.$g['id'].'/invite')->assertOk()->json();
        $this->assertNotSame($g['code'], $fresh['code']);
        $this->assertNull(Group::where('invite_code', $g['code'])->first());

        // Группу со студентами или тестами не удалить
        $this->deleteJson('/api/groups/'.$this->student()->group_id)->assertStatus(409);
        $withTest = $this->group('С тестом');
        $this->makeTest('m1', [$this->singleQuestion('Q-1')], ['group_name' => 'С тестом']);
        $this->deleteJson('/api/groups/'.$withTest->id)->assertStatus(409);
        $this->deleteJson('/api/groups/'.$g['id'])->assertNoContent();

        $this->get('/teacher/groups')->assertOk()->assertSee('Группы');
    }

    public function test_moving_a_student_keeps_old_results_in_old_group(): void
    {
        $this->makeTest('m1', [$this->singleQuestion('Q-1')]);
        $old = $this->student()->group;
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();
        $this->assertSame($old->id, $attempt->group_id);
        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => ['Q-1' => [0]]])->assertOk();

        $new = $this->group('Новая');
        $this->actingAs($this->teacher())->patchJson('/api/students/'.$this->student()->id.'/group', ['group_id' => $new->id])->assertOk();
        $this->assertSame($new->id, $this->student()->fresh()->group_id);

        $row = $this->get('/teacher/analytics?test=m1')->viewData('appData')['analytics']['students'][0];
        $this->assertSame($old->name, $row['group']);

        // Студента можно убрать из группы, но не «перевести» преподавателя
        $this->patchJson('/api/students/'.$this->student()->id.'/group', ['group_id' => null])->assertOk();
        $this->patchJson('/api/students/'.$this->teacher()->id.'/group', ['group_id' => $new->id])->assertNotFound();
    }

    public function test_students_cannot_manage_groups(): void
    {
        $g = $this->student()->group;
        $this->actingAs($this->student());
        $this->postJson('/api/groups', ['name' => 'Моя'])->assertForbidden();
        $this->patchJson('/api/groups/'.$g->id, ['invite_open' => false])->assertForbidden();
        $this->patchJson('/api/students/'.$this->student()->id.'/group', ['group_id' => null])->assertForbidden();
        $this->get('/teacher/groups')->assertRedirect();
    }
}
