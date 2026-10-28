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
 * Запуск идёт частями (step): прогресс пишется в generation_runs и agent_logs, экран «Работа агентов» читает его опросом.
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

    /** Весь запуск за один вызов — для синхронной очереди и agents:bench. */
    public function run(GenerationRun $run): void
    {
        while ($this->step($run)) {
            // следующая часть
        }
    }

    /**
     * Одна часть запуска: разбор одного материала, одна порция вопросов (Составитель → Критик →
     * Каталогизатор) или завершение. Части короткие — несколько минут, — а прогресс между ними
     * хранится в $run->state, поэтому большой запуск не упирается в предел времени задачи очереди:
     * его продолжают следующие задачи, а уже готовые вопросы сразу размечены Каталогизатором.
     *
     * @return bool осталась ли работа на следующую часть
     */
    public function step(GenerationRun $run): bool
    {
        $run->refresh();
        if (in_array($run->status, ['done', 'cancelled', 'failed'], true)) {
            return false;
        }
        // Остановили, пока запуск ждал в очереди
        if ($run->status === 'cancelling' && $run->state === null) {
            $run->update(['status' => 'cancelled', 'finished_at' => now()]);

            return false;
        }
        AgentLlm::context(['generation_run_id' => $run->id]);

        try {
            if ($run->state === null) {
                $this->start($run);
            }
            $this->errors = (int) ($run->state['errors'] ?? 0);
            $this->inARow = (int) ($run->state['in_a_row'] ?? 0);
            $this->lastError = $run->state['last_error'] ?? null;
            $this->guard($run);

            return match ($run->state['phase']) {
                'analyze' => $this->analyzeNext($run),
                'write' => $this->writeNext($run),
                default => $this->finish($run),
            };
        } catch (RunAborted) {
            // Статус и причину уже записал markFailed
            return false;
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

            return false;
        } catch (Throwable $e) {
            self::markFailed($run, $e->getMessage());
            throw $e;
        } finally {
            // Обработчик очереди живёт долго: следующая задача не должна писать вызовы в этот запуск
            AgentLlm::context([]);
        }
    }

    /** Начало запуска: план по материалам и пустой прогресс. */
    private function start(GenerationRun $run): void
    {
        $run->update(['status' => 'running', 'started_at' => now()]);
        $this->initStates($run);
        // Материалы без текста (сканы, пустые файлы) агентам нечего дать — их не считаем в план
        $materials = Material::whereIn('id', $run->params['material_ids'] ?? [])->where('has_text', true)->orderBy('id')->pluck('id')->all();
        $count = (int) ($run->params['count'] ?? config('agents.default_count'));
        $perMaterial = max(1, (int) ceil($count / max(1, count($materials))));
        $run->update(['total' => $perMaterial * count($materials)]);
        Agent::report('analyst', 'work', 'Разбирает материалы');

        $this->put($run, [
            'phase' => 'analyze',
            'materials' => $materials,
            'per_material' => $perMaterial,
            'next' => 0,             // какой материал разбирать следующим
            'analyses' => [],        // id материала → разбор Аналитика
            'topics' => 0,
            'concepts' => 0,
            'material' => 0,         // какой материал сейчас у Составителя
            'plan' => null,          // порции Составителя по этому материалу
            'params' => null,        // параметры Составителя по этому материалу
            'portion' => 0,
            'drafts' => 0,           // сколько черновиков дал этот материал
            'catalogued' => 0,
            'similar' => 0,
            'not_checked' => 0,
        ]);
    }

    /** 1. Аналитик: один материал за часть. Сбой на одном материале не останавливает остальные. */
    private function analyzeNext(GenerationRun $run): bool
    {
        $s = $run->state;
        $total = count($s['materials']);
        if ($m = Material::find($s['materials'][$s['next']] ?? 0)) {
            $m->update(['status' => 'processing']);
            try {
                $analysis = $this->analyze($m, $run->course->topics ?? [], $run);
                $this->succeeded();
                $s['analyses'][$m->id] = $analysis;
                $s['topics'] += count($analysis['topics']);
                $s['concepts'] += $analysis['concepts'];
                $run->log('analyst', '«'.$m->title.'»: '.$analysis['concepts'].' понятий, '.$analysis['examples'].' примеров'
                    .(! empty($analysis['note']) ? ' ('.$analysis['note'].')' : ''));
                $run->setAgentState('analyst', ['work', 'Работает', 'Разобрано материалов: '.count($s['analyses']).' из '.$total]);
            } catch (RunCancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->failedStep($run, 'analyst', '«'.$m->title.'» не разобран', $e);
            }
        }
        $s['next']++;
        if ($s['next'] < $total) {
            $this->put($run, $s);

            return true;
        }

        if (! $s['analyses']) {
            throw new \RuntimeException('Аналитик не смог разобрать ни один материал'.($this->lastError ? ': '.$this->lastError : ''));
        }
        $run->log('analyst', 'выделено тем: '.$s['topics']);
        $run->setAgentState('analyst', [count($s['analyses']) < $total ? 'warn' : 'done', 'Готово', $s['topics'].' '.self::plural($s['topics'], 'тема', 'темы', 'тем').' · '.$s['concepts'].' '.self::plural($s['concepts'], 'понятие', 'понятия', 'понятий')]);
        Agent::report('analyst', 'done', 'Выделил '.$s['concepts'].' ключевых понятий');
        $run->setAgentState('writer', ['work', 'Работает', 'Генерирует вопросы']);
        $run->setAgentState('critic', ['work', 'Работает', 'Проверяет вопросы']);
        $run->setAgentState('catalog', ['work', 'Работает', 'Сверяет новые вопросы с банком']);
        $this->put($run, ['phase' => 'write'] + $s);

        return true;
    }

    /**
     * 2–4. Одна порция материала: Составитель пишет черновики, Критик проверяет (с доработкой),
     * Каталогизатор сразу проставляет теги и сверяет с банком — оборванный запуск не оставит неразмеченных вопросов.
     */
    private function writeNext(GenerationRun $run): bool
    {
        $s = $run->state;
        if (! $this->toMaterial($s)) {
            $this->put($run, ['phase' => 'finish'] + $s);

            return true;
        }
        $m = Material::findOrFail($s['materials'][$s['material']]);
        $analysis = $s['analyses'][$m->id];

        if ($s['plan'] === null) {
            $s['params'] = $this->paramsFor($m, $run);
            $s['plan'] = $this->writer->plan($analysis, $s['params'], $s['per_material']);
            $s['portion'] = 0;
            $s['drafts'] = 0;
        }

        if ($s['plan']) {
            $drafts = [];
            try {
                $drafts = $this->writer->write($m, $analysis, $s['params'], $s['plan'][$s['portion']]);
                $this->succeeded();
            } catch (RunCancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->failedStep($run, 'writer', 'вопросы по «'.$m->title.'» не составлены'
                    .(count($s['plan']) > 1 ? ' (порция '.($s['portion'] + 1).' из '.count($s['plan']).')' : ''), $e);
            }
            $s['drafts'] += count($drafts);
            $created = $this->review($run, $m, $drafts);
            $this->catalogue($run, $created, $s);
            $s['portion']++;
        }

        if ($s['portion'] >= count($s['plan'])) {
            $run->log('writer', '«'.$m->title.'» → '.$s['drafts'].' из '.$s['per_material'].' '.self::plural($s['per_material'], 'вопроса', 'вопросов', 'вопросов')
                .($s['drafts'] ? '' : ': в материале не нашлось понятий для вопросов'));
            $s['material']++;
            $s['plan'] = null;
            $s['params'] = null;
            // Материалы кончились — следующая часть сразу подводит итоги
            if (! $this->toMaterial($s)) {
                $s['phase'] = 'finish';
            }
        }
        $this->put($run, $s);

        return true;
    }

    /** Курсор Составителя — на следующий материал, который есть что писать: неразобранный или удалённый по ходу запуска пропускаем. */
    private function toMaterial(array &$s): bool
    {
        $ids = $s['materials'];
        while ($s['material'] < count($ids) && (! isset($s['analyses'][$ids[$s['material']]]) || ! Material::whereKey($ids[$s['material']])->exists())) {
            $s['material']++;
        }

        return $s['material'] < count($ids);
    }

    /**
     * Критик по черновикам порции, с доработкой по замечаниям.
     *
     * @return Question[] сохранённые вопросы (принятые и спорные)
     */
    private function review(GenerationRun $run, Material $m, array $drafts): array
    {
        $created = [];
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
            $created[] = $q;
            if ($verdict['verdict'] === 'dispute') {
                $run->log('critic', $q->code.' спорный → к преподавателю');
            }
            // Подряд идущие сбои (нет баланса, сеть) останавливают запуск — но уже после сохранения черновика
            if ($failure) {
                $this->failedStep($run, 'critic', $q->code.' не проверен, сохранён для проверки преподавателем', $failure);
            }
        }
        $run->setAgentState('writer', ['work', 'Работает', 'Составлено вопросов: '.$run->written.' из '.$run->total]);

        return $created;
    }

    /**
     * Каталогизатор по вопросам порции: теги, сложность, похожие в банке. Сверяем с банком,
     * а не друг с другом: черновики одного запуска похожи по построению.
     *
     * @param  Question[]  $created
     */
    private function catalogue(GenerationRun $run, array $created, array &$s): void
    {
        if (! $created) {
            return;
        }
        $bank = Question::where('course_id', $run->course_id)
            ->where(fn ($q) => $q->whereNull('generation_run_id')->orWhere('generation_run_id', '!=', $run->id))
            ->get();
        foreach ($created as $q) {
            $s['catalogued']++;
            try {
                $info = $this->catalog->catalog($q, $bank);
            } catch (Throwable $e) {
                report($e);
                $s['not_checked']++;
                $run->log('catalog', $q->code.': не удалось сверить с банком');
                continue;
            }
            $q->tags = $info['tags'];
            $q->difficulty = $info['difficulty'];
            if (! empty($info['similar'])) {
                $s['similar']++;
                $q->flag_text = 'Похож на '.$info['similar']['code'];
                $q->flag_level = 'red';
                $q->addHistory('Каталогизатор нашёл похожий '.$info['similar']['code']);
                $run->log('catalog', $q->code.' похож на '.$info['similar']['code'].' ('.$info['similar']['percent'].'%)');
            }
            $q->save();
        }
        $run->catalog_progress = $run->total ? min(99, (int) round($s['catalogued'] / $run->total * 100)) : 0;
        $run->setAgentState('catalog', ['work', 'Работает', 'Сверено с банком: '.$s['catalogued'].' · похожих: '.$s['similar']]);
    }

    /** Все порции готовы: итоги агентов, материалы — из «в обработке», запуск — готов. */
    private function finish(GenerationRun $run): bool
    {
        $s = $run->state;
        $run->setAgentState('writer', ['done', 'Готово', $run->written.' вопросов']);
        $run->setAgentState('critic', ['done', 'Готово', $run->checked.' проверено · '.$run->rejected.' отклонено · '.$run->disputed.' спорных']);
        Agent::report('writer', 'done', 'Сгенерировал '.$run->written.' вопросов');
        Agent::report('critic', $run->disputed ? 'warn' : 'done', 'Отклонил '.$run->rejected.', пометил спорные: '.$run->disputed);

        $run->log('catalog', $s['not_checked'] ? 'не сверено с банком: '.$s['not_checked'] : 'проставлены теги и сложность');
        $run->catalog_progress = 100;
        $run->setAgentState('catalog', [$s['not_checked'] ? 'warn' : 'done', 'Готово', 'Теги проставлены · похожих: '.$s['similar'].($s['not_checked'] ? ' · не сверено: '.$s['not_checked'] : '')]);

        self::releaseMaterials($run);
        if ($this->errors) {
            $run->log('analyst', 'запуск завершён с ошибками модели: '.$this->errors.' — подробности выше');
        }
        // Только если запуск всё ещё наш: failed, записанный задачей очереди, не перезаписываем
        GenerationRun::whereKey($run->id)->where('status', 'running')->update(['status' => 'done', 'finished_at' => now()]);

        return false;
    }

    /** Сохранить прогресс запуска вместе со счётчиками сбоев модели — их читает следующая часть. */
    private function put(GenerationRun $run, array $state): void
    {
        $run->state = array_replace($run->state ?? [], $state,
            ['errors' => $this->errors, 'in_a_row' => $this->inARow, 'last_error' => $this->lastError]);
        $run->save();
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
            'user_id' => $run->user_id,
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
