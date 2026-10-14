<?php

namespace Tests\Feature;

use App\Models\TeacherInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\MakesCourseData;
use Tests\TestCase;

/** Админ-панель: администратор выдаёт преподавателям ссылки, по ним они регистрируются сами. */
class AdminPanelTest extends TestCase
{
    use MakesCourseData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedAccountsAndCourse();
    }

    private function admin(): User
    {
        $this->artisan('admin:create', ['email' => 'admin@utmn.local', 'name' => 'Админов Сергей', '--password' => 'admin12345'])->assertExitCode(0);

        return User::where('email', 'admin@utmn.local')->firstOrFail();
    }

    private function form(array $over = []): array
    {
        return $over + ['last_name' => 'петрова', 'first_name' => 'анна', 'middle_name' => 'сергеевна', 'email' => 'Petrova@UTMN.local',
            'password' => 'secret123', 'password_confirmation' => 'secret123'];
    }

    private function guest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function invite(int $maxUses = 1, int $days = 7): TeacherInvite
    {
        $r = $this->actingAs($this->admin())->postJson('/api/admin/invites', ['note' => 'Петрова, кафедра ИБ', 'max_uses' => $maxUses, 'days' => $days])
            ->assertOk()->json();
        $this->guest();

        return TeacherInvite::findOrFail($r['id']);
    }

    public function test_admin_logs_in_to_own_panel_and_others_cannot_open_it(): void
    {
        $admin = $this->admin();
        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->post('/login', ['email' => 'admin@utmn.local', 'password' => 'admin12345'])->assertRedirect(route('home'));
        $this->get('/')->assertRedirect(route('admin.index'));
        $page = $this->get('/admin')->assertOk()->viewData('appData');
        $this->assertSame(1, $page['stats']['teachers']);
        $this->get('/profile')->assertOk()->assertSee('Администратор');

        // Преподаватель и студент в админ-панель не попадают, её API для них закрыт
        $this->actingAs($this->teacher())->get('/admin')->assertRedirect(route('home'));
        $this->postJson('/api/admin/invites', ['max_uses' => 1, 'days' => 7])->assertForbidden();
        $this->actingAs($this->student())->postJson('/api/admin/invites', ['max_uses' => 1, 'days' => 7])->assertForbidden();
        // А администратор — в кабинет преподавателя
        $this->actingAs($admin)->get('/teacher/dashboard')->assertRedirect(route('home'));
    }

    public function test_teacher_registers_by_one_time_link(): void
    {
        $invite = $this->invite();
        $this->assertSame(40, strlen($invite->token));
        $this->get('/teacher-invite/'.$invite->token)->assertOk()->assertSee('Регистрация преподавателя');

        // Роль из формы не берётся
        $this->post('/teacher-invite/'.$invite->token, $this->form(['role' => 'admin']))->assertRedirect(route('teacher.dashboard'));
        $t = User::where('email', 'petrova@utmn.local')->firstOrFail();
        $this->assertAuthenticatedAs($t);
        $this->assertSame(User::ROLE_TEACHER, $t->role);
        $this->assertSame('Петрова А. С.', $t->name);
        $this->assertSame($invite->id, $t->teacher_invite_id);
        $this->assertTrue(Hash::check('secret123', $t->password));
        $this->assertSame('used', $invite->fresh()->status());
        $this->get('/teacher/dashboard')->assertOk();

        // Второй раз одноразовая ссылка не работает
        $this->guest();
        $this->get('/teacher-invite/'.$invite->token)->assertOk()->assertSee('уже зарегистрировались');
        $this->post('/teacher-invite/'.$invite->token, $this->form(['email' => 'second@utmn.local']))->assertRedirect(route('teacher-invite', $invite->token));
        // По недействительной ссылке форма не проверяется — занятость почты не узнать
        $this->post('/teacher-invite/'.str_repeat('x', 40), $this->form(['email' => 'petrova@utmn.local']))->assertSessionDoesntHaveErrors();
        $this->assertNull(User::where('email', 'second@utmn.local')->first());

        // В админ-панели видно, кто пришёл по ссылке
        $page = $this->actingAs(User::where('role', User::ROLE_ADMIN)->first())->get('/admin')->viewData('appData');
        $this->assertSame(['Петрова А. С.'], $page['invites'][0]['teachers']);
        $this->assertSame('Петрова, кафедра ИБ', collect($page['teachers'])->firstWhere('id', $t->id)['invite']);
    }

    public function test_multi_use_expired_revoked_and_wrong_links(): void
    {
        $many = $this->invite(5);
        foreach (['a@utmn.local', 'b@utmn.local'] as $email) {
            $this->post('/teacher-invite/'.$many->token, $this->form(['email' => $email]))->assertRedirect(route('teacher.dashboard'));
            $this->guest();
        }
        $this->assertSame(2, $many->fresh()->uses);
        $this->assertSame('active', $many->fresh()->status());

        // Отозванная
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->first())->deleteJson('/api/admin/invites/'.$many->id)->assertOk()->assertJsonPath('status', 'revoked');
        $this->guest();
        $this->post('/teacher-invite/'.$many->token, $this->form(['email' => 'c@utmn.local']))->assertRedirect();

        // Истёкшая
        $short = TeacherInvite::issue(null, null, 1, 1);
        $this->travel(2)->days();
        $this->get('/teacher-invite/'.$short->token)->assertOk()->assertSee('Срок действия ссылки истёк');
        $this->post('/teacher-invite/'.$short->token, $this->form(['email' => 'd@utmn.local']))->assertRedirect();

        // Выдуманная
        $this->get('/teacher-invite/abc')->assertOk()->assertSee('Ссылка не работает');
        $this->assertSame(0, User::whereIn('email', ['c@utmn.local', 'd@utmn.local'])->count());

        // Неверные параметры ссылки
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->first())->postJson('/api/admin/invites', ['max_uses' => 1000, 'days' => 7])->assertUnprocessable();
    }

    public function test_logged_in_user_must_log_out_to_use_link(): void
    {
        $invite = $this->invite();
        $this->actingAs($this->student())->get('/teacher-invite/'.$invite->token)->assertOk()->assertSee('Вы уже вошли');
        $this->post('/teacher-invite/'.$invite->token, $this->form())->assertRedirect(route('teacher-invite', $invite->token));
        $this->assertSame(0, $invite->fresh()->uses);
        $this->assertSame(User::ROLE_STUDENT, $this->student()->fresh()->role);
    }

    public function test_admin_resets_password_and_closes_access(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();

        $r = $this->actingAs($admin)->postJson('/api/admin/teachers/'.$teacher->id.'/password')->assertOk()->json();
        $this->assertTrue(Hash::check($r['password'], $teacher->fresh()->password));
        // Только преподавателям
        $this->postJson('/api/admin/teachers/'.$this->student()->id.'/password')->assertNotFound();
        $this->patchJson('/api/admin/teachers/'.$admin->id, ['disabled' => true])->assertNotFound();

        $this->patchJson('/api/admin/teachers/'.$teacher->id, ['disabled' => true])->assertOk()->assertJsonPath('disabled', true);
        // Открытая сессия заканчивается, войти заново нельзя
        $this->actingAs($teacher->fresh())->get('/teacher/dashboard')->assertRedirect(route('login'));
        $this->guest();
        $this->post('/login', ['email' => $teacher->email, 'password' => $r['password']])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($admin)->patchJson('/api/admin/teachers/'.$teacher->id, ['disabled' => false])->assertOk()->assertJsonPath('disabled', false);
        $this->guest();
        $this->post('/login', ['email' => $teacher->email, 'password' => $r['password']])->assertRedirect(route('home'));
    }
}
