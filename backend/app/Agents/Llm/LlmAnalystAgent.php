<?php

namespace App\Agents\Llm;

use App\Agents\Contracts\AnalystAgent;
use App\Agents\Support\Text;
use App\Models\Material;

/**
 * Аналитик: материал режется на фрагменты, по каждому модель выделяет тему,
 * понятия и примеры с дословными цитатами. Код отбрасывает понятия, чья цитата
 * не нашлась во фрагменте. Кроме полей контракта возвращает `chunks` — разобранные
 * фрагменты для Составителя.
 */
class LlmAnalystAgent implements AnalystAgent
{
    private const BLOOM = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'];

    public function __construct(private AgentLlm $llm)
    {
    }

    public function analyze(Material $material, array $courseTopics): array
    {
        $chunks = Text::chunks((string) $material->extracted_text, (int) config('agents.chunk_chars'));
        $chunks = $this->limit($chunks, (int) config('agents.max_chunks'));
        $course = $material->course->title ?? 'Базы данных';

        $result = ['topics' => [], 'concepts' => 0, 'examples' => 0, 'fragments' => [], 'chunks' => []];
        foreach ($chunks as $chunk) {
            $user = '<темы_курса>'."\n".implode("\n", array_map(fn ($t) => '- '.$t, $courseTopics))."\n".'</темы_курса>'."\n\n"
                .'<фрагмент место="'.$material->title.', '.$chunk['place'].'">'."\n".$chunk['text']."\n".'</фрагмент>';

            $json = $this->llm->json('analyst', $user, ['course' => $course], fn ($j) => $this->validate($j));

            $topic = in_array($json['topic'] ?? '', $courseTopics, true) ? $json['topic'] : 'другое';
            $concepts = array_values(array_filter($json['concepts'] ?? [], fn ($c) => Text::contains($chunk['text'], (string) ($c['quote'] ?? ''))));
            $examples = array_values(array_filter($json['examples'] ?? [], fn ($e) => Text::contains($chunk['text'], (string) ($e['quote'] ?? ''))));
            foreach ($concepts as &$c) {
                $c['bloom'] = in_array($c['bloom'] ?? '', self::BLOOM, true) ? $c['bloom'] : 'understand';
            }
            unset($c);

            if (! $concepts && ! $examples) {
                continue;
            }

            $result['chunks'][] = [
                'place' => $chunk['place'],
                'text' => $chunk['text'],
                'topic' => $topic,
                'concepts' => $concepts,
                'examples' => $examples,
            ];
            $result['concepts'] += count($concepts);
            $result['examples'] += count($examples);
            if ($topic !== 'другое' && ! in_array($topic, $result['topics'], true)) {
                $result['topics'][] = $topic;
                $quote = $concepts[0]['quote'] ?? $examples[0]['quote'];
                $result['fragments'][$topic] = Text::excerpt($chunk['text'], $quote);
            }
        }

        // Материал не относится ни к одной теме курса — работаем с ним как есть
        if (! $result['topics'] && $result['chunks']) {
            $result['topics'] = ['другое'];
        }

        return $result;
    }

    private function validate(array $j): ?string
    {
        if (! array_key_exists('topic', $j) || ! is_array($j['concepts'] ?? null)) {
            return 'нужны поля topic и concepts (массив)';
        }
        foreach ($j['concepts'] as $i => $c) {
            if (! is_array($c) || empty($c['name']) || ! isset($c['quote'])) {
                return 'у понятия '.$i.' нет name или quote';
            }
        }

        return null;
    }

    /** Если фрагментов больше лимита — берём равномерно по всему материалу. */
    private function limit(array $chunks, int $max): array
    {
        if ($max <= 0 || count($chunks) <= $max) {
            return $chunks;
        }
        $step = count($chunks) / $max;
        $out = [];
        for ($i = 0; $i < $max; $i++) {
            $out[] = $chunks[(int) floor($i * $step)];
        }

        return $out;
    }
}
