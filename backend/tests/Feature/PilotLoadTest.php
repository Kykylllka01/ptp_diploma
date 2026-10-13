<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Attempt;
use App\Models\Course;
use App\Models\Group;
use App\Models\Test;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesCourseData;
use Tests\TestCase;

/**
 * Пилот на 200 студентов из разных групп с одного вузовского Wi-Fi: лимиты запросов не должны
 * отсекать честных пользователей, а страницы — делать запросы к базе на каждую строку.
 */
class PilotLoadTest extends TestCase
{
    use MakesCourseData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedAccountsAndCourse();
    }

    /** Следующий запрос — от гостя (выход без запроса /logout). */
    private function guest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function students(int $n, ?Group $group = null): \Illuminate\Support\Collection
    {
        return collect(range(1, $n))->map(fn ($i) => User::create([
            'name' => 'Студент '.$i, 'email' => 's'.$i.'-'.($group?->id ?? 0).'@utmn.local', 'role' => User::ROLE_STUDENT,
            'password' => 'secret123', 'group_id' => $group?->id,
        ]));
    }

    /** Сколько запросов к базе делает запрос к странице. */
    private function queries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_whole_group_logs_in_from_one_ip_but_password_guessing_is_stopped(): void
    {
        foreach ($this->students(40) as $s) {
            $this->post('/login', ['email' => $s->email, 'password' => 'secret123'])->assertRedirect(route('home'));
            $this->assertAuthenticatedAs($s);
            $this->guest();
        }

        $s = User::where('email', 's1-0@utmn.local')->first();
        // Вместе с удачным входом выше — 10 попыток за минуту, одиннадцатая отклоняется
        foreach (range(1, 9) as $i) {
            $this->post('/login', ['email' => $s->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $s->email, 'password' => 'wrong'])->assertStatus(429);
        // Почта массивом — ошибка проверки формы, а не 500
        $this->post('/login', ['email' => ['x@utmn.local'], 'password' => 'wrong'])->assertSessionHasErrors('email');
        // Соседу по Wi-Fi чужой подбор пароля не мешает
        $this->post('/login', ['email' => 's2-0@utmn.local', 'password' => 'secret123'])->assertRedirect(route('home'));
        $this->assertAuthenticated();
    }

    public function test_api_limit_counts_each_student_separately(): void
    {
        $this->makeTest('m1', [$this->singleQuestion('Q-1'), $this->singleQuestion('Q-2')], ['group_name' => null]);
        [$a, $b] = $this->students(2)->all();

        $this->actingAs($a)->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::where('user_id', $a->id)->firstOrFail();
        // Активный студент: автосохранение раз в секунду дольше минуты
        foreach (range(1, 90) as $i) {
            $this->putJson("/api/attempts/{$attempt->id}", ['answers' => ['Q-1' => [$i % 4]], 'current' => 0])->assertOk();
        }
        // Второй студент с того же IP не упирается в лимит первого
        $this->actingAs($b)->get('/student/test?test=m1')->assertOk();
        $other = Attempt::where('user_id', $b->id)->firstOrFail();
        $this->putJson("/api/attempts/{$other->id}", ['answers' => ['Q-1' => [0]]])->assertOk();
    }

    public function test_autosave_writes_only_changed_answers(): void
    {
        $questions = collect(range(1, 30))->map(fn ($i) => $this->singleQuestion('Q-'.$i));
        $this->makeTest('m1', $questions->all());
        $this->actingAs($this->student())->get('/student/test?test=m1')->assertOk();
        $attempt = Attempt::firstOrFail();
        $answers = $questions->mapWithKeys(fn ($q) => [$q->code => [1]])->all();

        $first = $this->queries(fn () => $this->putJson("/api/attempts/{$attempt->id}", ['answers' => $answers, 'current' => 3])->assertOk());
        $this->assertSame(30, Answer::count());

        // Тот же набор ответов ещё раз (автосохранение без изменений) — ни одной записи в ответы
        $again = $this->queries(fn () => $this->putJson("/api/attempts/{$attempt->id}", ['answers' => $answers, 'current' => 3])->assertOk());
        $this->assertLessThan(20, $first, 'первое сохранение 30 ответов — одним пакетом');
        $this->assertLessThan(15, $again);

        // Изменился один ответ — меняется только он, остальные на месте
        $answers['Q-7'] = [2];
        $this->putJson("/api/attempts/{$attempt->id}", ['answers' => $answers, 'current' => 7])->assertOk();
        $byCode = Answer::with('question')->get()->keyBy(fn ($a) => $a->question->code);
        $this->assertSame([2], $byCode['Q-7']->value);
        $this->assertSame([1], $byCode['Q-8']->value);
        $this->assertSame(7, $attempt->fresh()->current_index);

        // Сдача видит сохранённые ответы
        $this->postJson("/api/attempts/{$attempt->id}/submit")->assertOk();
        $this->assertSame(30.0, (float) $attempt->fresh()->max_score);
    }

    public function test_pages_do_not_grow_queries_with_number_of_students_and_tests(): void
    {
        $course = Course::firstOrFail();
        $groups = collect(range(1, 8))->map(fn ($i) => Group::create(['course_id' => $course->id, 'name' => 'Пилот '.$i, 'invite_code' => 'pilotcode'.$i]));
        foreach ($groups as $g) {
            $this->students(25, $g);
        }
        $questions = collect(range(1, 60))->map(fn ($i) => $this->singleQuestion('Q-'.$i));
        foreach (range(1, 20) as $i) {
            $t = Test::create(['code' => 't'.$i, 'course_id' => $course->id, 'title' => 'Тест '.$i, 'status' => 'running', 'audience' => 'groups', 'minutes' => 45, 'attempts_allowed' => 2]);
            $t->groups()->attach($groups[$i % 8]->id);
            $t->questions()->attach($questions->slice($i, 10)->mapWithKeys(fn ($q, $k) => [$q->id => ['position' => $k]])->all());
            // Сдачи: каждый студент группы прошёл тест
            foreach (User::where('group_id', $groups[$i % 8]->id)->get() as $u) {
                Attempt::create(['test_id' => $t->id, 'user_id' => $u->id, 'group_id' => $u->group_id, 'status' => 'confirmed',
                    'score' => 7, 'max_score' => 10, 'percent' => 70, 'started_at' => now()->subHour(), 'submitted_at' => now()]);
            }
        }
        $student = User::where('group_id', $groups[1]->id)->first();

        $budgets = [
            [$this->teacher(), '/teacher/tests', 15],
            [$this->teacher(), '/teacher/dashboard', 20],
            [$this->teacher(), '/teacher/analytics?test=t1', 30],
            [$this->teacher(), '/teacher/gradebook?group='.$groups[1]->id, 15],
            [$this->teacher(), '/teacher/groups', 10],
            [$this->teacher(), '/teacher/analytics', 20],
            [$student, '/student/tests', 15],
            [$student, '/student/results', 15],
        ];
        foreach ($budgets as [$user, $url, $max]) {
            $this->actingAs($user);
            $n = $this->queries(fn () => $this->get($url)->assertOk());
            $this->assertLessThanOrEqual($max, $n, $url.' делает '.$n.' запросов к базе (бюджет '.$max.')');
        }
    }

    public function test_sql_sandbox_frees_its_slot_after_each_query(): void
    {
        // Одно место на всех: если бы место не освобождалось, второй запрос ждал бы и упал
        config(['agents.sql_sandbox_parallel' => 1]);
        $sandbox = app(\App\Services\SqlSandbox::class);
        foreach (range(1, 3) as $i) {
            $this->assertTrue($sandbox->run('SELECT count(*) FROM students')['ok']);
        }
        $this->assertFalse($sandbox->run('SELECT * FROM nowhere')['ok']);
        $this->assertTrue($sandbox->run('SELECT name FROM groups')['ok']);
    }

    public function test_pilot_check_reports_problems_without_printing_secrets(): void
    {
        config(['queue.default' => 'sync', 'agents.driver' => 'llm', 'agents.llm.key' => 'sk-secret-value']);
        $this->artisan('pilot:check')
            ->expectsOutputToContain('Учебная база SQL')
            ->doesntExpectOutputToContain('sk-secret-value')
            ->assertExitCode(1);   // очередь sync — ошибка: развёрнутые ответы оценивались бы прямо при сдаче
    }
}
