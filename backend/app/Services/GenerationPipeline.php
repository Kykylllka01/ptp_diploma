<?php

namespace App\Services;

use App\Agents\Contracts\AnalystAgent;
use App\Agents\Contracts\CatalogAgent;
use App\Agents\Contracts\CriticAgent;
use App\Agents\Contracts\WriterAgent;
use App\Agents\Llm\AgentLlm;
use App\Models\Agent;
use App\Models\GenerationRun;
use App\Models\Material;
use App\Models\Question;
use Throwable;

/**
 * Конвейер генерации вопросов: Аналитик → Составитель → Критик → Каталогизатор.
 * Прогресс пишется в generation_runs и agent_logs, экран «Работа агентов» читает его опросом.
 */
class GenerationPipeline
{
    public function __construct(
        private AnalystAgent $analyst,
        private WriterAgent $writer,
        private CriticAgent $critic,
        private CatalogAgent $catalog,
    ) {
    }

    public function run(GenerationRun $run): void
    {
        if ($run->fresh()->status === 'cancelling') {
            $run->update(['status' => 'cancelled', 'finished_at' => now()]);

            return;
        }
        $run->update(['status' => 'running', 'started_at' => now()]);
        $this->initStates($run);
        AgentLlm::context(['generation_run_id' => $run->id]);

        try {
            $materials = Material::whereIn('id', $run->params['material_ids'] ?? [])->get();
            $courseTopics = $run->course->topics ?? [];
            $count = (int) ($run->params['count'] ?? config('agents.default_count'));
            $perMaterial = max(1, (int) ceil($count / max(1, $materials->count())));
            $run->update(['total' => $perMaterial * $materials->count()]);

            // 1. Аналитик
            Agent::report('analyst', 'work', 'Разбирает материалы');
            $analyses = [];
            $topicsTotal = 0;
            $concepts = 0;
            foreach ($materials as $m) {
                $this->guard($run);
                $m->update(['status' => 'processing']);
                $analyses[$m->id] = $this->analyst->analyze($m, $courseTopics);
                $topicsTotal += count($analyses[$m->id]['topics']);
                $concepts += $analyses[$m->id]['concepts'];
                $run->log('analyst', '«'.$m->title.'»: '.$analyses[$m->id]['concepts'].' понятий, '.$analyses[$m->id]['examples'].' примеров');
                $run->setAgentState('analyst', ['work', 'Работает', 'Разобрано материалов: '.count($analyses).' из '.$materials->count()]);
            }
            $run->log('analyst', 'выделено тем: '.$topicsTotal);
            $run->setAgentState('analyst', ['done', 'Готово', $topicsTotal.' '.self::plural($topicsTotal, 'тема', 'темы', 'тем').' · '.$concepts.' '.self::plural($concepts, 'понятие', 'понятия', 'понятий')]);
            Agent::report('analyst', 'done', 'Выделил '.$concepts.' ключевых понятий');

            // 2–3. Составитель и Критик
            $run->setAgentState('writer', ['work', 'Работает', 'Генерирует вопросы']);
            $run->setAgentState('critic', ['work', 'Работает', 'Проверяет вопросы']);
            $created = collect();
            foreach ($materials as $m) {
                $this->guard($run);
                $drafts = $this->writer->generate($m, $analyses[$m->id], $run->params, $perMaterial);
                $run->log('writer', '«'.$m->title.'» → '.count($drafts).' '.self::plural(count($drafts), 'вопрос', 'вопроса', 'вопросов').(count($drafts) ? '' : ': в материале не нашлось понятий для вопросов'));
                foreach ($drafts as $draft) {
                    $this->guard($run);
                    $run->increment('written');
                    try {
                        $verdict = $this->critic->review($draft);

                        // Доработка по замечаниям: ошибка по существу или не пройдена формальная проверка.
                        // Не больше config('agents.rewrite_rounds') кругов, дальше решает преподаватель
                        for ($round = 0; $round < (int) config('agents.rewrite_rounds', 1)
                            && $verdict['verdict'] === 'dispute'
                            && (($verdict['flag']['level'] ?? '') === 'red' || ! empty($verdict['formal'])); $round++) {
                            $run->log('critic', mb_substr($draft['text'], 0, 60).'… → на доработку: '.mb_strtolower($verdict['flag']['text'] ?? 'замечания'));
                            $draft = $this->writer->rewrite($draft, $verdict['checks']);
                            $verdict = $this->critic->review($draft);
                        }
                    } catch (Throwable $e) {
                        // Сбой на одном вопросе не останавливает запуск
                        report($e);
                        $run->increment('checked');
                        $run->increment('rejected');
                        $run->log('critic', 'не удалось проверить вопрос: '.mb_substr($e->getMessage(), 0, 150));
                        continue;
                    }
                    $run->increment('checked');
                    $run->setAgentState('critic', ['work', 'Работает', 'Проверено '.$run->checked.' из '.$run->written]);

                    if ($verdict['verdict'] === 'reject') {
                        $run->increment('rejected');
                        $run->log('critic', 'вопрос отклонён: '.mb_substr($draft['text'], 0, 60).'…');
                        continue;
                    }
                    if ($verdict['verdict'] === 'dispute') {
                        $run->increment('disputed');
                    }

                    $q = $this->store($run, $m, $draft, $verdict);
                    $created->push($q);
                    if ($verdict['verdict'] === 'dispute') {
                        $run->log('critic', $q->code.' спорный → к преподавателю');
                    }
                }
            }
            $run->refresh();
            $run->setAgentState('writer', ['done', 'Готово', $run->written.' вопросов']);
            $run->setAgentState('critic', ['done', 'Готово', $run->checked.' проверено · '.$run->rejected.' отклонено · '.$run->disputed.' спорных']);
            Agent::report('writer', 'done', 'Сгенерировал '.$run->written.' вопросов');
            Agent::report('critic', $run->disputed ? 'warn' : 'done', 'Отклонил '.$run->rejected.', пометил спорные: '.$run->disputed);

            $this->guard($run);

            // 4. Каталогизатор
            $run->setAgentState('catalog', ['work', 'Работает', 'Сверяет с банком…']);
            // Сверяем с банком, а не друг с другом: черновики одного запуска похожи по построению
            $bank = Question::where('course_id', $run->course_id)
                ->where(fn ($q) => $q->whereNull('generation_run_id')->orWhere('generation_run_id', '!=', $run->id))
                ->get();
            $similar = 0;
            foreach ($created as $q) {
                try {
                    $info = $this->catalog->catalog($q, $bank);
                } catch (Throwable $e) {
                    report($e);
                    $run->log('catalog', $q->code.': не удалось сверить с банком');
                    continue;
                }
                $q->tags = $info['tags'];
                $q->difficulty = $info['difficulty'];
                if (! empty($info['similar'])) {
                    $similar++;
                    $q->flag_text = 'Похож на '.$info['similar']['code'];
                    $q->flag_level = 'red';
                    $q->addHistory('Каталогизатор нашёл похожий '.$info['similar']['code']);
                    $run->log('catalog', $q->code.' похож на '.$info['similar']['code'].' ('.$info['similar']['percent'].'%)');
                }
                $q->save();
            }
            $run->log('catalog', 'проставлены теги и сложность');
            $run->update(['catalog_progress' => 100]);
            $run->setAgentState('catalog', ['done', 'Готово', 'Теги проставлены · похожих: '.$similar]);

            foreach ($materials as $m) {
                $m->update(['status' => $m->questions()->where('status', 'pending')->exists() ? 'review' : 'ready']);
            }
            $run->update(['status' => 'done', 'finished_at' => now()]);
        } catch (RunCancelled) {
            // Остановлено преподавателем: уже сохранённые вопросы остаются в банке
            $states = $run->fresh()->agent_states ?? [];
            foreach ($states as $agent => $state) {
                if ($state[0] !== 'done') {
                    $states[$agent] = ['wait', 'Остановлен', 'Запуск остановлен преподавателем'];
                }
            }
            $run->update(['status' => 'cancelled', 'finished_at' => now(), 'agent_states' => $states]);
            $run->log('analyst', 'запуск остановлен преподавателем');
            foreach (Material::whereIn('id', $run->params['material_ids'] ?? [])->get() as $m) {
                $m->update(['status' => $m->questions()->where('status', 'pending')->exists() ? 'review' : 'ready']);
            }
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
            $run->log('analyst', 'ошибка конвейера: '.mb_substr($e->getMessage(), 0, 200));
            throw $e;
        }
    }

