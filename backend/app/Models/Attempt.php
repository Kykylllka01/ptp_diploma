<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attempt extends Model
{
    protected $fillable = [
        'test_id', 'user_id', 'group_id', 'status', 'flags', 'question_ids', 'current_index', 'score', 'max_score', 'percent',
        'started_at', 'submitted_at',
    ];

    protected $casts = [
        'flags' => 'array',
        'question_ids' => 'array',
        'score' => 'float',
        'max_score' => 'float',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Группа студента на момент старта: перевод в другую группу не переносит старые результаты. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    /**
     * Ответы больше не принимаются: вышло время попытки или срок сдачи (с минутой запаса на сеть),
     * либо преподаватель закрыл приём. Такую попытку можно только сдать с тем, что уже сохранено.
     */
    public function isOverdue(): bool
    {
        $test = $this->test;
        $minutes = (int) $test->minutes;

        return $test->status !== 'running'
            || ($minutes > 0 && $this->started_at && $this->started_at->copy()->addMinutes($minutes + 1)->isPast())
            || ($test->deadline_at && $test->deadline_at->copy()->addMinute()->isPast());
    }

    /**
     * Вопросы этой попытки в порядке показа: свой вариант из пула теста (индивидуальный тест)
     * или все вопросы теста. Пул загружается один раз — набор берётся из него, без лишних запросов.
     */
    public function questionList(): \Illuminate\Support\Collection
    {
        $pool = $this->test->questions;
        if (empty($this->question_ids)) {
            return $pool;
        }
        $byId = $pool->keyBy('id');

        return collect($this->question_ids)->map(fn ($id) => $byId->get($id))->filter()->values();
    }

    public function isSubmitted(): bool
    {
        return $this->status !== 'in_progress';
    }

    public function minutesSpent(): int
    {
        $end = $this->submitted_at ?? now();

        return max(1, (int) round($this->started_at->diffInSeconds($end) / 60));
    }
}
