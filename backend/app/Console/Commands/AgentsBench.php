<?php

namespace App\Console\Commands;

use App\Agents\Llm\AgentLlm;
use App\Models\Course;
use App\Models\GenerationRun;
use App\Models\LlmCall;
use App\Models\Question;
use App\Models\User;
use App\Services\GenerationPipeline;
use Illuminate\Console\Command;

/**
 * Замер расхода конвейера генерации на одном материале: токены, кэш, время и стоимость
 * по каждому агенту, плюс получившиеся вопросы с вердиктами Критика. Настройки агентов
 * можно переопределить на один запуск, не меняя config/agents.php:
 *
 *   php artisan agents:bench 1 --count=6 --set=analyst.thinking=false --set=critic.model=deepseek-flash
 *
 * Запускайте на отдельной базе (DB_DATABASE=ptp_diploma_bench), чтобы не засорять банк.
 */
class AgentsBench extends Command
{
    protected $signature = 'agents:bench
        {material : id материала с распознанным текстом}
        {--count=6 : сколько вопросов заказать}
        {--types=single,multiple,tf,match,sql,open : типы вопросов через запятую}
        {--set=* : переопределение настройки агента, например writer.effort=low или analyst.thinking=false}
        {--label= : подпись запуска в отчёте}';

    protected $description = 'Прогнать конвейер агентов на материале и показать расход токенов и стоимость';

    public function handle(GenerationPipeline $pipeline): int
    {
        foreach ($this->option('set') as $set) {
            [$key, $value] = array_pad(explode('=', $set, 2), 2, null);
            $value = match ($value) {
                'true' => true, 'false' => false, 'null' => null,
                default => is_numeric($value) ? $value + 0 : $value,
            };
            config(['agents.agents.'.$key => $value]);
            $this->line("  {$key} = ".var_export($value, true));
        }

        $run = GenerationRun::create([
            'course_id' => Course::firstOrFail()->id,
            'user_id' => User::where('role', User::ROLE_TEACHER)->value('id'),
            'status' => 'queued',
            'params' => [
                'material_ids' => [(int) $this->argument('material')],
                'types' => explode(',', (string) $this->option('types')),
                'count' => (int) $this->option('count'),
                'difficulty' => 'mixed',
                'bench' => $this->option('label') ?: implode(' ', $this->option('set')) ?: 'базовая',
            ],
        ]);
        $this->info('Запуск #'.$run->id.' …');
        $started = microtime(true);
        try {
            $pipeline->run($run);
        } catch (\Throwable $e) {
            $this->error('Запуск упал: '.$e->getMessage());
        } finally {
            AgentLlm::context([]);
        }
        $run->refresh();

        $this->newLine();
        $this->info('Расход по агентам (запуск #'.$run->id.', '.round(microtime(true) - $started).' с, статус '.$run->status.')');
        $rows = LlmCall::where('generation_run_id', $run->id)
            ->selectRaw('agent, model, count(*) calls, sum(tokens_in) tin, sum(tokens_cached) tcached, sum(tokens_out) tout, sum(tokens_reasoning) treason, sum(cost_usd) cost, sum(ms) ms, sum(status <> \'ok\') bad')
            ->groupBy('agent', 'model')->orderByRaw("field(agent, 'analyst', 'writer', 'critic', 'catalog')")->get();
        $this->table(['агент', 'модель', 'вызовов', 'вход', 'из кэша', 'выход', 'рассужд.', '$', 'сек', 'ошибок'],
            $rows->map(fn ($r) => [$r->agent, $r->model, $r->calls, $r->tin, $r->tcached, $r->tout, $r->treason, round((float) $r->cost, 4), round($r->ms / 1000), $r->bad])->all());

        $questions = Question::where('generation_run_id', $run->id)->orderBy('id')->get();
        $cost = (float) $rows->sum('cost');
        $this->line(sprintf('Итого: %d вопросов (написано %d, отклонено %d, спорных %d), $%.4f, $%.4f на вопрос, вход из кэша %d%%',
            $questions->count(), $run->written, $run->rejected, $run->disputed, $cost, $questions->count() ? $cost / $questions->count() : 0,
            $rows->sum('tin') ? round(100 * $rows->sum('tcached') / $rows->sum('tin')) : 0));

        $this->newLine();
        $this->table(['код', 'тип', 'слож.', 'Критик', 'вопрос'], $questions->map(fn (Question $q) => [
            $q->code, $q->type, $q->difficulty, $q->flag_text ? '⚑ '.mb_substr($q->flag_text, 0, 50) : 'ок '.($q->critic['score'] ?? ''),
            mb_substr(preg_replace('/\s+/u', ' ', $q->text), 0, 90),
        ])->all());

        return self::SUCCESS;
    }
}
