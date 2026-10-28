<?php

namespace App\Agents\Llm;

use App\Agents\Contracts\WriterAgent;
use App\Models\Material;
use App\Models\Question;
use App\Services\SqlSandbox;

/**
 * Составитель: распределяет нужное число вопросов по разобранным фрагментам
 * (тип и сложность задаёт код), по каждому фрагменту — один запрос к модели
 * с понятиями, источником и уже существующими вопросами темы.
 */
class LlmWriterAgent implements WriterAgent
{
    /** Сколько вопросов просить за один запрос. */
    private const PER_CALL = 6;

    private const LEVEL_BLOOM = [
        'easy' => ['remember', 'understand'],
        'medium' => ['understand', 'apply', 'analyze'],
        'hard' => ['apply', 'evaluate', 'create'],
    ];

    private const DIFFICULTY_MIX = [
        'mixed' => ['easy', 'medium', 'medium', 'hard'],
        'easy' => ['easy', 'easy', 'medium'],
        'medium' => ['medium'],
        'hard' => ['medium', 'hard', 'hard'],
    ];

    public function __construct(private AgentLlm $llm, private SqlSandbox $sandbox)
    {
    }

    /** План: тип и сложность каждого вопроса, распределение по фрагментам по кругу, порции до PER_CALL вопросов фрагмента. */
    public function plan(array $analysis, array $params, int $count): array
    {
        $chunks = self::chunks($analysis);
        if (! $chunks || $count <= 0) {
            return [];
        }

        $types = $params['types'] ?: ['single'];
        $mix = self::DIFFICULTY_MIX[$params['difficulty'] ?? 'mixed'] ?? self::DIFFICULTY_MIX['mixed'];
        $slots = [];
        for ($i = 0; $i < $count; $i++) {
            $type = $types[$i % count($types)];
            $difficulty = $this->fit($type, $mix[$i % count($mix)]);
            $slots[$i % count($chunks)][] = ['type' => $type, 'difficulty' => $difficulty, 'bloom' => $this->bloom($difficulty, $params['bloom'] ?? null)];
        }

        $portions = [];
        foreach ($slots as $ci => $chunkSlots) {
            foreach (array_chunk($chunkSlots, self::PER_CALL) as $batch) {
                $portions[] = ['chunk' => $ci, 'slots' => $batch];
            }
        }

        return $portions;
    }

    public function write(Material $material, array $analysis, array $params, array $portion): array
    {
        $chunk = self::chunks($analysis)[$portion['chunk'] ?? -1] ?? null;
        $batch = $portion['slots'] ?? [];
        if (! $chunk || ! $batch) {
            return [];
        }
        $types = $params['types'] ?: ['single'];
        $course = $material->course->title ?? 'Базы данных';
        $ctx = [
            'topic' => $chunk['topic'] === 'другое' ? $material->title : $chunk['topic'],
            'source' => $material->title.(str_starts_with($chunk['place'], 'фрагмент') ? '' : ', '.$chunk['place']),
            'source_text' => $chunk['text'],
        ];
        // Порядок под кэш DeepSeek: сначала то, что общее у всех порций фрагмента (источник,
        // понятия), затем изменчивое (задание порции, банк — он растёт по ходу запуска)
        $user = '<источник место="'.$ctx['source'].'">'."\n".$chunk['text']."\n".'</источник>'
            ."\n\n".$this->concepts($chunk)
            ."\n\n".$this->task($batch, $params)
            ."\n\n".$this->bank($material->course_id, $ctx['topic'])
            .$this->notes($params);

        $json = $this->llm->json('writer', $user, ['course' => $course], fn ($j) => is_array($j['questions'] ?? null) ? null : 'нужно поле questions (массив)');

        $drafts = [];
        foreach (array_slice($json['questions'], 0, count($batch)) as $k => $q) {
            if (! in_array($q['type'] ?? '', $types, true)) {
                continue;
            }
            $slot = $batch[$k] ?? $batch[0];
            if ($draft = QuestionDraft::fromModel($q, $ctx + ['difficulty' => $slot['difficulty'], 'bloom' => $slot['bloom'][0]])) {
                $drafts[] = $draft;
            }
        }

        return $drafts;
    }

    /** Фрагменты, по которым есть что спрашивать: план и порции отбирают их одинаково. */
    private static function chunks(array $analysis): array
    {
        return array_values(array_filter($analysis['chunks'] ?? [], fn ($c) => ! empty($c['concepts'])));
    }

