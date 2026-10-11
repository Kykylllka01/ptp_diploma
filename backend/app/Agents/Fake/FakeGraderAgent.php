<?php

namespace App\Agents\Fake;

use App\Agents\Contracts\GraderAgent;
use App\Agents\Support\Text;
use App\Models\Question;

/**
 * Заготовка Оценщика: по каждому критерию ищет в ответе основы слов из текста
 * критерия и эталона. Это эвристика для демонстрации, не оценка по смыслу.
 */
class FakeGraderAgent implements GraderAgent
{
    public function grade(Question $question, string $answer): array
    {
        $criteria = $question->criteria ?: [['Ответ по существу вопроса', $question->points]];
        $answerStems = Text::stems($answer);
        $refStems = Text::stems((string) $question->reference);

        $scores = [];
        $hits = [];
        $certainty = [];
        $missing = [];
        foreach ($criteria as [$text, $max]) {
            $keys = Text::stems($text);
            $keys = array_values(array_unique(array_merge($keys, array_slice(array_intersect($refStems, $keys) ?: $refStems, 0, 4))));
            $found = array_values(array_intersect($keys, $answerStems));
            $ratio = $keys ? count($found) / count($keys) : 0;
            $hits = array_merge($hits, $found);

            if ($ratio >= 0.4) {
                $scores[] = [(int) $max, 'ok', 'выполнено'];
            } elseif ($ratio >= 0.15 && $max > 1) {
                $scores[] = [(int) floor($max / 2), 'part', 'частично — раскрыто не полностью'];
                $missing[] = mb_strtolower($text);
            } else {
                $scores[] = [0, 'no', 'не выполнено'];
                $missing[] = mb_strtolower($text);
            }
            $certainty[] = abs($ratio - 0.25) / 0.75;
        }

        $confidence = (int) round(55 + 40 * min(1, array_sum($certainty) / max(1, count($certainty))));
        if (mb_strlen(trim($answer)) < 40) {
            $confidence = min($confidence, 60);
        }

        return [
            'scores' => $scores,
            'confidence' => $confidence,
            'comment' => $missing ? 'Не хватает: '.implode('; ', $missing).'.' : 'Ответ покрывает все критерии.',
            'highlighted' => $this->highlight($answer, array_unique($hits)),
        ];
    }

    /** Экранирует ответ и подсвечивает предложения, где нашлись ключевые слова. */
    private function highlight(string $answer, array $stems): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($answer)) ?: [];

        return implode(' ', array_map(function ($s) use ($stems) {
            $found = array_intersect(Text::stems($s), $stems);

            return $found ? '<mark class="good">'.e($s).'</mark>' : e($s);
        }, $sentences));
    }
}
