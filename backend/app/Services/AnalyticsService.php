<?php

namespace App\Services;

use App\Models\Answer;
use App\Models\Question;
use App\Models\Test;
use App\Models\User;

/** Аналитика по тесту: распределение баллов, темы, проблемные вопросы. */
class AnalyticsService
{
    public const HISTOGRAM_LABELS = ['0–10', '20', '30', '40', '50', '60', '70', '80', '90–100%'];

    public function forTest(Test $test): array
    {
        $attempts = $test->attempts()->with('user')->whereIn('status', ['submitted', 'confirmed'])->get();
        $total = $test->group_name
            ? User::where('role', User::ROLE_STUDENT)->where('group_name', $test->group_name)->count()
            : User::where('role', User::ROLE_STUDENT)->count();

        $histogram = array_fill(0, 9, 0);
        foreach ($attempts as $a) {
            $histogram[max(0, min(8, intdiv((int) $a->percent, 10) - 1))]++;
        }

        $minutes = $attempts->map(fn ($a) => $a->minutesSpent())->sort()->values();
        $median = $minutes->count() ? (int) $minutes->median() : 0;

        $answers = Answer::whereIn('attempt_id', $attempts->pluck('id'))->get()->groupBy('question_id');
        $questions = $test->questions;

        // По темам: доля набранных баллов
        $topics = [];
        foreach ($questions as $q) {
            $list = $answers->get($q->id, collect());
            $topics[$q->topic][0] = ($topics[$q->topic][0] ?? 0) + $list->sum('score');
            $topics[$q->topic][1] = ($topics[$q->topic][1] ?? 0) + $list->count() * $q->points;
        }
        $topicRows = [];
        foreach ($topics as $name => [$got, $max]) {
            $topicRows[] = [$name, $max > 0 ? (int) round($got / $max * 100) : 0];
        }
        usort($topicRows, fn ($a, $b) => $b[1] <=> $a[1]);

        // Проблемные вопросы: доля полностью верных ответов и сигнал
        $problems = [];
        foreach ($questions as $q) {
            $list = $answers->get($q->id, collect());
            if (! $list->count()) {
                continue;
            }
            $correct = (int) round($list->filter(fn ($a) => $a->score >= $a->max_score)->count() / $list->count() * 100);
            $problems[] = [
                'id' => $q->code,
                'text' => $q->text,
                'type' => $q->type,
                'correct' => $correct,
                // По одному-двум ответам выводы делать рано
                'signal' => $list->count() >= self::MIN_ANSWERS_FOR_SIGNAL ? $this->signal($q, $correct) : null,
            ];
        }
        usort($problems, fn ($a, $b) => ($b['signal'] ? 1 : 0) <=> ($a['signal'] ? 1 : 0) ?: $a['correct'] <=> $b['correct']);

        $weakest = $topicRows ? end($topicRows) : null;

        return [
            'testId' => $test->code,
            'submitted' => $attempts->count(),
            'total' => max($total, $attempts->count()),
            'avg' => $attempts->count() ? (int) round($attempts->avg('percent')) : 0,
            'medianMinutes' => $median,
            'histogram' => $histogram,
            'histogramLabels' => self::HISTOGRAM_LABELS,
            'topics' => $topicRows,
            // Заготовка: текст вывода позже сформулирует агент по ошибкам студентов
            'insight' => $weakest && $weakest[1] < 70
                ? 'слабее всего тема «'.$weakest[0].'» — '.$weakest[1].'% баллов. Предлагаю тренировку из 8 вопросов на эту тему.'
                : 'заметных провалов по темам нет.',
            'problems' => array_slice($problems, 0, 6),
            // Для выгрузки в Excel: строка на студента, баллы по каждому вопросу
            'questionCodes' => $questions->pluck('code')->all(),
            'students' => $attempts->sortBy(fn ($a) => $a->user->name)->values()->map(fn ($a) => [
                'name' => $a->user->name,
                'group' => $a->user->group_name,
                'submitted' => $a->submitted_at?->format('d.m.Y H:i'),
                'minutes' => $a->minutesSpent(),
                'score' => (float) $a->score,
                'max' => (float) $a->max_score,
                'percent' => (int) $a->percent,
                'status' => $a->status === 'confirmed' ? 'итог' : 'предварительно',
                'byQuestion' => $questions->map(fn ($q) => (float) ($answers->get($q->id)?->firstWhere('attempt_id', $a->id)?->score ?? 0))->all(),
            ])->all(),
        ];
    }

    private const MIN_ANSWERS_FOR_SIGNAL = 5;

    private function signal(Question $q, int $correct): ?array
    {
        if ($correct < 30 && $q->isChoice()) {
            return ['Возможна ошибка в ключе', 'red'];
        }
        if ($correct < 50) {
            return ['Низкая различающая сила', 'amber'];
        }
        if ($correct > 95) {
            return ['Слишком лёгкий', 'gray'];
        }

        return null;
    }
}
