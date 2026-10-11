<?php

namespace App\Http\Controllers\Api;

use App\Agents\Contracts\ExaminerAgent;
use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\Test;
use App\Services\FrontendData;
use App\Support\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TestController extends Controller
{
    public function __construct(private FrontendData $data)
    {
    }

    /** Публикация теста со страницы «Сборка теста». */
    public function store(Request $request, ExaminerAgent $examiner)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'mode' => ['required', Rule::in(['rules', 'manual', 'adaptive'])],
            'matrix' => 'required|array',
            'matrix.*' => 'array|size:6',
            'topics' => 'array',
            'group' => 'nullable|string|max:100',
            'opens_at' => 'nullable|date',
            'deadline_at' => 'nullable|date',
            'minutes' => 'nullable|integer|min:0|max:600',
            'attempts' => 'nullable|integer|min:1|max:99',
            'settings' => 'nullable|array',
            'settings.shuffle' => 'boolean',
            'settings.show_review' => 'boolean',
            'settings.auto_grade' => 'boolean',
            'training' => 'boolean',
            'publish' => 'boolean',
        ]);

        $course = CurrentUser::course();
        $pool = Question::where('course_id', $course->id)->where('status', 'approved')->get();
        $picked = $data['mode'] === 'manual' ? collect() : $examiner->assemble($pool, $data['matrix'], $data['topics'] ?? []);

        $test = Test::create([
            'code' => 't'.Str::lower(Str::random(6)),
            'course_id' => $course->id,
            'title' => $data['title'],
            'status' => $data['mode'] === 'manual' ? 'draft' : (! empty($data['training']) ? 'training' : (! empty($data['publish']) ? 'running' : 'draft')),
            'mode' => $data['mode'],
            'group_name' => $data['group'] ?? null,
            'minutes' => $data['minutes'] ?? 45,
            'attempts_allowed' => $data['attempts'] ?? 1,
            'graded' => empty($data['training']),
            'opens_at' => $data['opens_at'] ?? null,
            'deadline_at' => $data['deadline_at'] ?? null,
            'settings' => [
                'matrix' => $data['matrix'],
                'topics' => $data['topics'] ?? [],
                'publish_as' => ! empty($data['training']) ? 'training' : (! empty($data['publish']) ? 'running' : null),
                'shuffle' => (bool) ($data['settings']['shuffle'] ?? true),
                'show_review' => (bool) ($data['settings']['show_review'] ?? true),
                'auto_grade' => (bool) ($data['settings']['auto_grade'] ?? false),
            ],
        ]);
        foreach ($picked->values() as $i => $q) {
            $test->questions()->attach($q->id, ['position' => $i]);
        }

        $requested = array_sum(array_map(fn ($r) => $r[2] + $r[3] + $r[4], $data['matrix']));

        return [
            'test' => $this->data->test($test->loadCount('questions')),
            'requested' => $requested,
            'picked' => $picked->count(),
        ];
    }

    /** Открыть или закрыть приём ответов, переименовать. */
    public function update(Request $request, Test $test)
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['running', 'closed', 'training', 'draft'])],
            'title' => 'sometimes|string|max:255',
            'deadline_at' => 'sometimes|nullable|date',
        ]);
        // Повторное открытие после срока: продлеваем на неделю, иначе тест сразу снова закроется
        if (($data['status'] ?? null) === 'running' && $test->deadline_at?->isPast() && ! array_key_exists('deadline_at', $data)) {
            $data['deadline_at'] = now()->addWeek()->setTime(23, 59);
        }
        if (($data['status'] ?? null) === 'running' && $test->opens_at?->isFuture()) {
            $data['opens_at'] = now();
        }
        $test->update($data);

        return $this->data->test($test->loadCount('questions'));
    }

    /** Удалить тест вместе с попытками студентов. */
    public function destroy(Test $test)
    {
        $test->delete();

        return response()->noContent();
    }

    /** Добавить вопросы из банка в тест (массовое действие в банке). */
    public function attachQuestions(Request $request, Test $test)
    {
        $data = $request->validate(['ids' => 'required|array|min:1']);
        $position = (int) $test->questions()->max('position');
        $added = 0;
        foreach (Question::whereIn('code', $data['ids'])->get() as $q) {
            if (! $test->questions()->where('questions.id', $q->id)->exists()) {
                $test->questions()->attach($q->id, ['position' => ++$position]);
                $added++;
            }
        }

        // Тест, собранный вручную, публикуется, как только в нём появились вопросы
        if ($test->status === 'draft' && $added && ($publishAs = $test->settings['publish_as'] ?? null)) {
            $test->update(['status' => $publishAs]);
        }

        return ['added' => $added, 'test' => $this->data->test($test->loadCount('questions'))];
    }

    /**
     * Сколько одобренных вопросов в банке: по теме, типу и сложности.
     * Сборка теста берёт отсюда матрицу по умолчанию и предупреждает о нехватке.
     */
    public function bankStats()
    {
        $rows = Question::where('course_id', CurrentUser::course()->id)->where('status', 'approved')
            ->selectRaw('topic, type, difficulty, count(*) as n')->groupBy('topic', 'type', 'difficulty')->get();

        $out = ['total' => (int) $rows->sum('n'), 'counts' => []];
        foreach ($rows as $r) {
            $out['counts'][$r->topic][$r->type][$r->difficulty] = (int) $r->n;
        }

        return $out;
    }
}
