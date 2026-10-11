<?php

namespace Tests\Feature;

use App\Agents\Contracts\AnalystAgent;
use App\Agents\Contracts\CriticAgent;
use App\Agents\Llm\AgentLlm;
use App\Agents\Llm\HttpLlmClient;
use App\Agents\Llm\LlmGraderAgent;
use App\Jobs\RunGenerationPipeline;
use App\Models\GenerationRun;
use App\Models\LlmCall;
use App\Models\Material;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\MakesCourseData;
use Tests\TestCase;

/** Сбои модели не выдаются за «плохие вопросы», запуски не зависают, Оценщик не тратит лишнего. */
class AgentsReliabilityTest extends TestCase
{
    use MakesCourseData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedAccountsAndCourse();
        Storage::fake();
        AgentLlm::context([]);
    }

    private function material(): array
    {
        return $this->actingAs($this->teacher())->postJson('/api/materials', [
            'title' => 'Нормализация',
            'text' => 'Нормализация ('.uniqid().') — процесс приведения отношений к нормальным формам. Первая нормальная форма требует атомарных значений. '
                .'Вторая нормальная форма устраняет частичные зависимости от составного ключа.',
        ])->assertOk()->json();
    }

    private function generate(array $material, int $count = 3): GenerationRun
    {
        $r = $this->postJson('/api/generation-runs', ['material_ids' => [$material['id']], 'types' => ['single'], 'count' => $count, 'difficulty' => 'mixed'])->assertOk()->json();

        return GenerationRun::findOrFail($r['id']);
    }

    // ------------------------------------------------- Конвейер

    public function test_critic_outage_keeps_paid_questions_for_teacher_instead_of_rejecting(): void
    {
        $material = $this->material();
        // Критик падает через раз: подряд не больше одной ошибки, запуск доходит до конца
        $this->app->bind(CriticAgent::class, fn () => new class implements CriticAgent
        {
            private static int $n = 0;

            public function review(array $draft): array
            {
                if (self::$n++ % 2 === 0) {
                    throw new RuntimeException('HTTP 503');
                }

                return ['verdict' => 'accept', 'score' => 90, 'checks' => [], 'flag' => null];
            }
        });

        $run = $this->generate($material, 4);

        $this->assertSame('done', $run->status);
        $this->assertSame(0, $run->rejected);   // сбой — не «отклонено»
        $this->assertSame(4, Question::where('generation_run_id', $run->id)->count());
        $this->assertSame(2, Question::where('generation_run_id', $run->id)->where('flag_text', 'Критик не смог проверить вопрос — проверьте сами')->count());
    }

    public function test_api_outage_fails_the_run_and_releases_materials(): void
    {
        $material = $this->material();
        $this->app->bind(CriticAgent::class, fn () => new class implements CriticAgent
        {
            public function review(array $draft): array
            {
                throw new RuntimeException('Ошибка API модели (HTTP 402): Insufficient Balance');
            }
        });

        $run = $this->generate($material, 5);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Нейросеть недоступна', $run->error);
        $this->assertStringContainsString('Insufficient Balance', $run->error);
        $this->assertNotSame('processing', Material::find($material['id'])->status);
        $this->assertNotContains('work', array_column($run->agent_states, 0));
    }

    public function test_one_broken_material_does_not_stop_the_others(): void
    {
        $good = $this->material();
        $bad = $this->material();
        $this->app->bind(AnalystAgent::class, fn () => new class($bad['id']) implements AnalystAgent
        {
            public function __construct(private int $badId)
            {
            }

            public function analyze(Material $material, array $courseTopics): array
            {
                if ($material->id === $this->badId) {
                    throw new RuntimeException('битый ответ модели');
                }

                return app(\App\Agents\Fake\FakeAnalystAgent::class)->analyze($material, $courseTopics);
            }
        });

        $r = $this->postJson('/api/generation-runs', ['material_ids' => [$good['id'], $bad['id']], 'types' => ['single'], 'count' => 2, 'difficulty' => 'mixed'])->assertOk()->json();
        $run = GenerationRun::findOrFail($r['id']);

        $this->assertSame('done', $run->status);
        $this->assertGreaterThan(0, Question::where('generation_run_id', $run->id)->count());
        $this->assertTrue($run->logs()->where('message', 'like', '%не разобран%')->exists());
    }

    public function test_killed_generation_job_marks_the_run_failed(): void
    {
        $material = $this->material();
        $run = GenerationRun::create(['course_id' => 1, 'user_id' => $this->teacher()->id, 'status' => 'running',
            'params' => ['material_ids' => [$material['id']]], 'agent_states' => ['analyst' => ['work', 'Работает', '']]]);
        Material::whereKey($material['id'])->update(['status' => 'processing']);

        (new RunGenerationPipeline($run))->failed(new RuntimeException('Job has been attempted too many times'));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('error', $run->agent_states['analyst'][0]);
        $this->assertNotSame('processing', Material::find($material['id'])->status);
    }

    // ------------------------------------------------- Оценщик

    private function grader(array $answers): array
    {
        $client = new class($answers) extends HttpLlmClient
        {
            public int $calls = 0;

            public function __construct(private array $answers)
            {
                parent::__construct([]);
            }

            public function chat(array $messages, array $options = []): array
            {
                $a = $this->answers[min($this->calls++, count($this->answers) - 1)];
                if ($a instanceof \Throwable) {
                    throw $a;
                }

                return ['content' => json_encode($a), 'model' => 'deepseek-v4-pro', 'tokens_in' => 1000,
                    'tokens_cached' => $this->calls > 1 ? 900 : 0, 'tokens_out' => 200, 'tokens_reasoning' => 0, 'ms' => 10];
            }
        };
        $question = $this->question('Q-1', ['type' => 'open', 'points' => 3, 'text' => 'Что такое 1НФ?', 'reference' => 'Атомарные значения',
            'criteria' => [['Определение', 2], ['Пример', 1]]]);
        $result = (new LlmGraderAgent(new AgentLlm($client)))->grade($question, 'Все значения атрибутов атомарны, например телефон в отдельной строке.');

        return [$result, $client->calls];
    }

    private static function run2(int $a, string $sa, int $b, string $sb, bool $uncertain = false): array
    {
        return ['criteria' => [['index' => 0, 'score' => $a, 'status' => $sa, 'reason' => 'r', 'evidence' => []],
            ['index' => 1, 'score' => $b, 'status' => $sb, 'reason' => 'r', 'evidence' => []]],
            'injection' => false, 'uncertain' => $uncertain, 'comment' => 'ok'];
    }

    public function test_grader_stops_after_two_agreeing_runs(): void
    {
        [$r, $calls] = $this->grader([self::run2(2, 'ok', 1, 'ok'), self::run2(2, 'ok', 1, 'ok'), self::run2(0, 'no', 0, 'no')]);

        $this->assertSame(2, $calls);
        $this->assertSame(100, $r['confidence']);
        $this->assertSame(2, LlmCall::count());
        // Второй прогон взял вход из кэша — он дешевле первого
        [$first, $second] = LlmCall::orderBy('id')->get()->all();
        $this->assertSame(900, $second->tokens_cached);
        $this->assertLessThan((float) $first->cost_usd, (float) $second->cost_usd);
    }

    public function test_grader_adds_third_run_when_first_two_disagree(): void
    {
        [$r, $calls] = $this->grader([self::run2(2, 'ok', 1, 'ok'), self::run2(1, 'part', 1, 'ok'), self::run2(2, 'ok', 1, 'ok')]);

        $this->assertSame(3, $calls);
        $this->assertSame(2, $r['scores'][0][0]);   // медиана 1, 2, 2
    }

    public function test_grader_keeps_paid_runs_when_one_fails(): void
    {
        [$r, $calls] = $this->grader([self::run2(2, 'ok', 1, 'ok'), new RuntimeException('HTTP 500'), self::run2(2, 'ok', 1, 'ok')]);

        $this->assertSame(3, $calls);
        $this->assertSame(3, array_sum(array_column($r['scores'], 0)));
    }

    public function test_two_disagreeing_runs_take_the_lower_score(): void
    {
        // Третий прогон упал: остались 2 и 1 балл — берём нижнюю медиану, согласия нет
        [$r] = $this->grader([self::run2(2, 'ok', 1, 'ok'), self::run2(1, 'part', 1, 'ok'), new RuntimeException('HTTP 500')]);

        $this->assertSame(1, $r['scores'][0][0]);
        $this->assertLessThan(100, $r['confidence']);
    }

    public function test_run_marked_failed_from_outside_is_not_overwritten_by_done(): void
    {
        $material = $this->material();
        // Пока Критик работает, «второй обработчик очереди» признаёт задачу упавшей
        $this->app->bind(CriticAgent::class, fn () => new class implements CriticAgent
        {
            public function review(array $draft): array
            {
                GenerationRun::query()->update(['status' => 'failed', 'error' => 'Job has been attempted too many times']);

                return ['verdict' => 'accept', 'score' => 90, 'checks' => [], 'flag' => null];
            }
        });

        $run = $this->generate($material, 3);

        $this->assertSame('failed', $run->status);
        $this->assertSame(1, $run->written);   // после первого вопроса конвейер остановился
    }

    public function test_cost_uses_peak_and_off_peak_prices(): void
    {
        $monday9utc = new \DateTimeImmutable('2026-10-12 09:00:00', new \DateTimeZone('UTC'));
        $monday12utc = new \DateTimeImmutable('2026-10-12 12:00:00', new \DateTimeZone('UTC'));

        // 1 млн входа без кэша + 1 млн выхода на flash: 0,30 + 1,20 в пик, вдвое меньше вне пика
        $this->assertEqualsWithDelta(1.5, AgentLlm::cost('deepseek-flash', 1_000_000, 0, 1_000_000, $monday9utc), 1e-6);
        $this->assertEqualsWithDelta(0.75, AgentLlm::cost('deepseek-flash', 1_000_000, 0, 1_000_000, $monday12utc), 1e-6);
        // Вход из кэша в 50 раз дешевле
        $this->assertEqualsWithDelta(0.006, AgentLlm::cost('deepseek-flash', 1_000_000, 1_000_000, 0, $monday9utc), 1e-6);
        // Неизвестная модель — цены нет
        $this->assertSame(0.0, AgentLlm::cost('qwen-local', 1_000_000, 0, 1_000_000));
    }

    // ------------------------------------------------- Экономия токенов

    public function test_second_generation_on_the_same_material_reuses_analysis(): void
    {
        $material = $this->material();
        $this->generate($material, 1);
        $second = $this->generate($material, 1);

        $this->assertTrue($second->logs()->where('message', 'like', '%разбор взят из кэша%')->exists());
    }

    public function test_sql_questions_are_skipped_for_material_without_sql(): void
    {
        $material = $this->material();
        $r = $this->postJson('/api/generation-runs', ['material_ids' => [$material['id']], 'types' => ['sql', 'single'], 'count' => 4, 'difficulty' => 'mixed'])->assertOk()->json();
        $run = GenerationRun::findOrFail($r['id']);

        $this->assertSame(0, Question::where('generation_run_id', $run->id)->where('type', 'sql')->count());
        $this->assertTrue($run->logs()->where('message', 'like', '%SQL-вопросы не составляются%')->exists());
    }

    public function test_critic_thinks_only_on_open_and_sql_questions(): void
    {
        $client = new class extends HttpLlmClient
        {
            public array $thinking = [];

            public function __construct()
            {
                parent::__construct([]);
            }

            public function chat(array $messages, array $options = []): array
            {
                $this->thinking[] = $options['thinking'];
                $checks = array_map(fn ($c) => ['code' => $c, 'ok' => true, 'comment' => ''], ['key', 'source', 'single', 'distractors', 'clarity', 'level', 'self']);

                return ['content' => json_encode(['verdict' => 'accept', 'score' => 9, 'checks' => $checks, 'flag' => null]),
                    'model' => 'deepseek-flash', 'tokens_in' => 10, 'tokens_cached' => 0, 'tokens_out' => 10, 'tokens_reasoning' => 0, 'ms' => 1];
            }
        };
        $critic = new \App\Agents\Llm\LlmCriticAgent(new AgentLlm($client), app(\App\Services\SqlSandbox::class));
        $single = ['type' => 'single', 'text' => 'Что такое 1НФ?', 'points' => 1, 'options' => [['text' => 'атомарные значения', 'correct' => true],
            ['text' => 'нет частичных зависимостей', 'correct' => false], ['text' => 'нет транзитивных зависимостей', 'correct' => false], ['text' => 'есть внешний ключ', 'correct' => false]]];
        $open = ['type' => 'open', 'text' => 'Объясните 2НФ', 'points' => 2, 'reference' => 'Нет частичных зависимостей', 'criteria' => [['Определение', 1], ['Пример', 1]]];
        $source = ['_source' => 'В 1НФ хранятся атомарные значения. Во 2НФ нет частичных зависимостей.', '_quote' => 'атомарные значения'];
        [$single, $open] = [$single + $source, $open + $source];

        try {
            $critic->review($single);
            $critic->review($open);
        } catch (\Throwable) {
            // Проверка ответа Критика здесь не важна — только что ушло в модель
        }

        $this->assertSame(false, $client->thinking[0] ?? null);
        $this->assertSame(true, end($client->thinking));
    }

    public function test_grader_treats_self_contradiction_as_doubt(): void
    {
        // «выполнено», но 0 баллов — модель сама себе противоречит
        [$r] = $this->grader([self::run2(0, 'ok', 1, 'ok'), self::run2(0, 'ok', 1, 'ok'), self::run2(0, 'ok', 1, 'ok')]);

        $this->assertLessThan(100, $r['confidence']);
    }
}
