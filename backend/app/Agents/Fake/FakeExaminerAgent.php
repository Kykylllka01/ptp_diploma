<?php

namespace App\Agents\Fake;

use App\Agents\Contracts\ExaminerAgent;
use App\Models\Question;
use Illuminate\Support\Collection;

/** Заготовка Экзаменатора: случайный выбор по матрице и простая лестница сложности. */
class FakeExaminerAgent implements ExaminerAgent
{
    private const LEVELS = ['easy', 'medium', 'hard'];

    public function assemble(Collection $pool, array $matrix, array $topics): Collection
    {
        $byTopic = $topics ? $pool->filter(fn (Question $q) => in_array($q->topic, $topics, true)) : $pool;
        // Если по выбранным темам вопросов мало, добираем из всего банка
        $source = $byTopic->count() >= 3 ? $byTopic : $pool;

        $picked = collect();
        foreach ($matrix as $row) {
            [$type, , $easy, $medium, $hard] = $row;
            foreach (['easy' => $easy, 'medium' => $medium, 'hard' => $hard] as $level => $n) {
                if ($n <= 0) {
                    continue;
                }
                $picked = $picked->merge(
                    $source->where('type', $type)->where('difficulty', $level)->shuffle()->take((int) $n)
                );
            }
        }

        return $picked->unique('id')->values();
    }

    public function next(Collection $pool, array $history): ?Question
    {
        $level = 1;
        foreach ($history as $h) {
            $level = max(0, min(2, $level + ($h['correct'] ? 1 : -1)));
        }

        return $pool->where('difficulty', self::LEVELS[$level])->shuffle()->first() ?? $pool->shuffle()->first();
    }
}