    public function rewrite(array $draft, array $criticChecks): array
    {
        $remarks = array_map(fn ($c) => '- '.$c['text'], array_filter($criticChecks, fn ($c) => empty($c['ok'])));
        if (! $remarks) {
            $remarks = ['- Улучши формулировку и варианты ответа'];
        }
        $source = QuestionDraft::sourceText($draft);
        $course = optional(Material::find($draft['material_id'] ?? null))->course->title ?? 'Базы данных';

        // Источник первым: он общий с запросами генерации и Критика по этому фрагменту — вход из кэша
        $user = '<источник>'."\n".$source."\n".'</источник>'."\n\n"
            .'<задание>Доработка одного вопроса. Язык: тот же, что в вопросе.'
            .($draft['type'] === 'sql' ? "\n".'Схема учебной базы (SQLite): '.$this->sandbox->schema() : '')
            .'</задание>'."\n\n"
            .'<вопрос>'."\n".json_encode(QuestionDraft::toModel($draft), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n".'</вопрос>'."\n\n"
            .'<замечания_критика>'."\n".implode("\n", $remarks)."\n".'</замечания_критика>'."\n\n"
            .'Исправь вопрос так, чтобы устранить замечания. Не меняй проверяемое понятие и тип вопроса. '
            .'Верни JSON с полем questions, в котором ровно один вопрос в том же формате.';

        $json = $this->llm->json('writer', $user, ['course' => $course], function ($j) use ($draft) {
            $q = $j['questions'][0] ?? null;

            return is_array($q) && ($q['type'] ?? $draft['type']) === $draft['type'] ? null : 'нужно поле questions с одним вопросом того же типа ('.$draft['type'].')';
        });

        $new = QuestionDraft::fromModel($json['questions'][0] + ['type' => $draft['type']], [
            'topic' => $draft['topic'] ?? null,
            'source' => $draft['source'] ?? null,
            'source_text' => $source,
            'difficulty' => $draft['difficulty'] ?? 'medium',
            'bloom' => $draft['_bloom'] ?? 'understand',
        ]);

        // Если доработка не разобралась — оставляем как было, решит преподаватель
        return $new ? array_merge($draft, $new) : $draft;
    }

    private function task(array $batch, array $params): string
    {
        $lines = [];
        foreach ($batch as $i => $s) {
            $lines[] = ($i + 1).'. type='.$s['type'].', difficulty='.$s['difficulty'].', bloom: '.implode(' или ', $s['bloom']);
        }
        $lang = ($params['lang'] ?? 'ru') === 'en' ? 'английский' : 'русский';
        $sql = in_array('sql', array_column($batch, 'type'), true)
            ? "\n".'Схема учебной базы для sql (SQLite): '.$this->sandbox->schema()
            : '';

        return '<задание>'."\n".'Составь '.count($batch).' вопр. в таком порядке:'."\n".implode("\n", $lines)
            ."\n".'Язык вопросов: '.$lang.'.'.$sql."\n".'</задание>';
    }

    private function concepts(array $chunk): string
    {
        $lines = [];
        foreach ($chunk['concepts'] as $c) {
            $lines[] = '- '.$c['name'].' ('.$c['bloom'].'): '.($c['definition'] ?? '').' Цитата: «'.$c['quote'].'»';
        }
        foreach ($chunk['examples'] ?? [] as $e) {
            $lines[] = '- Пример: '.($e['description'] ?? '').' Цитата: «'.($e['quote'] ?? '').'»';
        }

        return '<понятия>'."\n".implode("\n", $lines)."\n".'</понятия>';
    }

    private function bank(int $courseId, string $topic): string
    {
        $texts = Question::where('course_id', $courseId)->where('topic', $topic)->where('status', '!=', 'rejected')
            ->latest('id')->limit(25)->pluck('text');

        return '<уже_в_банке>'."\n".($texts->isEmpty() ? '(пусто)' : $texts->map(fn ($t) => '- '.mb_substr($t, 0, 300))->implode("\n"))."\n".'</уже_в_банке>';
    }

    private function notes(array $params): string
    {
        $notes = trim((string) ($params['notes'] ?? ''));

        return $notes !== '' ? "\n\n".'<указания_преподавателя>'."\n".$notes."\n".'</указания_преподавателя>' : '';
    }

    /**
     * Уровни, на которых тип вопроса имеет смысл (по промпту научного руководителя):
     * развёрнутый ответ и SQL — не лёгкий уровень, сопоставление — не сложный.
     */
    private function fit(string $type, string $difficulty): string
    {
        return match (true) {
            in_array($type, ['open', 'sql'], true) && $difficulty === 'easy' => 'medium',
            $type === 'match' && $difficulty === 'hard' => 'medium',
            default => $difficulty,
        };
    }

    /** Уровни Блума для вопроса: пересечение уровня сложности и выбора преподавателя. */
    private function bloom(string $difficulty, ?string $choice): array
    {
        $level = self::LEVEL_BLOOM[$difficulty];
        $wanted = match (true) {
            $choice === null => null,
            str_contains(mb_strtolower($choice), 'только знание') => ['remember'],
            str_contains(mb_strtolower($choice), 'применение и анализ') => ['apply', 'analyze'],
            default => null,
        };
        $both = $wanted ? array_values(array_intersect($level, $wanted)) : [];

        return $both ?: $level;
    }
}
