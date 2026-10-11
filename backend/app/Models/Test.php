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
        'code', 'course_id', 'title', 'status', 'mode', 'group_name', 'minutes', 'attempts_allowed',
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

    /** Тесты, которые студент видит: не черновики, своей группы или без группы. */
    public function scopeVisibleTo(Builder $query, User $student): Builder
    {
        return $query->where('status', '!=', 'draft')
            ->where(fn ($q) => $q->whereNull('group_name')->orWhere('group_name', $student->group_name));
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
        // Срок — с той же минутой запаса, что и приём ответов (AttemptController::timeIsUp)
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

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class)->withPivot('position')->orderByPivot('position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }

    /** Настройки проведения: shuffle — перемешивать, show_review — разбор после сдачи, auto_grade — автооценка открытых. */
    public function setting(string $key, bool $default = false): bool
    {
        return (bool) ($this->settings[$key] ?? $default);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
