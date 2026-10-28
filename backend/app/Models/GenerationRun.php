<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GenerationRun extends Model
{
    protected $fillable = [
        'course_id', 'user_id', 'status', 'params', 'total', 'written', 'checked', 'rejected',
        'disputed', 'catalog_progress', 'agent_states', 'state', 'error', 'started_at', 'finished_at',
    ];

    // Разборы материалов в state весят сотни килобайт — экрану «Работа агентов» они не нужны
    protected $hidden = ['state'];

    protected $casts = [
        'params' => 'array',
        'agent_states' => 'array',
        // Прогресс между частями запуска (GenerationPipeline::step): разборы, план, курсор, счётчики
        'state' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AgentLog::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function log(string $agent, string $message): void
    {
        $this->logs()->create(['agent' => $agent, 'message' => $message]);
    }

    /** @param array{0:string,1:string,2:string} $state [done|work|wait, подпись, текст] */
    public function setAgentState(string $agent, array $state): void
    {
        $states = $this->agent_states ?? [];
        $states[$agent] = $state;
        $this->agent_states = $states;
        $this->save();
    }
}
