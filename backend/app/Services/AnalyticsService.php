<?php

namespace App\Services;

use App\Models\Answer;
use App\Models\Attempt;
use App\Models\Group;
use App\Models\Question;
use App\Models\Review;
use App\Models\Test;
use App\Models\User;

/** Аналитика по тесту: распределение баллов, темы, проблемные вопросы. */
class AnalyticsService
{
    // Подписи совпадают с корзинами гистограммы: 0–19 %, затем по десяткам, последняя — 90–100 %
    public const HISTOGRAM_LABELS = ['0–19', '20–29', '30–39', '40–49', '50–59', '60–69', '70–79', '80–89', '90–100'];

    public function forTest(Test $test): array
    {
        // Студент с несколькими попытками — один студент: в аналитику идёт его лучшая попытка
        $attempts = $test->attempts()->with(['user.group', 'group'])->whereIn('status', ['submitted', 'confirmed'])->get()
            ->groupBy('user_id')->map(fn ($own) => $own->sortByDesc('percent')->first())->values();
        $total = $test->participants()->count();

        $histogram = array_fill(0, 9, 0);
        foreach ($attempts as $a) {
            $histogram[max(0, min(8, intdiv((int) $a->percent, 10) - 1))]++;
        }

        $minutes = $attempts->map(fn ($a) => $a->minutesSpent())->sort()->values();
        $median = $minutes->count() ? (int) $minutes->median() : 0;

        $answers = Answer::whereIn('attempt_id', $attempts->pluck('id'))->get()->groupBy('question_id');
        // Балл по (попытка, вопрос) одной картой — для выгрузки без перебора всех ответов на каждую ячейку
        $scoreOf = [];
        foreach ($answers->flatten(1) as $ans) {
            $scoreOf[$ans->attempt_id][$ans->question_id] = $ans->score;
        }
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
            // Тема без единого ответа — не «0 %», а просто нет данных
            if ($max > 0) {
                $topicRows[] = [$name, (int) round($got / $max * 100)];
            }
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
            // Без сдач — null («—» на экране), а не «0 %» и «0 мин», будто кто-то сдал на ноль
            'avg' => $attempts->count() ? (int) round($attempts->avg('percent')) : null,
            'medianMinutes' => $attempts->count() ? $median : null,
            // Ответы этого теста, которые ждут подтверждения преподавателем (а не очередь всех тестов)
            'pending' => \App\Models\Review::where('status', 'pending')
                ->whereHas('answer.attempt', fn ($q) => $q->where('test_id', $test->id))->count(),
            'histogram' => $histogram,
            'histogramLabels' => self::HISTOGRAM_LABELS,
            'topics' => $topicRows,
            // Заготовка: текст вывода позже сформулирует агент по ошибкам студентов.
            // По одной-двум сдачам выводы делать рано — тот же порог, что для сигналов по вопросам.
            'insightReady' => $attempts->count() >= self::MIN_ANSWERS_FOR_SIGNAL,
            'insight' => match (true) {
                $attempts->count() < self::MIN_ANSWERS_FOR_SIGNAL => 'выводы появятся, когда тест сдадут хотя бы '.self::MIN_ANSWERS_FOR_SIGNAL.' студентов (сейчас '.$attempts->count().').',
                $weakest && $weakest[1] < 70 => 'слабее всего тема «'.$weakest[0].'» — '.$weakest[1].'% баллов. Предлагаю тренировку из 8 вопросов на эту тему.',
                default => 'заметных провалов по темам нет.',
            },
            'problems' => array_slice($problems, 0, 6),
            // Для выгрузки в Excel: строка на студента, баллы по каждому вопросу
            'questionCodes' => $questions->pluck('code')->all(),
            'students' => $attempts->sortBy(fn ($a) => $a->user->name)->values()->map(fn ($a) => [
                'name' => $a->user->name,
                'group' => $a->group?->name ?? $a->user->group?->name,
                'submitted' => $a->submitted_at?->format('d.m.Y H:i'),
                'minutes' => $a->minutesSpent(),
                'score' => (float) $a->score,
                'max' => (float) $a->max_score,
                'percent' => (int) $a->percent,
                'status' => $a->status === 'confirmed' ? 'итог' : 'предварительно',
                // Пусто — вопроса не было в варианте студента (индивидуальный тест)
                'byQuestion' => $questions->map(fn ($q) => isset($scoreOf[$a->id][$q->id]) ? (float) $scoreOf[$a->id][$q->id] : '')->all(),
            ])->all(),
        ];
    }

    /**
     * Аналитика всего курса — чем страница «Аналитика» отличается от списка «Тесты»: что курс усвоил
     * по темам, как идут группы, какие вопросы решают хуже всего и как тесты выглядят рядом друг с другом.
     * В расчёт идёт лучшая попытка каждого студента в каждом тесте, как в журнале.
     */
    public function forCourse(int $courseId): array
    {
        $tests = Test::where('course_id', $courseId)->where('status', '!=', 'draft')->orderBy('id')->get();
        $best = Attempt::whereIn('test_id', $tests->pluck('id'))->whereIn('status', ['submitted', 'confirmed'])
            ->get(['id', 'test_id', 'user_id', 'group_id', 'percent', 'submitted_at'])
            ->groupBy(fn ($a) => $a->test_id.':'.$a->user_id)
            ->map(fn ($own) => $own->sortByDesc(fn ($a) => [(float) $a->percent, $a->id])->first())->values();

        $answers = Answer::whereIn('attempt_id', $best->pluck('id'))->get(['attempt_id', 'question_id', 'score', 'max_score']);
        $questions = Question::whereIn('id', $answers->pluck('question_id')->unique())->get(['id', 'code', 'text', 'topic', 'type'])->keyBy('id');

        // Темы: доля набранных баллов по всем ответам курса; слабые сверху
        $topics = [];
        foreach ($answers as $ans) {
            $topic = $questions[$ans->question_id]->topic ?? null;
            if ($topic === null || $ans->max_score <= 0) {
                continue;
            }
            $topics[$topic] ??= ['name' => $topic, 'got' => 0.0, 'max' => 0.0, 'answers' => 0];
            $topics[$topic]['got'] += (float) $ans->score;
            $topics[$topic]['max'] += (float) $ans->max_score;
            $topics[$topic]['answers']++;
        }
        $topics = collect($topics)->map(fn ($t) => ['name' => $t['name'], 'percent' => (int) round($t['got'] / $t['max'] * 100), 'answers' => $t['answers']])
            ->sortBy('percent')->values()->all();

        // Вопросы, которые чаще всего решают неверно (по всем тестам); выводы — от 5 ответов
        $problems = $answers->groupBy('question_id')->filter(fn ($list) => $list->count() >= self::MIN_ANSWERS_FOR_SIGNAL)
            ->map(function ($list, $qid) use ($questions) {
                $q = $questions[$qid];

                return ['id' => $q->code, 'text' => $q->text, 'type' => $q->type, 'topic' => $q->topic, 'answers' => $list->count(),
                    'correct' => (int) round($list->filter(fn ($a) => $a->score >= $a->max_score)->count() / $list->count() * 100)];
            })->sortBy('correct')->take(8)->values()->all();

        // Группы: средний процент лучших попыток; группа берётся на момент сдачи
        $groupNames = Group::where('course_id', $courseId)->pluck('name', 'id');
        $groups = $best->groupBy(fn ($a) => $a->group_id ?? 0)->map(fn ($list, $gid) => [
            'name' => $gid ? ($groupNames[$gid] ?? 'Удалённая группа') : 'Без группы',
            'avg' => (int) round($list->avg('percent')),
            'students' => $list->pluck('user_id')->unique()->count(),
            'works' => $list->count(),
        ])->sortBy('name')->values()->all();

        $pending = Review::query()->join('answers', 'answers.id', '=', 'reviews.answer_id')->join('attempts', 'attempts.id', '=', 'answers.attempt_id')
            ->where('reviews.status', 'pending')->whereIn('attempts.test_id', $tests->pluck('id'))
            ->groupBy('attempts.test_id')->selectRaw('attempts.test_id, count(*) as n')->pluck('n', 'test_id');
        $byTest = $best->groupBy('test_id');

        return [
            'avg' => $best->count() ? (int) round($best->avg('percent')) : null,
            'works' => $best->count(),
            'students' => $best->pluck('user_id')->unique()->count(),
            'studentsTotal' => User::where('role', User::ROLE_STUDENT)->count(),
            'pending' => (int) $pending->sum(),
            'topics' => $topics,
            'groups' => $groups,
            'problems' => $problems,
            'tests' => $tests->map(function (Test $t) use ($byTest, $pending) {
                $list = $byTest->get($t->id, collect());
                $last = $list->max('submitted_at');

                return [
                    'id' => $t->code, 'title' => $t->title, 'status' => $t->status,
                    'avg' => $list->count() ? (int) round($list->avg('percent')) : null,
                    'submitted' => $list->count(),
                    'participants' => $t->status === 'training' ? null : max($t->participants()->count(), $list->count()),
                    'pending' => (int) ($pending[$t->id] ?? 0),
                    'last' => $last?->translatedFormat('j F'),
                    'lastTs' => $last?->getTimestamp() ?? 0,
                ];
            })->sortByDesc('lastTs')->values()->all(),
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
