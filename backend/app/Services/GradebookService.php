<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\Group;
use App\Models\Test;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Результаты студентов для преподавателя и журнал для переноса в Модеус.
 * В зачёт идёт лучшая сданная попытка (как в аналитике); баллы — её процент, умноженный на вес теста.
 * «Предварительно» — пока у попытки есть развёрнутые ответы без подтверждения преподавателя.
 */
class GradebookService
{
    /** Все участники теста — и те, кто ещё не начинал: в ведомость попадает каждый. */
    public function forTest(Test $test): array
    {
        $test->loadMissing('groups');
        $attempts = $test->attempts()->with('group')->withCount('proctorEvents')->whereIn('status', ['in_progress', 'submitted', 'confirmed'])->get()->groupBy('user_id');
        // Назначенные студенты плюс те, кто сдавал и потом перешёл в другую группу
        $students = $test->participants()->with('group')->get()
            ->merge(User::with('group')->whereIn('id', $attempts->keys())->get())
            ->unique('id');

        $rows = $students->map(fn (User $u) => $this->row($test, $u, $attempts->get($u->id, collect())))
            ->sortBy(fn ($r) => [$r['group'] ?? 'яяя', mb_strtolower($r['name'])])->values();

        return [
            'testId' => $test->code,
            'title' => $test->title,
            'weight' => $test->weight(),
            'transferred' => $test->settings['transferred'] ?? [],
            'proctoring' => $test->isProctored(),
            'students' => $rows->all(),
            'counts' => [
                'total' => $rows->count(),
                'final' => $rows->where('status', 'final')->count(),
                'pending' => $rows->where('status', 'pending')->count(),
                'none' => $rows->whereIn('status', ['none', 'in_progress'])->count(),
                'proctor' => $rows->where('violations', '>', 0)->count(),
            ],
        ];
    }

    /**
     * Журнал группы: студенты по алфавиту (как список в Модеусе) × тесты группы, в ячейке — баллы лучшей попытки.
     * $group = null — студенты без группы.
     */
    public function forGroup(?Group $group, int $courseId): array
    {
        $students = User::with('group')->where('role', User::ROLE_STUDENT)->where('group_id', $group?->id)->orderBy('name')->get();
        $tests = Test::where('course_id', $courseId)->where('status', '!=', 'draft')->where('graded', true)
            ->where(fn ($q) => $q->where('audience', 'all')
                ->orWhereHas('groups', fn ($g) => $g->where('student_groups.id', $group?->id ?? 0)))
            ->orderBy('id')->get();
        $attempts = Attempt::with('group')->withCount('proctorEvents')->whereIn('test_id', $tests->pluck('id'))->whereIn('user_id', $students->pluck('id'))
            ->whereIn('status', ['in_progress', 'submitted', 'confirmed'])->get()->groupBy(fn ($a) => $a->test_id.':'.$a->user_id);

        $key = (string) ($group?->id ?? 0);

        return [
            'group' => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'tests' => $tests->map(fn (Test $t) => [
                'id' => $t->code,
                'title' => $t->title,
                'weight' => $t->weight(),
                'status' => $t->status,
                'transferred' => $t->settings['transferred'][$key] ?? null,
            ])->all(),
            'students' => $students->map(function (User $u) use ($tests, $attempts) {
                $cells = [];
                $total = 0.0;
                foreach ($tests as $t) {
                    $r = $this->row($t, $u, $attempts->get($t->id.':'.$u->id, collect()));
                    $cells[$t->code] = array_intersect_key($r, array_flip(['status', 'percent', 'points', 'attemptId', 'attempts', 'violations']));
                    $total += $r['points'] ?? 0;
                }

                return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'cells' => $cells, 'total' => round($total, 1)];
            })->values()->all(),
        ];
    }

    private function row(Test $test, User $u, Collection $own): array
    {
        $done = $own->filter(fn (Attempt $a) => $a->isSubmitted());
        $best = $done->sortByDesc(fn ($a) => [(float) $a->percent, $a->id])->first();
        $status = match (true) {
            $best !== null => $best->status === 'confirmed' ? 'final' : 'pending',
            $own->isNotEmpty() => 'in_progress',
            default => 'none',
        };

        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'group' => $best?->group?->name ?? $u->group?->name,
            'status' => $status,
            'attempts' => $done->count(),
            'attemptId' => $best?->id,
            'percent' => $best ? (int) $best->percent : null,
            'score' => $best ? (float) $best->score : null,
            'max' => $best ? (float) $best->max_score : null,
            'points' => $best ? $test->points($best->exactPercent()) : null,
            'minutes' => $best?->minutesSpent(),
            'submitted' => $best?->submitted_at?->format('d.m.Y H:i'),
            // Прокторинг: уходы со страницы теста во всех попытках студента
            'violations' => (int) $own->sum('proctor_events_count'),
        ];
    }
}
