<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentLog;
use App\Models\Attempt;
use App\Models\GenerationRun;
use App\Models\Group;
use App\Models\Material;
use App\Models\Question;
use App\Models\Review;
use App\Models\Test;
use App\Models\User;
use App\Support\CurrentUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Собирает данные для страниц в той же форме, что была в mock-data.js (window.MOCK),
 * чтобы скрипты экранов работали без переделки. Попадает в шаблон как window.APP_DATA.
 */
class FrontendData
{
    public function __construct(private AnalyticsService $analytics)
    {
    }

    /**
     * Данные преподавателя для страницы. $only — какие части нужны (materials, agents, questions, groups,
     * participants, tests, reviews); null — все. Раньше каждая страница получала весь банк и все проверки.
     */
    public function teacher(?array $only = null): array
    {
        $course = CurrentUser::course();
        $want = fn (string $part) => $only === null || in_array($part, $only, true);
        $data = $this->base() + ['teacher' => $this->user(CurrentUser::teacher())];

        if ($want('materials')) {
            $data['materials'] = Material::where('course_id', $course->id)->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn ($m) => $this->material($m))->all();
        }
        if ($want('agents')) {
            $data['agents'] = Agent::orderBy('id')->whereIn('code', ['analyst', 'writer', 'critic', 'grader'])->get()
                ->map(fn ($a) => ['id' => $a->code, 'name' => $a->name, 'role' => $a->role, 'state' => $a->state, 'summary' => $a->summary])->all();
        }
        if ($want('questions')) {
            $data['questions'] = Question::where('course_id', $course->id)->orderBy('code')->get()->map(fn ($q) => $this->question($q))->all();
        }
        if ($want('groups')) {
            $data['groups'] = Group::where('course_id', $course->id)->withCount('students')->orderBy('name')->get()
                ->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'students' => $g->students_count])->all();
        }
        if ($want('participants')) {
            // Тест без группы открыт всем студентам курса — тогда участвуют все
            // Тест «для всех» открыт всем студентам курса — тогда участвуют все
            $live = Test::where('course_id', $course->id)->whereIn('status', ['running', 'training']);
            $data['participants'] = (clone $live)->where('audience', 'all')->exists()
                ? User::where('role', 'student')->count()
                : User::where('role', 'student')->whereIn('group_id',
                    DB::table('group_test')->whereIn('test_id', (clone $live)->select('id'))->select('group_id'))->count();
        }
        if ($want('tests')) {
            $data['tests'] = Test::where('course_id', $course->id)->with('groups')->withCount('questions')->orderBy('id')->get()->map(fn ($t) => $this->test($t))->all();
        }
        if ($want('reviews')) {
            // Очередь проверки: ответы этого курса, которые ждут подтверждения, с апелляцией
            // или подтверждены за последний месяц (для контекста), — а не все проверки за всё время
            $reviews = Review::with(['student', 'question'])
                ->whereHas('question', fn ($q) => $q->where('course_id', $course->id))
                ->where(fn ($q) => $q->where('status', 'pending')->orWhereNotNull('appealed_at')->orWhere('confirmed_at', '>=', now()->subDays(30)))
                ->orderBy('id')->get();
            $reviewQuestions = $reviews->pluck('question')->unique('id');
            $data['reviews'] = $reviews->map(fn ($r) => $this->review($r))->all();
            $data['criteria'] = $reviewQuestions->mapWithKeys(fn ($q) => [$q->code => $q->criteria ?? []])->all();
            $data['questionTexts'] = $reviewQuestions->mapWithKeys(fn ($q) => [$q->code => ['text' => $q->text, 'context' => $q->context, 'reference' => $q->reference]])->all();
        }

        return $data;
    }

    public function analytics(?Test $test = null): array
    {
        $test ??= Test::where('status', 'running')->first() ?? Test::first();

        return ['analytics' => $test ? $this->analytics->forTest($test) : null];
    }

    /** Стартовая страница «Аналитики»: курс целиком — темы, группы, трудные вопросы и тесты рядом. */
    public function analyticsTests(): array
    {
        return ['overview' => $this->analytics->forCourse(CurrentUser::course()->id)];
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
        $tests = Test::where('course_id', CurrentUser::course()->id)->visibleTo($student)
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
                $mine = $t->attempts()->where('user_id', $student->id)->orderByDesc('id')->get(['id', 'status', 'percent']);
                $my = $mine->first();
                $used = $mine->where('status', '!=', 'in_progress')->count();
                $row['my'] = $my ? ['status' => $my->status, 'percent' => $my->percent, 'attemptId' => $my->id,
                    'used' => $used, 'left' => max(0, (int) $t->attempts_allowed - $used)] : null;

                return $row;
            })->all(),
            'studentDone' => $done,
        ];
    }

    /** Вопросы попытки для экрана прохождения теста (без ключей). */
    public function attempt(Test $test, ?Attempt $attempt, ?\Illuminate\Support\Collection $questions = null): array
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
            'questions' => ($questions ?? $attempt?->questionList() ?? $test->questions)->map(fn ($q) => $this->studentQuestion($q))->values()->all(),
        ]];
    }

    /** Результат сданной попытки: здесь уже можно показать ключи и эталоны. */
    public function result(Attempt $attempt, bool $forTeacher = false): array
    {
        $attempt->load(['test.questions', 'answers.question', 'answers.review']);
        // Разбор с ответами — только когда пересдавать уже нельзя, иначе он подсказывает ответы для следующей попытки.
        // Преподаватель видит разбор и ключи всегда.
        $reviewAllowed = $forTeacher || $attempt->test->setting('show_review', true);
        $showReview = $forTeacher || ($reviewAllowed && $attempt->test->reviewOpenFor($attempt->user));
        // Вопрос из банка может стоять и в другом, ещё идущем тесте — его ключ остаётся скрытым
        $secret = $showReview && ! $forTeacher ? Test::secretQuestionIdsFor($attempt->user, $attempt->test) : [];
        $keyFor = fn (Question $q) => $showReview && ! in_array($q->id, $secret, true);
        $answers = $attempt->answers->keyBy(fn ($a) => $a->question->code);

        $results = [];
        $values = [];
        foreach ($attempt->questionList() as $q) {
            $a = $answers->get($q->code);
            $values[$q->code] = $a?->value;
            $results[$q->code] = [
                'score' => $this->num($a?->score ?? 0),
                'max' => $q->points,
                'final' => (bool) ($a?->is_final ?? true),
                // Пояснения к SQL сравнивают с эталоном («5 строк вместо 7») — это подсказка, пока разбор закрыт
                'criteria' => $q->type === 'open' || $keyFor($q) ? ($a?->criteria ?? []) : [],
                'answerId' => $a?->id,
                'canAppeal' => ! $forTeacher && $q->type === 'open' && $a?->review !== null,
                'appeal' => $a?->review?->appeal,
            ];
        }

        return [
            'attempt' => [
                'testId' => $attempt->test->code,
                'questions' => $attempt->questionList()
                    ->map(fn ($q) => $this->studentQuestion($q, $keyFor($q)) + ['keyHidden' => $showReview && ! $keyFor($q)])
                    ->values()->all(),
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
                'status' => $attempt->status,
                'points' => $attempt->test->points($attempt->exactPercent()),
                'weight' => $attempt->test->weight(),
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
        return ['id' => $u->id, 'name' => $u->name, 'firstName' => $u->full_name, 'initials' => $u->initials, 'group' => $u->group?->name, 'avatar' => $u->avatarUrl()];
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
            // Исходный файл или страница-источник: открывается из карточки материала
            'fileUrl' => $m->file_path || $m->url ? route('teacher.material.file', $m) : null,
            'isLink' => (bool) $m->url,
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
        $pool = $t->questions_count ?? $t->questions()->count();
        // У индивидуального теста к нему прикреплён пул, а студенту достаётся вариант по матрице
        $count = $t->isIndividual() ? min($pool, max(1, $t->variantSize())) : $pool;
        // По студенту — лучшая попытка: пересдачи не считаются новыми студентами и не тянут средний вниз
        $best = $t->attempts()->where('status', '!=', 'in_progress')->selectRaw('user_id, max(percent) as best')->groupBy('user_id')->pluck('best');
        $submitted = $best->count();
        $students = $t->participants()->count();
        $avg = $best->count() ? $best->avg() : null;
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
            // Сырые значения для сортировки в таблицах (подписи выше — для показа)
            'avgPercent' => $avg !== null && $t->status !== 'draft' ? (int) round($avg) : null,
            'students' => $students,
            'deadlineTs' => $t->deadline_at?->getTimestamp(),
            'createdTs' => $t->created_at?->getTimestamp(),
            'deadline' => $deadline,
            'minutes' => $t->minutes,
            'attempts' => $t->attempts_allowed,
            'open' => $openCount,
            'graded' => $t->graded,
            'weight' => $t->weight(),
            'individual' => $t->isIndividual(),
            'pool' => $pool,
            'isOpen' => $t->isOpenNow(),
            'mode' => $t->mode,
            'group' => $t->groupLabel(),
            'groups' => $t->forAll() ? [] : $t->groups->pluck('id')->all(),
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

    /** Экран «Группы»: группы курса со ссылками-приглашениями и студентами, плюс студенты без группы. */
    public function groupsPage(): array
    {
        $course = CurrentUser::course();
        $groups = Group::where('course_id', $course->id)->withCount('tests')->with(['students' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')->get();
        $loose = User::where('role', User::ROLE_STUDENT)->whereNull('group_id')->orderBy('name')->get();

        return [
            'groups' => $groups->map(fn ($g) => $this->group($g))->all(),
            'ungrouped' => $loose->map(fn ($u) => $this->groupStudent($u))->all(),
        ];
    }

    public function group(Group $g): array
    {
        return [
            'id' => $g->id,
            'name' => $g->name,
            'code' => $g->invite_code,
            'url' => $g->inviteUrl(),
            'open' => $g->invite_open,
            'tests' => $g->tests_count ?? $g->tests()->count(),
            'students' => ($g->relationLoaded('students') ? $g->students : $g->students()->orderBy('name')->get())
                ->map(fn ($u) => $this->groupStudent($u))->values()->all(),
        ];
    }

    private function groupStudent(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'since' => $this->date($u->created_at),
            'initials' => $u->initials, 'avatar' => $u->avatarUrl()];
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
