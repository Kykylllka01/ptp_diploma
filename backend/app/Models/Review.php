<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Развёрнутый ответ, предварительно оценённый агентом и ждущий подтверждения преподавателя. */
class Review extends Model
{
    protected $fillable = [
        'answer_id', 'question_id', 'student_id', 'answer_html', 'confidence', 'minutes', 'agent_scores',
        'scores', 'final_score', 'comment', 'appeal', 'appealed_at', 'status', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = [
        'agent_scores' => 'array',
        'scores' => 'array',
        'confirmed_at' => 'datetime',
        'appealed_at' => 'datetime',
    ];

    public function answer(): BelongsTo
    {
        return $this->belongsTo(Answer::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
