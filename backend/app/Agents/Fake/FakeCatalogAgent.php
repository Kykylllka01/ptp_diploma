<?php

namespace App\Agents\Fake;

use App\Agents\Contracts\CatalogAgent;
use App\Agents\Support\Text;
use App\Models\Question;
use Illuminate\Support\Collection;

/** Заготовка Каталогизатора: теги по теме и сложности, дубли — по похожести текста. */
class FakeCatalogAgent implements CatalogAgent
{
    private const BLOOM = ['easy' => 'Знание', 'medium' => 'Понимание', 'hard' => 'Анализ'];

    public function catalog(Question $question, Collection $bank): array
    {
        $result = [
            'tags' => array_values(array_filter([$question->topic, self::BLOOM[$question->difficulty] ?? null])),
            'difficulty' => $question->difficulty,
        ];

        $best = null;
        foreach ($bank as $other) {
            if ($other->id === $question->id) {
                continue;
            }
            $p = Text::similarity($question->text, $other->text);
            if (! $best || $p > $best['percent']) {
                $best = ['code' => $other->code, 'percent' => $p];
            }
        }
        if ($best && $best['percent'] >= config('agents.duplicate_threshold')) {
            $result['similar'] = $best;
        }

        return $result;
    }
}
