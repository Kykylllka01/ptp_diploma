<?php

namespace App\Models;

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
