<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentLog;
use App\Models\Attempt;
use App\Models\GenerationRun;
use App\Models\Material;
use App\Models\Question;
use App\Models\Review;
use App\Models\Test;
use App\Models\User;
use App\Support\CurrentUser;
use Carbon\Carbon;

/**
 * Собирает данные для страниц в той же форме, что была в mock-data.js (window.MOCK),
 * чтобы скрипты экранов работали без переделки. Попадает в шаблон как window.APP_DATA.
 */
class FrontendData
{
    public function __construct(private AnalyticsService $analytics)
    {
    }

    /** Общие данные преподавателя: банк, материалы, тесты, агенты, очередь проверки. */
    public function teacher(): array
    {
        $course = CurrentUser::course();
        $teacher = CurrentUser::teacher();
        $questions = Question::where('course_id', $course->id)->orderBy('code')->get();

        $reviews = Review::with(['student', 'question'])->orderBy('id')->get();
        $reviewQuestions = $reviews->pluck('question')->unique('id');

        return $this->base() + [
            'teacher' => $this->user($teacher),
            'materials' => Material::where('course_id', $course->id)->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn ($m) => $this->material($m))->all(),
            'agents' => Agent::orderBy('id')->whereIn('code', ['analyst', 'writer', 'critic', 'grader'])->get()
                ->map(fn ($a) => ['id' => $a->code, 'name' => $a->name, 'role' => $a->role, 'state' => $a->state, 'summary' => $a->summary])->all(),
            'questions' => $questions->map(fn ($q) => $this->question($q))->all(),
            // Студенты групп, которым открыт хотя бы один идущий тест или тренировка
            'groups' => User::where('role', 'student')->whereNotNull('group_name')->distinct()->orderBy('group_name')->pluck('group_name')->all(),
            'participants' => User::where('role', 'student')->whereIn('group_name',
                Test::where('course_id', $course->id)->whereIn('status', ['running', 'training'])->whereNotNull('group_name')->pluck('group_name'))->count(),
            'tests' => Test::where('course_id', $course->id)->withCount('questions')->orderBy('id')->get()->map(fn ($t) => $this->test($t))->all(),
            'reviews' => $reviews->map(fn ($r) => $this->review($r))->all(),
            'criteria' => $reviewQuestions->mapWithKeys(fn ($q) => [$q->code => $q->criteria ?? []])->all(),
            'questionTexts' => $reviewQuestions->mapWithKeys(fn ($q) => [$q->code => ['text' => $q->text, 'context' => $q->context, 'reference' => $q->reference]])->all(),
        ];
    }

    public function analytics(?Test $test = null): array
    {
        $test ??= Test::where('status', 'running')->first() ?? Test::first();

        return ['analytics' => $test ? $this->analytics->forTest($test) : null];
    }

    public function pipeline(?GenerationRun $run): array
    {
        return ['run' => $run ? $this->run($run) : null];
    }

    /** Данные студента: без верных ответов и эталонов. */
    public function student(): array
    {
        $student = CurrentUser::student();
        // Тесты своей группы и открытые всем по ссылке; черновики студент не видит
        $tests = Test::where('course_id', CurrentUser::course()->id)->where('status', '!=', 'draft')
            ->where(fn ($q) => $q->whereNull('group_name')->orWhere('group_name', $student->group_name))
            ->withCount('questions')->orderBy('id')->get();

        $done = Attempt::with('test')->where('user_id', $student->id)->where('status', '!=', 'in_progress')
            ->orderByDesc('submitted_at')->get()
            ->map(fn (Attempt $a) => [
                'attemptId' => $a->id,
                'title' => $a->test->title,
                'meta' => ($a->test->status === 'training' ? 'Тренировка · ' : 'Сдан ').$a->submitted_at?->translatedFormat('j F'),
                'status' => $a->test->status === 'training' ? ['Без оценки', 'gray'] : ($a->status === 'confirmed' ? ['Итог подтверждён', 'green'] : ['Предварительно', 'amber']),
                'score' => $a->test->status === 'training' ? $this->num($a->score).'/'.$this->num($a->max_score) : $a->percent.'%',
            ])->all();

        return $this->base() + [
            'student' => $this->user($student),
            'tests' => $tests->map(function (Test $t) use ($student) {
                $row = $this->test($t);
                $my = $t->attempts()->where('user_id', $student->id)->latest('id')->first();
                $row['my'] = $my ? ['status' => $my->status, 'percent' => $my->percent, 'attemptId' => $my->id] : null;

                return $row;
            })->all(),
            'studentDone' => $done,
        ];
    }

    /** Вопросы попытки для экрана прохождения теста (без ключей). */
    public function attempt(Test $test, ?Attempt $attempt): array
    {
        $answers = $attempt ? $attempt->answers()->with('question')->get()->mapWithKeys(fn ($a) => [$a->question->code => $a->value])->all() : [];

        return ['attempt' => [
            'testId' => $test->code,
            'title' => $test->title,
            'attemptId' => $attempt?->id,
            'minutes' => $test->minutes,
            'shuffle' => $test->setting('shuffle', true),
            'startedAt' => $attempt?->started_at?->getTimestampMs() ?? now()->getTimestampMs(),
            'serverNow' => now()->getTimestampMs(),
            'answers' => (object) $answers,
            'flags' => (object) array_fill_keys($attempt?->flags ?? [], true),
            'current' => $attempt?->current_index ?? 0,
            'questions' => $test->questions->map(fn ($q) => $this->studentQuestion($q))->values()->all(),
        ]];
    }

    /** Результат сданной попытки: здесь уже можно показать ключи и эталоны. */
    public function result(Attempt $attempt): array
    {
        $attempt->load(['test.questions', 'answers.question', 'answers.review']);
        // Разбор с ответами — только когда пересдавать уже нельзя, иначе он подсказывает ответы для следующей попытки
        $reviewAllowed = $attempt->test->setting('show_review', true);
        $showReview = $reviewAllowed && $attempt->test->reviewOpenFor($attempt->user);
        $answers = $attempt->answers->keyBy(fn ($a) => $a->question->code);

        $results = [];
        $values = [];
        foreach ($attempt->test->questions as $q) {
            $a = $answers->get($q->code);
            $values[$q->code] = $a?->value;
            $results[$q->code] = [
                'score' => $this->num($a?->score ?? 0),
                'max' => $q->points,
                'final' => (bool) ($a?->is_final ?? true),
                'criteria' => $a?->criteria ?? [],
                'answerId' => $a?->id,
                'canAppeal' => $q->type === 'open' && $a?->review !== null,
                'appeal' => $a?->review?->appeal,
            ];
        }

        return [
            'attempt' => [
                'testId' => $attempt->test->code,
                'questions' => $attempt->test->questions->map(fn ($q) => $this->studentQuestion($q, $showReview))->values()->all(),
            ],
            'result' => [
                'attemptId' => $attempt->id,
                'testTitle' => $attempt->test->title,
                'minutes' => $attempt->minutesSpent(),
                'percent' => (int) $attempt->percent,
                'score' => $this->num($attempt->score),
                'max' => $this->num($attempt->max_score),
                'answers' => (object) $values,
                'results' => (object) $results,
                'showReview' => $showReview,
                'reviewNote' => $reviewAllowed && ! $showReview
                    ? 'Разбор с правильными ответами откроется, когда закончатся попытки или тест закроется.'
                    : null,
            ],
        ];
    }

    // ---------------------------------------------------------------- формы данных

    private function base(): array
    {
        $course = CurrentUser::course();

        return [
            'course' => ['id' => $course->id, 'title' => $course->title, 'semester' => $course->semester],
            'types' => Question::TYPES,
            'difficulties' => Question::DIFFICULTIES,
            'topics' => $course->topics ?? [],
        ];
    }

    private function user(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'firstName' => $u->full_name, 'initials' => $u->initials, 'group' => $u->group_name];
    }

    public function material(Material $m): array
    {
        return [
            'id' => $m->id,
            'title' => $m->title,
            'kind' => $m->kind,
            'meta' => $m->meta ?? '',
            'date' => $this->date($m->created_at),
            'status' => $m->status,
            'hasText' => $m->has_text,
            'size' => $m->size_bytes ? str_replace('.', ',', number_format($m->size_bytes / 1048576, 1)).' МБ' : null,
        ];
    }

    public function question(Question $q): array
    {
        return [
            'id' => $q->code,
            'type' => $q->type,
            'difficulty' => $q->difficulty,
            'topic' => $q->topic,
            'materialId' => $q->material_id,
            'source' => $q->source,
            'points' => $q->points,
            'status' => $q->status,
            'flag' => $q->flag_text ? ['text' => $q->flag_text, 'level' => $q->flag_level] : null,
            'text' => $q->text,
            'context' => $q->context,
            'options' => $q->options,
            'explanation' => $q->explanation,
            'reference' => $q->reference,
            'criteria' => $q->criteria,
            'sqlSchema' => $q->sql_schema,
            'tags' => $q->tags,
            'critic' => $q->critic,
            'fixApplied' => $q->fix_applied,
            'quote' => $q->quote,
            'history' => $q->history,
        ];
    }

    private function studentQuestion(Question $q, bool $withKey = false): array
    {
        $row = [
            'id' => $q->code,
            'type' => $q->type,
            'points' => $q->points,
            'topic' => $q->topic,
            'text' => $q->text,
            'context' => $q->context,
            'ref' => $q->source,
        ];
        if ($q->type === 'match') {
            $parts = $q->matchParts();
            $row['terms'] = $parts['terms'];
            $row['definitions'] = $parts['definitions'];
        } elseif ($q->options) {
            $row['options'] = array_map(fn ($o) => $o['text'], $q->options);
        }
        if ($q->type === 'sql') {
            $row['schema'] = $q->sql_schema ?: app(SqlSandbox::class)->schema();
            $row['placeholder'] = 'SELECT ...';
        }
        if ($q->type === 'open') {
            $row['criteria'] = $q->criteria ?? [];
        }
        if ($withKey) {
            $row['correct'] = $q->type === 'match' ? $q->matchParts()['key'] : $q->correctIndexes();
            $row['explanation'] = $q->explanation;
            $row['reference'] = $q->reference;
        }

        return $row;
    }

    public function test(Test $t): array
    {
        $count = $t->questions_count ?? $t->questions()->count();
        $submitted = $t->attempts()->where('status', '!=', 'in_progress')->count();
        // Без группы тест открыт всем студентам курса
        $students = User::where('role', 'student')->when($t->group_name, fn ($q) => $q->where('group_name', $t->group_name))->count();
        $avg = $t->attempts()->where('status', '!=', 'in_progress')->avg('percent');
        $openCount = $t->questions()->where('type', 'open')->count();

        $statusLabel = match ($t->status) {
            'running' => 'Идёт'.($t->deadline_at ? ' · до '.$t->deadline_at->translatedFormat('j M') : ''),
            'training' => 'Тренировка',
            'closed' => 'Приём закрыт',
            default => 'Черновик',
        };

        $deadline = match (true) {
            $t->status === 'running' && $t->opens_at !== null && $t->opens_at->isFuture() => 'Откроется '.$t->opens_at->translatedFormat('j F в H:i'),
            $t->status === 'running' && $t->deadline_at !== null && $t->deadline_at->isPast() => 'Срок сдачи прошёл',
            $t->status === 'running' && $t->deadline_at !== null => 'До '.$t->deadline_at->translatedFormat('j F').' · осталось '.$this->daysLeft($t->deadline_at),
            $t->status === 'draft' && $t->opens_at !== null => 'Откроется '.$t->opens_at->translatedFormat('j F'),
            $t->status === 'draft' => 'Ещё не опубликован',
            default => '',
        };

        return [
            'id' => $t->code,
            'title' => $t->title,
            'count' => $count,
            'status' => $t->status,
            'statusLabel' => $statusLabel,
            'passed' => $t->status === 'draft' ? '—' : ($t->status === 'training' ? (string) $submitted : $submitted.' / '.max($students, $submitted)),
            'avg' => $avg !== null && $t->status !== 'draft' ? round($avg).'%' : '—',
            'deadline' => $deadline,
            'minutes' => $t->minutes,
            'attempts' => $t->attempts_allowed,
            'open' => $openCount,
            'graded' => $t->graded,
            'isOpen' => $t->isOpenNow(),
            'mode' => $t->mode,
            'group' => $t->group_name,
            'submitted' => $submitted,
            'settings' => [
                'shuffle' => $t->setting('shuffle', true),
                'showReview' => $t->setting('show_review', true),
                'autoGrade' => $t->setting('auto_grade'),
            ],
            'about' => $t->about,
        ];
    }

    public function review(Review $r): array
    {
        return [
            'id' => $r->id,
            'student' => $r->student->name,
            'questionId' => $r->question->code,
            'confidence' => $r->confidence,
            'minutes' => $r->minutes,
            'status' => $r->status,
            'answer' => $r->answer_html,
            'agent' => $r->agent_scores,
            'scores' => $r->scores,
            'comment' => $r->comment,
            'appeal' => $r->appeal,
            'final' => $r->final_score,
        ];
    }

    public function run(GenerationRun $run): array
    {
        $fresh = $run->questions()->latest('id')->take(6)->get()->reverse()->values();

        return [
            'id' => $run->id,
            'status' => $run->status,
            'total' => $run->total,
            'written' => $run->written,
            'checked' => $run->checked,
            'rejected' => $run->rejected,
            'disputed' => $run->disputed,
            'catalog' => $run->catalog_progress,
            'states' => $run->agent_states ?? (object) [],
            'error' => $run->error,
            'log' => $run->logs()->orderBy('id')->get()->map(fn (AgentLog $l) => $l->toLogEntry())->all(),
            'fresh' => $fresh->map(fn ($q) => $this->question($q))->all(),
        ];
    }

    private function date(?Carbon $d): string
    {
        if (! $d) {
            return '';
        }

        return $d->isToday() ? 'сегодня' : ($d->isYesterday() ? 'вчера' : $d->translatedFormat('j F'));
    }

    private function daysLeft(Carbon $deadline): string
    {
        $n = max(0, (int) ceil(now()->diffInHours($deadline, false) / 24));

        return $n.' '.$this->plural($n, 'день', 'дня', 'дней');
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        $m10 = $n % 10;
        $m100 = $n % 100;
        if ($m10 === 1 && $m100 !== 11) {
            return $one;
        }
        if ($m10 >= 2 && $m10 <= 4 && ($m100 < 10 || $m100 >= 20)) {
            return $few;
        }

        return $many;
    }

    private function num(float|int|null $v): float|int
    {
        $v = (float) $v;

        return floor($v) == $v ? (int) $v : round($v, 1);
    }
}
