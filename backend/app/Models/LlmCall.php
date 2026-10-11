<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Один вызов языковой модели агентом (журнал для расчёта расхода и экспериментов). */
class LlmCall extends Model
{
    protected $fillable = [
        'agent', 'model', 'prompt_version', 'generation_run_id', 'answer_id',
        'tokens_in', 'tokens_out', 'tokens_reasoning', 'ms', 'status', 'request', 'response', 'error',
    ];
}
