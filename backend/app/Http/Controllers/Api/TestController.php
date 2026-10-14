<?php

namespace App\Http\Controllers\Api;

use App\Agents\Contracts\ExaminerAgent;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Question;
use App\Models\Test;
use App\Services\FrontendData;
use App\Support\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'matrix' => 'required|array|max:20',
            'matrix.*' => 'array|size:6',
            // Строка матрицы: [тип, баллы, лёгких, средних, сложных, минут на вопрос] — числа, иначе 500 при сложении
            'matrix.*.0' => ['required', Rule::in(array_keys(Question::TYPES))],
            'matrix.*.1' => 'numeric|min:0|max:100',
            'matrix.*.2' => 'integer|min:0|max:200',
            'matrix.*.3' => 'integer|min:0|max:200',
            'matrix.*.4' => 'integer|min:0|max:200',
            'matrix.*.5' => 'numeric|min:0|max:60',
            'topics' => 'array',
            'topics.*' => 'string|max:255',
            // Участники: пусто — все студенты курса; иначе только эти группы (своего курса)
            'groups' => 'nullable|array|max:50',
            'groups.*' => ['integer', Rule::exists('student_groups', 'id')->where('course_id', CurrentUser::course()->id)],
            'opens_at' => 'nullable|date',
            'deadline_at' => 'nullable|date',
            'minutes' => 'nullable|integer|min:0|max:600',
            'attempts' => 'nullable|integer|min:1|max:99',
            'settings' => 'nullable|array',
            'settings.shuffle' => 'boolean',
            'settings.show_review' => 'boolean',
            'settings.auto_grade' => 'boolean',
            'settings.individual' => 'boolean',
            'settings.proctoring' => 'boolean',
            // Сколько баллов БРС даёт тест (за 100 %); без веса в журнале — проценты
            'weight' => 'nullable|numeric|min:0|max:100',
            'training' => 'boolean',
            'publish' => 'boolean',
        ]);

        if (! empty($data['opens_at']) && ! empty($data['deadline_at']) && strtotime($data['deadline_at']) <= strtotime($data['opens_at'])) {
            return response()->json(['message' => 'Срок сдачи должен быть позже открытия теста'], 422);
        }

        $course = CurrentUser::course();
        $pool = Question::where('course_id', $course->id)->where('status', 'approved')->get();
        $picked = $data['mode'] === 'manual' ? collect() : $examiner->assemble($pool, $data['matrix'], $data['topics'] ?? []);
        // Опубликовать тест без вопросов — студент сдаст «0 из 0», а попытка будет потрачена
        if ($data['mode'] !== 'manual' && $picked->isEmpty() && (! empty($data['publish']) || ! empty($data['training']))) {
            return response()->json(['message' => 'В банке нет одобренных вопросов под выбранную матрицу и темы — тест не опубликован'], 422);
        }
        // Индивидуальный вариант: к тесту прикрепляется весь пул нужных типов и тем,
        // а вариант каждому студенту Экзаменатор собирает при старте попытки
        $individual = $data['mode'] !== 'manual' && ! empty($data['settings']['individual']);
        // Сколько вопросов достанется студенту: клетка матрицы, где в банке меньше вопросов, чем нужно, даёт меньше
        $variant = $picked->count();
        if ($individual) {
            $types = array_column(array_filter($data['matrix'], fn ($r) => $r[2] + $r[3] + $r[4] > 0), 0);
            $topics = $data['topics'] ?? [];
            $picked = $pool->filter(fn ($q) => in_array($q->type, $types, true) && (! $topics || in_array($q->topic, $topics, true)))->values();
        }

        $groups = array_values(array_unique($data['groups'] ?? []));
        $test = DB::transaction(function () use ($data, $course, $picked, $individual, $variant, $groups) {
            $test = Test::create([
                'code' => 't'.Str::lower(Str::random(6)),
                'course_id' => $course->id,
                'user_id' => CurrentUser::teacher()->id,
                'title' => $data['title'],
                'status' => $data['mode'] === 'manual' ? 'draft' : (! empty($data['training']) ? 'training' : (! empty($data['publish']) ? 'running' : 'draft')),
                'mode' => $data['mode'],
                'audience' => $groups ? 'groups' : 'all',
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
                    'individual' => $individual,
                    'variant' => $individual ? $variant : null,
                    // Жёсткий прокторинг: в тренировке попыток нет — там он не нужен
                    'proctoring' => empty($data['training']) && ! empty($data['settings']['proctoring']),
                    'weight' => isset($data['weight']) ? round((float) $data['weight'], 1) : null,
                ],
            ]);
            $test->questions()->attach($picked->values()->mapWithKeys(fn ($q, $i) => [$q->id => ['position' => $i]])->all());
            $test->groups()->sync($groups);

            return $test;
        });

        $requested = array_sum(array_map(fn ($r) => $r[2] + $r[3] + $r[4], $data['matrix']));

        return [
            'test' => $this->data->test($test->loadCount('questions')),
            'requested' => $requested,
            'picked' => $variant,
            'pool' => $picked->count(),
        ];
    }

    /** Открыть или закрыть приём ответов, переименовать. */
    public function update(Request $request, Test $test)
    {
        $this->onlyAuthor($test, 'Менять тест');
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['running', 'closed', 'training', 'draft'])],
            'title' => 'sometimes|string|max:255',
            // Срок в прошлом запрещаем, только когда его меняют: у закрытого теста можно поправить группы, не трогая срок
            'deadline_at' => array_merge(['sometimes', 'nullable', 'date'],
                $request->filled('deadline_at') && $request->input('deadline_at') !== $test->deadline_at?->format('Y-m-d\TH:i') ? ['after:now'] : []),
            'groups' => 'sometimes|nullable|array|max:50',
            'groups.*' => ['integer', Rule::exists('student_groups', 'id')->where('course_id', $test->course_id)],
            'weight' => 'sometimes|nullable|numeric|min:0|max:100',
            'proctoring' => 'sometimes|boolean',
        ], ['deadline_at.after' => 'Срок сдачи уже прошёл — выберите время в будущем']);
        // Повторное открытие после срока: продлеваем на неделю, иначе тест сразу снова закроется
        if (($data['status'] ?? null) === 'running' && $test->deadline_at?->isPast() && ! array_key_exists('deadline_at', $data)) {
            $data['deadline_at'] = now()->addWeek()->setTime(23, 59);
        }
        if (($data['status'] ?? null) === 'running' && $test->opens_at?->isFuture()) {
            $data['opens_at'] = now();
        }
        if (in_array($data['status'] ?? null, ['running', 'training'], true) && ! $test->questions()->exists()) {
            return response()->json(['message' => 'В тесте нет вопросов — добавьте их из банка, потом открывайте приём'], 422);
        }
        DB::transaction(function () use ($test, $data) {
            if (array_key_exists('weight', $data) || array_key_exists('proctoring', $data)) {
                // Настройки перечитываются под блокировкой: правка из другой вкладки не затрётся
                $settings = Test::lockForUpdate()->findOrFail($test->id)->settings ?? [];
                if (array_key_exists('weight', $data)) {
                    $settings['weight'] = $data['weight'] === null ? null : round((float) $data['weight'], 1);
                }
                if (array_key_exists('proctoring', $data)) {
                    $settings['proctoring'] = (bool) $data['proctoring'];
                }
                $test->settings = $settings;
                unset($data['weight'], $data['proctoring']);
            }
            if (array_key_exists('groups', $data)) {
                $groups = array_values(array_unique($data['groups'] ?? []));
                $test->groups()->sync($groups);
                $data['audience'] = $groups ? 'groups' : 'all';
                unset($data['groups']);
            }
            $test->update($data);
        });

        return $this->data->test($test->load('groups')->loadCount('questions'));
    }

    /** Отметка «баллы перенесены в Модеус» для группы (0 — студенты без группы): дата или снять. */
    public function transferred(Request $request, Test $test)
    {
        $this->onlyAuthor($test, 'Отмечать перенос баллов');
        $data = $request->validate(['group' => 'required|integer|min:0', 'done' => 'required|boolean']);
        // 0 — студенты без группы; иначе группа этого же курса
        abort_if($data['group'] && ! Group::where('id', $data['group'])->where('course_id', $test->course_id)->exists(), 422, 'Нет такой группы');

        return DB::transaction(function () use ($test, $data) {
            $locked = Test::lockForUpdate()->findOrFail($test->id);
            $settings = $locked->settings ?? [];
            $marks = $settings['transferred'] ?? [];
            if ($data['done']) {
                $marks[(string) $data['group']] = now()->format('d.m.Y');
            } else {
                unset($marks[(string) $data['group']]);
            }
            $settings['transferred'] = $marks;
            $locked->update(['settings' => $settings]);

            return ['transferred' => $marks];
        });
    }

    /** Удалить тест вместе с попытками студентов. */
    public function destroy(Test $test)
    {
        if (! $test->isOwnedBy(CurrentUser::teacher())) {
            abort(403, 'Удалить тест может только его автор: '.$test->author?->name);
        }
        $test->delete();

        return response()->noContent();
    }

    /** Добавить вопросы из банка в тест (массовое действие в банке). */
    public function attachQuestions(Request $request, Test $test)
    {
        $this->onlyAuthor($test, 'Добавлять вопросы в тест');
        $data = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'string|max:20']);
        // Состав теста, по которому уже есть попытки, не меняем: иначе пересчитываются сданные результаты
        if ($test->attempts()->exists()) {
            return response()->json(['message' => 'Тест уже проходили — состав менять нельзя. Соберите новый тест'], 409);
        }
        // Только одобренные вопросы этого курса: неодобренный может быть с неверным ключом
        $questions = Question::whereIn('code', $data['ids'])->where('course_id', $test->course_id)->where('status', 'approved')->get();
        $added = DB::transaction(function () use ($test, $questions) {
            $have = $test->questions()->pluck('questions.id')->all();
            $position = (int) $test->questions()->max('position');
            $new = $questions->reject(fn ($q) => in_array($q->id, $have, true))->values();
            $test->questions()->attach($new->mapWithKeys(fn ($q) => [$q->id => ['position' => ++$position]])->all());

            return $new->count();
        });
        $skipped = count(array_unique($data['ids'])) - $questions->count();

        // Тест, собранный вручную, публикуется, как только в нём появились вопросы
        if ($test->status === 'draft' && $added && ($publishAs = $test->settings['publish_as'] ?? null)) {
            $test->update(['status' => $publishAs]);
        }

        return ['added' => $added, 'skipped' => $skipped, 'test' => $this->data->test($test->loadCount('questions'))];
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
