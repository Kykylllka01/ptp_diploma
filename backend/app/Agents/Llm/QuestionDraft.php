<?php

namespace App\Agents\Llm;

use App\Agents\Support\Text;
use App\Models\Material;
use App\Services\SqlSandbox;

/**
 * Перевод вопроса из JSON модели в поля модели Question и обратно,
 * поиск фрагмента источника для Критика и доработки.
 * Служебные поля черновика начинаются с «_» и в базу не попадают.
 */
class QuestionDraft
{
    public const POINTS = ['single' => 1, 'tf' => 1, 'multiple' => 2, 'match' => 2, 'sql' => 2, 'open' => 5];

    public const BLOOM_LABELS = [
        'remember' => 'Знание', 'understand' => 'Понимание', 'apply' => 'Применение',
        'analyze' => 'Анализ', 'evaluate' => 'Оценка', 'create' => 'Создание',
    ];

    /** Вопрос из ответа модели → черновик в формате WriterAgent::generate(); null — если разобрать нельзя. */
    public static function fromModel(array $q, array $ctx): ?array
    {
        $type = $q['type'] ?? $ctx['type'] ?? null;
        $text = trim((string) ($q['text'] ?? ''));
        if (! isset(self::POINTS[$type]) || $text === '') {
            return null;
        }
        $difficulty = in_array($q['difficulty'] ?? '', ['easy', 'medium', 'hard'], true) ? $q['difficulty'] : ($ctx['difficulty'] ?? 'medium');
        $bloom = isset(self::BLOOM_LABELS[$q['bloom'] ?? '']) ? $q['bloom'] : ($ctx['bloom'] ?? 'understand');
        $quote = trim((string) ($q['quote'] ?? ''));
        $source = (string) ($ctx['source_text'] ?? '');

        $draft = [
            'type' => $type,
            'difficulty' => $difficulty,
            'topic' => $ctx['topic'] ?? null,
            'source' => $ctx['source'] ?? null,
            'points' => self::POINTS[$type],
            'text' => $text,
            'context' => trim((string) ($q['context'] ?? '')) ?: null,
            'explanation' => trim((string) ($q['explanation'] ?? '')) ?: null,
            'quote' => $quote !== '' ? Text::excerpt($source, $quote) : null,
            'tags' => array_values(array_filter([$ctx['topic'] ?? null, self::BLOOM_LABELS[$bloom]])),
            '_bloom' => $bloom,
            '_concept' => (string) ($q['concept'] ?? ''),
            '_quote' => $quote,
            '_source' => $source,
        ];

        switch ($type) {
            case 'single':
            case 'multiple':
                $draft['options'] = self::options($q['options'] ?? []);
                break;
            case 'tf':
                $correctTrue = collect($q['options'] ?? [])->contains(fn ($o) => ! empty($o['correct']) && mb_stripos((string) ($o['text'] ?? ''), 'невер') === false);
                $draft['options'] = $correctTrue
                    ? [['text' => 'Верно', 'correct' => true], ['text' => 'Неверно']]
                    : [['text' => 'Верно'], ['text' => 'Неверно', 'correct' => true]];
                break;
            case 'match':
                $draft['options'] = [];
                foreach ($q['pairs'] ?? [] as $p) {
                    if (! empty($p['term']) && ! empty($p['definition'])) {
                        $draft['options'][] = ['text' => trim($p['term']).' — '.trim($p['definition']), 'correct' => true];
                    }
                }
                if (! empty($q['extra'])) {
                    $draft['options'][] = ['text' => 'Лишнее: '.trim((string) $q['extra'])];
                }
                break;
            case 'open':
                $draft['reference'] = trim((string) ($q['reference'] ?? '')) ?: null;
                $draft['criteria'] = [];
                foreach ($q['criteria'] ?? [] as $c) {
                    $label = is_array($c) ? ($c['text'] ?? $c[0] ?? '') : (string) $c;
                    $pts = is_array($c) ? (int) ($c['points'] ?? $c[1] ?? 1) : 1;
                    if (trim($label) !== '') {
                        $draft['criteria'][] = [trim($label), max(1, $pts)];
                    }
                }
                $draft['points'] = (int) ($q['points'] ?? 0) ?: (array_sum(array_column($draft['criteria'], 1)) ?: self::POINTS['open']);
                break;
            case 'sql':
                $draft['reference'] = trim((string) ($q['reference'] ?? '')) ?: null;
                $draft['sql_schema'] = app(SqlSandbox::class)->schema();
                break;
        }
        return $draft;
    }

    /** Черновик → JSON для модели (Критику и на доработку), без служебных полей. */
    public static function toModel(array $draft): array
    {
        $q = [
            'type' => $draft['type'],
            'difficulty' => $draft['difficulty'] ?? 'medium',
            'bloom' => $draft['_bloom'] ?? null,
            'concept' => $draft['_concept'] ?? null,
            'context' => $draft['context'] ?? null,
            'text' => $draft['text'],
            'points' => (int) ($draft['points'] ?? 1),
        ];
        if (($draft['type'] ?? '') === 'match') {
            $q['pairs'] = [];
            foreach ($draft['options'] ?? [] as $o) {
                if (str_starts_with($o['text'], 'Лишнее: ')) {
                    $q['extra'] = mb_substr($o['text'], 8);
                } else {
                    [$term, $def] = array_pad(explode(' — ', \App\Models\Question::normalizePair((string) $o['text']), 2), 2, '');
                    $q['pairs'][] = ['term' => $term, 'definition' => $def];
                }
            }
        } elseif (! empty($draft['options'])) {
            $q['options'] = array_map(fn ($o) => ['text' => $o['text'], 'correct' => ! empty($o['correct'])], $draft['options']);
        }
        foreach (['explanation', 'reference'] as $f) {
            if (! empty($draft[$f])) {
                $q[$f] = $draft[$f];
            }
        }
        if (! empty($draft['criteria'])) {
            $q['criteria'] = array_map(fn ($c) => ['text' => $c[0], 'points' => (int) $c[1]], $draft['criteria']);
        }
        $q['quote'] = $draft['_quote'] ?? self::markedQuote($draft['quote'] ?? '');

        return array_filter($q, fn ($v) => $v !== null && $v !== '');
    }

    /** Фрагмент источника, по которому составлен вопрос. */
    public static function sourceText(array $draft): string
    {
        if (! empty($draft['_source'])) {
            return $draft['_source'];
        }
        $quote = self::markedQuote($draft['quote'] ?? '');
        if (! empty($draft['material_id']) && ($m = Material::find($draft['material_id'])) && $m->extracted_text) {
            foreach (Text::chunks($m->extracted_text, (int) config('agents.chunk_chars')) as $chunk) {
                if ($quote !== '' && Text::contains($chunk['text'], $quote)) {
                    return $chunk['text'];
                }
            }
        }

        return html_entity_decode(strip_tags((string) ($draft['quote'] ?? '')), ENT_QUOTES, 'UTF-8');
    }

    /** Текст внутри <mark> сохранённого фрагмента. */
    public static function markedQuote(string $html): string
    {
        return preg_match('/<mark[^>]*>(.*?)<\/mark>/su', $html, $m)
            ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')
            : html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    }

    private static function options(array $raw): array
    {
        $out = [];
        foreach ($raw as $o) {
            $text = trim((string) (is_array($o) ? ($o['text'] ?? '') : $o));
            if ($text === '') {
                continue;
            }
            $out[] = ! empty($o['correct']) ? ['text' => $text, 'correct' => true] : ['text' => $text];
        }

        return $out;
    }
}
