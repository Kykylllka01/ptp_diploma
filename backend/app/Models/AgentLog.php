<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentLog extends Model
{
    protected $fillable = ['generation_run_id', 'agent', 'message'];

    /** Подписи агентов в журнале (как в прототипе — со строчной буквы). */
    public const LABELS = [
        'analyst' => 'аналитик',
        'writer' => 'составитель',
        'critic' => 'критик',
        'catalog' => 'каталогизатор',
        'grader' => 'оценщик',
        'examiner' => 'экзаменатор',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(GenerationRun::class, 'generation_run_id');
    }

    public function toLogEntry(): array
    {
        return [$this->agent, self::LABELS[$this->agent] ?? $this->agent, $this->message, $this->created_at?->format('H:i:s')];
    }
}
