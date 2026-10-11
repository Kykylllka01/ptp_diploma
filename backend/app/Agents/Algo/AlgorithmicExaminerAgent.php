<?php

namespace App\Agents\Algo;

use App\Agents\Contracts\ExaminerAgent;
use App\Models\Answer;
use App\Models\Question;
use Illuminate\Support\Collection;

/**
 * Экзаменатор без нейросети: сборка теста по матрице «тип × сложность» и лестница
 * сложности в адаптивном режиме. Калибровка: когда на вопрос накопилось достаточно
 * ответов, его сложность берётся из доли правильных ответов, а не из оценки Составителя.
 */
class AlgorithmicExaminerAgent implements ExaminerAgent
{
    private const LEVELS = ['easy', 'medium', 'hard'];

    public function assemble(Collection $pool, array $matrix, array $topics): Collection
    {
        $level = $this->calibrate($pool);
        $byTopic = $topics ? $pool->filter(fn (Question $q) => in_array($q->topic, $topics, true)) : $pool;
        // Если по выбранным темам вопросов мало, добираем из всего банка
        $source = $byTopic->count() >= 3 ? $byTopic : $pool;

        $picked = collect();
        foreach ($matrix as $row) {
            [$type, , $easy, $medium, $hard] = $row;
            foreach (['easy' => $easy, 'medium' => $medium, 'hard' => $hard] as $difficulty => $n) {
                if ($n <= 0) {
                    continue;
                }
                $picked = $picked->merge(
                    $source->filter(fn (Question $q) => $q->type === $type && $level[$q->id] === $difficulty)->shuffle()->take((int) $n)
                );
            }
        }

        return $picked->unique('id')->values();
    }

    public function next(Collection $pool, array $history): ?Question
    {
        $level = $this->calibrate($pool);
        $step = 1;
        foreach ($history as $h) {
            $step = max(0, min(2, $step + ($h['correct'] ? 1 : -1)));
        }

        return $pool->filter(fn (Question $q) => $level[$q->id] === self::LEVELS[$step])->shuffle()->first() ?? $pool->shuffle()->first();
    }

    /**
     * Сложность каждого вопроса: по доле правильных ответов, если их достаточно, иначе заявленная.
     *
     * @return array<int, string> id вопроса => easy|medium|hard
     */
    private function calibrate(Collection $pool): array
    {
        $min = (int) config('agents.calibration_min_answers', 20);
        $stats = Answer::query()
            ->whereIn('question_id', $pool->pluck('id'))
            ->whereNotNull('score')->where('max_score', '>', 0)->where('is_final', true)
            ->selectRaw('question_id, COUNT(*) AS n, SUM(CASE WHEN score >= max_score THEN 1 ELSE 0 END) AS correct')
            ->groupBy('question_id')->get()->keyBy('question_id');

        $out = [];
        foreach ($pool as $q) {
            $s = $stats->get($q->id);
            $share = $s && $s->n >= $min ? $s->correct / $s->n : null;
            $out[$q->id] = $share === null ? $q->difficulty : ($share >= 0.8 ? 'easy' : ($share >= 0.45 ? 'medium' : 'hard'));
        }

        return $out;
    }
}
