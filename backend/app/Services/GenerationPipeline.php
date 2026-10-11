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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
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
            // Материалы без текста (сканы, пустые файлы) агентам нечего дать — их не считаем в план
            $materials = Material::whereIn('id', $run->params['material_ids'] ?? [])->where('has_text', true)->get();
            $courseTopics = $run->course->topics ?? [];
            $count = (int) ($run->params['count'] ?? config('agents.default_count'));
            $perMaterial = max(1, (int) ceil($count / max(1, $materials->count())));
            $run->update(['total' => $perMaterial * $materials->count()]);

            // 1. Аналитик. Сбой на одном материале не останавливает остальные
            Agent::report('analyst', 'work', 'Разбирает материалы');
            $analyses = [];
            $topicsTotal = 0;
            $concepts = 0;
            foreach ($materials as $m) {
                $this->guard($run);
                $m->update(['status' => 'processing']);
                try {
                    $analyses[$m->id] = $this->analyze($m, $courseTopics, $run);
                    $this->succeeded();
                } catch (RunCancelled $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $this->failedStep($run, 'analyst', '«'.$m->title.'» не разобран', $e);
                    continue;
                }
                $topicsTotal += count($analyses[$m->id]['topics']);
                $concepts += $analyses[$m->id]['concepts'];
                $run->log('analyst', '«'.$m->title.'»: '.$analyses[$m->id]['concepts'].' понятий, '.$analyses[$m->id]['examples'].' примеров'
                    .(! empty($analyses[$m->id]['note']) ? ' ('.$analyses[$m->id]['note'].')' : ''));
                $run->setAgentState('analyst', ['work', 'Работает', 'Разобрано материалов: '.count($analyses).' из '.$materials->count()]);
            }
            if (! $analyses) {
                throw new \RuntimeException('Аналитик не смог разобрать ни один материал'.($this->lastError ? ': '.$this->lastError : ''));
            }
            $run->log('analyst', 'выделено тем: '.$topicsTotal);
            $run->setAgentState('analyst', [count($analyses) < $materials->count() ? 'warn' : 'done', 'Готово', $topicsTotal.' '.self::plural($topicsTotal, 'тема', 'темы', 'тем').' · '.$concepts.' '.self::plural($concepts, 'понятие', 'понятия', 'понятий')]);
            Agent::report('analyst', 'done', 'Выделил '.$concepts.' ключевых понятий');

            // 2–3. Составитель и Критик
            $run->setAgentState('writer', ['work', 'Работает', 'Генерирует вопросы']);
            $run->setAgentState('critic', ['work', 'Работает', 'Проверяет вопросы']);
            $created = collect();
            foreach ($materials as $m) {
                if (! isset($analyses[$m->id])) {
                    continue;
                }
                $this->guard($run);
                try {
                    $drafts = $this->writer->generate($m, $analyses[$m->id], $this->paramsFor($m, $run), $perMaterial);
                    $this->succeeded();
                } catch (RunCancelled $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $this->failedStep($run, 'writer', 'вопросы по «'.$m->title.'» не составлены', $e);
                    continue;
                }
                $run->log('writer', '«'.$m->title.'» → '.count($drafts).' из '.$perMaterial.' '.self::plural($perMaterial, 'вопроса', 'вопросов', 'вопросов')
                    .(count($drafts) ? '' : ': в материале не нашлось понятий для вопросов'));
                foreach ($drafts as $draft) {
                    $this->guard($run);
                    $run->increment('written');
                    $verdict = null;
                    $failure = null;
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
                        $this->succeeded();
                    } catch (RunCancelled $e) {
                        throw $e;
                    } catch (Throwable $e) {
                        // Сбой модели — не «плохой вопрос»: черновик уже оплачен, сохраняем его спорным,
                        // решает преподаватель. Если упала доработка, остаётся последний вердикт Критика
                        $failure = $e;
                        $verdict = $verdict
                            ? ['verdict' => 'dispute'] + $verdict
                            : ['verdict' => 'dispute', 'score' => null, 'checks' => [],
                                'flag' => ['text' => 'Критик не смог проверить вопрос — проверьте сами', 'level' => 'amber']];
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
                    // Подряд идущие сбои (нет баланса, сеть) останавливают запуск — но уже после сохранения черновика
                    if ($failure) {
                        $this->failedStep($run, 'critic', $q->code.' не проверен, сохранён для проверки преподавателем', $failure);
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
            $notChecked = 0;
            foreach ($created as $q) {
                try {
                    $info = $this->catalog->catalog($q, $bank);
                } catch (Throwable $e) {
                    report($e);
                    $notChecked++;
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
            $run->log('catalog', $notChecked ? 'не сверено с банком: '.$notChecked : 'проставлены теги и сложность');
            $run->update(['catalog_progress' => 100]);
            $run->setAgentState('catalog', [$notChecked ? 'warn' : 'done', 'Готово', 'Теги проставлены · похожих: '.$similar.($notChecked ? ' · не сверено: '.$notChecked : '')]);

            self::releaseMaterials($run);
            if ($this->errors) {
                $run->log('analyst', 'запуск завершён с ошибками модели: '.$this->errors.' — подробности выше');
            }
            // Только если запуск всё ещё наш: failed, записанный задачей очереди, не перезаписываем
            GenerationRun::whereKey($run->id)->where('status', 'running')->update(['status' => 'done', 'finished_at' => now()]);
        } catch (RunAborted) {
            // Статус и причину уже записал markFailed
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
            self::releaseMaterials($run);
        } catch (Throwable $e) {
            self::markFailed($run, $e->getMessage());
            throw $e;
        } finally {
            // Обработчик очереди живёт долго: следующая задача не должна писать вызовы в этот запуск
            AgentLlm::context([]);
        }
    }

    /**
     * Запуск упал (исключение в конвейере или задача очереди убита по таймауту): статус, текст ошибки,
     * агенты не «в работе», материалы не «в обработке». Уже сохранённые вопросы остаются в банке.
     */
    public static function markFailed(GenerationRun $run, string $error): void
    {
        $run->refresh();
        if (in_array($run->status, ['done', 'cancelled', 'failed'], true)) {
            return;
        }
        $states = $run->agent_states ?? [];
        foreach ($states as $agent => $state) {
            if ($state[0] !== 'done') {
                $states[$agent] = ['error', 'Ошибка', mb_substr($error, 0, 120)];
            }
        }
        $run->update(['status' => 'failed', 'error' => mb_substr($error, 0, 1000), 'finished_at' => now(), 'agent_states' => $states]);
        $run->log('analyst', 'ошибка конвейера: '.mb_substr($error, 0, 200));
        self::releaseMaterials($run);
    }

    private static function releaseMaterials(GenerationRun $run): void
    {
        foreach (Material::whereIn('id', $run->params['material_ids'] ?? [])->where('status', 'processing')->get() as $m) {
            $m->update(['status' => $m->questions()->where('status', 'pending')->exists() ? 'review' : 'ready']);
        }
    }

    /**
     * Разбор материала Аналитиком с кэшем: результат зависит только от текста, тем курса и настроек
     * Аналитика, поэтому повторный запуск по той же лекции не тратит токены на её разбор заново.
     * Ключ меняется при любой правке текста, промпта, модели или нарезки — тогда разбор делается снова.
     */
    private function analyze(Material $m, array $topics, GenerationRun $run): array
    {
        $cfg = config('agents.agents.analyst');
        $key = 'analysis:'.sha1(json_encode([
            config('agents.driver'), sha1((string) $m->extracted_text), $topics,
            $cfg['model'] ?? null, $cfg['prompt'] ?? null, $cfg['thinking'] ?? null, $cfg['effort'] ?? null,
            config('agents.chunk_chars'), config('agents.max_chunks'),
        ]));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            $run->log('analyst', '«'.$m->title.'»: разбор взят из кэша — токены на Аналитика не тратятся');

            return $cached;
        }
        $analysis = $this->analyst->analyze($m, $topics);
        Cache::put($key, $analysis, now()->addDays((int) config('agents.analysis_cache_days', 30)));

        return $analysis;
    }

    /**
     * Параметры Составителя для материала. SQL-задания проверяются на учебной базе (groups, students):
     * если в лекции нет SQL-запросов, такой вопрос получается не по теме и Критик его отклоняет —
     * токены впустую. Тогда места SQL-вопросов достаются остальным выбранным типам.
     */
    private function paramsFor(Material $m, GenerationRun $run): array
    {
        $params = $run->params;
        $types = $params['types'] ?? [];
        if (in_array('sql', $types, true) && count($types) > 1 && ! preg_match('/\bSELECT\b/i', (string) $m->extracted_text)) {
            $params['types'] = array_values(array_diff($types, ['sql']));
            $run->log('writer', '«'.$m->title.'»: SQL-вопросы не составляются — в материале нет SQL-запросов, их места отданы другим типам');
        }

        return $params;
    }

    /** Сколько шагов модели подряд упало: при недоступном API нет смысла тратить запуск дальше. */
    private const MAX_FAILURES_IN_A_ROW = 3;

    private int $errors = 0;

    private int $inARow = 0;

    private ?string $lastError = null;

    private function succeeded(): void
    {
        $this->inARow = 0;
    }

    private function failedStep(GenerationRun $run, string $agent, string $what, Throwable $e): void
    {
        report($e);
        $this->errors++;
        // Неверный JSON — сбой качества одного ответа; подряд считаем только недоступность модели
        if (! $e instanceof \App\Agents\Llm\LlmInvalidAnswer) {
            $this->inARow++;
        }
        $this->lastError = mb_substr($e->getMessage(), 0, 200);
        $run->log($agent, $what.': '.$this->lastError);
        if ($this->inARow >= self::MAX_FAILURES_IN_A_ROW) {
            throw new \RuntimeException('Нейросеть недоступна ('.self::MAX_FAILURES_IN_A_ROW.' ошибки подряд): '.$this->lastError, 0, $e);
        }
    }

    /** Проверка между шагами: не нажал ли преподаватель «Остановить». */
    private function guard(GenerationRun $run): void
    {
        $status = GenerationRun::whereKey($run->id)->value('status');
        if ($status === 'cancelling') {
            throw new RunCancelled();
        }
        // Задачу уже признали упавшей (например, второй обработчик очереди по таймауту),
        // а этот процесс ещё работает — дальше не тратим токены и не перезаписываем статус
        if ($status === 'failed') {
            throw new RunAborted();
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
        // Код берётся как «наибольший + 1»: если его одновременно занял преподаватель
        // или другой запуск, берём следующий
        for ($try = 1; ; $try++) {
            try {
                $q->save();

                return $q;
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= 5) {
                    throw $e;
                }
                $q->code = Question::nextCode();
            }
        }
    }
}
