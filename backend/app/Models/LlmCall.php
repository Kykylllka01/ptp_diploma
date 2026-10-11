<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Один вызов языковой модели агентом (журнал для расчёта расхода и экспериментов). */
class LlmCall extends Model
{
    protected $fillable = [
        'agent', 'model', 'prompt_version', 'generation_run_id', 'answer_id',
        'tokens_in', 'tokens_cached', 'tokens_out', 'tokens_reasoning', 'ms', 'cost_usd', 'status', 'request', 'response', 'error',
    ];
}