    /** Проверка между шагами: не нажал ли преподаватель «Остановить». */
    private function guard(GenerationRun $run): void
    {
        if (GenerationRun::whereKey($run->id)->value('status') === 'cancelling') {
            throw new RunCancelled();
        }
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;

        return $n > 10 && $n < 20 ? $many : ($n1 > 1 && $n1 < 5 ? $few : ($n1 === 1 ? $one : $many));
    }

    private function initStates(GenerationRun $run): void
    {
        $run->agent_states = [
            'analyst' => ['work', 'Работает', 'Читает материалы…'],
            'writer' => ['wait', 'Ожидает', 'Начнёт после аналитика'],
            'critic' => ['wait', 'Ожидает', 'Начнёт после первых вопросов'],
            'catalog' => ['wait', 'Ожидает', 'Начнёт после критика'],
        ];
        $run->save();
    }

    private function store(GenerationRun $run, Material $m, array $draft, array $verdict): Question
    {
        $q = new Question(array_merge($draft, [
            'code' => Question::nextCode(),
            'course_id' => $run->course_id,
            'material_id' => $m->id,
            'generation_run_id' => $run->id,
            'status' => 'pending',
            'critic' => ['score' => $verdict['score'], 'checks' => $verdict['checks']] + (isset($verdict['fix']) ? ['fix' => $verdict['fix']] : []),
            'flag_text' => $verdict['flag']['text'] ?? null,
            'flag_level' => $verdict['flag']['level'] ?? null,
        ]));
        $q->addHistory('Составитель создал вопрос');
        if ($verdict['verdict'] === 'dispute') {
            $q->addHistory('Критик пометил: '.mb_strtolower($verdict['flag']['text']));
        }
        $q->save();

        return $q;
    }
}
