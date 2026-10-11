<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Answer extends Model
{
    protected $fillable = ['attempt_id', 'question_id', 'value', 'score', 'max_score', 'is_final', 'criteria'];

    protected $casts = [
        'value' => 'array',
        'criteria' => 'array',
        'score' => 'float',
        'max_score' => 'float',
        'is_final' => 'boolean',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }
}
