<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attempt extends Model
{
    protected $fillable = [
        'test_id', 'user_id', 'status', 'flags', 'current_index', 'score', 'max_score', 'percent',
        'started_at', 'submitted_at',
    ];

    protected $casts = [
        'flags' => 'array',
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

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
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
