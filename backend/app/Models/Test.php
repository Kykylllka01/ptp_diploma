<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Test extends Model
{
    protected $fillable = [
        'code', 'course_id', 'title', 'status', 'mode', 'audience', 'minutes', 'attempts_allowed',
        'graded', 'opens_at', 'deadline_at', 'about', 'settings',
    ];

    protected $casts = [
        'graded' => 'boolean',
        'opens_at' => 'datetime',
        'deadline_at' => 'datetime',
        'settings' => 'array',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** Тест идёт и сейчас внутри окна «открыт с — закрывается». */
    public function isOpenNow(): bool
    {
        return $this->status === 'running'
            && ($this->opens_at === null || $this->opens_at->isPast())
            && ($this->deadline_at === null || $this->deadline_at->isFuture());
    }

    /** Группы-участники; используется, когда audience = groups. */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_test');
    }

    /** Для всех студентов курса или только для отмеченных групп (пустой список групп не значит «всем»). */
    public function forAll(): bool
    {
        return $this->audience !== 'groups';
    }

    /** Тесты, которые студент видит: не черновики, для всех или для его группы. */
    public function scopeVisibleTo(Builder $query, User $student): Builder
    {
        return $query->where('status', '!=', 'draft')
            ->where(fn ($q) => $q->where('audience', 'all')
                ->orWhereHas('groups', fn ($g) => $g->where('student_groups.id', $student->group_id ?? 0)));
    }

    /** Студенты, которым тест назначен. */
    public function participants(): Builder
    {
        return User::where('role', User::ROLE_STUDENT)
            ->when(! $this->forAll(), fn ($q) => $q->whereIn('group_id', $this->groups->pluck('id')));
    }

    /** Подпись участников: «Группа 1, Группа 2» или null — все студенты курса. */
    public function groupLabel(): ?string
    {
        return $this->forAll() ? null : ($this->groups->pluck('name')->sort()->implode(', ') ?: 'группы удалены');
    }

    /**
     * Можно ли студенту видеть правильные ответы этого теста: в тренировке всегда, в контрольном —
     * когда попытки кончились или приём закрыт, и у студента нет незаконченной попытки
     * (иначе разбор первой попытки подсказывает ответы для следующей).
     */
    public function reviewOpenFor(User $student): bool
    {
        if ($this->status === 'training') {
            return true;
        }
        $mine = $this->attempts()->where('user_id', $student->id);
        if ((clone $mine)->where('status', 'in_progress')->exists()) {
            return false;
        }
        // Срок — с той же минутой запаса, что и приём ответов (Attempt::isOverdue)
        if ($this->status === 'closed' || $this->deadline_at?->copy()->addMinute()->isPast()) {
            return true;
        }

        return $mine->count() >= max(1, (int) $this->attempts_allowed);
    }

    /**
     * Ответы на вопросы этого теста пока нельзя раскрывать студенту ни здесь, ни через другие тесты
     * с теми же вопросами: черновик (ещё не опубликован) или идущий тест, по которому разбор
     * для студента закрыт — в том числе до opens_at и для тестов чужих групп.
     */
    public function keepsAnswersFrom(User $student): bool
    {
        return $this->status === 'draft' || ($this->status === 'running' && ! $this->reviewOpenFor($student));
    }

    /**
     * id вопросов, ответы на которые студенту пока нельзя показывать (см. keepsAnswersFrom).
     * Один вопрос банка часто входит в несколько тестов, поэтому проверяется каждый.
     *
     * @return int[]
     */
    public static function secretQuestionIdsFor(User $student, ?self $except = null): array
    {
        return self::whereIn('status', ['draft', 'running'])
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->with('questions:id')->get()
            ->filter(fn (self $t) => $t->keepsAnswersFrom($student))
            ->flatMap(fn (self $t) => $t->questions->pluck('id'))
            ->unique()->values()->all();
    }

    /** Каждому студенту свой набор вопросов из прикреплённого пула по матрице «тип × сложность». */
    public function isIndividual(): bool
    {
        return $this->setting('individual');
    }

    /**
     * Жёсткий прокторинг: уход со страницы теста прячет вопросы и записывается в журнал попытки.
     * В тренировке попыток нет — там прокторинг не действует.
     */
    public function isProctored(): bool
    {
        return $this->status !== 'training' && $this->setting('proctoring');
    }

    /** Сколько вопросов в одном варианте: сумма матрицы (лёгких + средних + сложных по каждому типу). */
    public function variantSize(): int
    {
        return (int) array_sum(array_map(fn ($r) => (int) ($r[2] ?? 0) + (int) ($r[3] ?? 0) + (int) ($r[4] ?? 0), $this->settings['matrix'] ?? []));
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class)->withPivot('position')->orderByPivot('position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }

    /** Настройки проведения: shuffle — перемешивать, show_review — разбор после сдачи, auto_grade — автооценка открытых. */
    /** Вес теста в баллах БРС (сколько баллов за 100 %); null — вес не задан, в журнале проценты. */
    public function weight(): ?float
    {
        $w = $this->settings['weight'] ?? null;

        return $w === null || $w === '' ? null : (float) $w;
    }

    /** Баллы для Модеуса за процент выполнения: линейно от веса, до десятых. */
    public function points(float $percent): ?float
    {
        $w = $this->weight();

        return $w === null ? null : round($percent * $w / 100, 1);
    }

    public function setting(string $key, bool $default = false): bool
    {
        return (bool) ($this->settings[$key] ?? $default);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
