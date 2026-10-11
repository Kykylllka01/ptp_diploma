<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Test;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCourseData;
use Tests\TestCase;

/** Индивидуальный вариант: каждому студенту свой набор вопросов из пула по матрице теста. */
class VariantsTest extends TestCase
{
    use MakesCourseData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedAccountsAndCourse();
    }

    private function individualTest(): Test
    {
        foreach (range(1, 8) as $i) {
            $this->singleQuestion('Q-'.$i, $i % 4);
        }
        $r = $this->actingAs($this->teacher())->postJson('/api/tests', [
            'title' => 'Контрольная', 'mode' => 'rules', 'publish' => true, 'topics' => [],
            'matrix' => [['single', 1, 0, 3, 0, 0.7]],      // 3 средних вопроса с одним верным
            'settings' => ['individual' => true],
        ])->assertOk()->json();

        return Test::where('code', $r['test']['id'])->firstOrFail();
    }

    private function newStudent(int $n): User
    {
        return User::create(['name' => 'Студент '.$n, 'email' => "s{$n}@utmn.local", 'password' => 'secret-'.$n,
            'role' => User::ROLE_STUDENT, 'group_name' => $this->student()->group_name]);
    }

    public function test_whole_pool_is_attached_and_each_student_gets_a_variant_of_matrix_size(): void
    {
        $test = $this->individualTest();
        $this->assertTrue($test->isIndividual());
        $this->assertSame(8, $test->questions()->count());   // пул целиком

        $sets = [];
        foreach (range(1, 6) as $n) {
            $this->actingAs($this->newStudent($n))->get('/student/test?test='.$test->code)->assertOk();
            $ids = Attempt::latest('id')->firstOrFail()->question_ids;
            $this->assertCount(3, $ids);
            $this->assertEmpty(array_diff($ids, $test->questions()->pluck('questions.id')->all()));
            $sets[] = implode(',', $ids);
        }
        // 56 возможных наборов: шесть одинаковых подряд практически невозможны
        $this->assertGreaterThan(1, count(array_unique($sets)));
    }

    public function test_player_grading_and_result_use_the_variant_not_the_pool(): void
    {
        $test = $this->individualTest();
        $page = $this->actingAs($this->student())->get('/student/test?test='.$test->code)->assertOk()->viewData('appData');
        $attempt = Attempt::firstOrFail();
        $codes = array_column($page['attempt']['questions'], 'id');
        $this->assertCount(3, $codes);

        $this->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => array_fill_keys($codes, [0])])->assertOk();
        $attempt->refresh();
        $this->assertEquals(3, $attempt->max_score);   // максимум — по варианту, а не по пулу из 8
        $this->assertSame(3, $attempt->answers()->count());

        $result = $this->get('/student/result?attempt='.$attempt->id)->viewData('appData');
        $this->assertSame($codes, array_column($result['attempt']['questions'], 'id'));
    }

    public function test_tests_list_shows_variant_size_and_pool(): void
    {
        $test = $this->individualTest();
        $row = collect($this->actingAs($this->teacher())->get('/teacher/tests')->viewData('appData')['tests'])->firstWhere('id', $test->code);

        $this->assertSame(3, $row['count']);
        $this->assertSame(8, $row['pool']);
        $this->assertTrue($row['individual']);
    }
}
