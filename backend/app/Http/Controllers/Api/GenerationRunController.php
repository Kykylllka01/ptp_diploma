<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunGenerationPipeline;
use App\Models\GenerationRun;
use App\Models\Material;
use App\Models\Question;
use App\Services\FrontendData;
use App\Services\GenerationPipeline;
use App\Support\CurrentUser;
use App\Support\QueueWorker;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class GenerationRunController extends Controller
{
    public function __construct(private FrontendData $data)
    {
    }

    /** Запуск агентов по выбранным материалам. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'material_ids' => 'required|array|min:1',
            'material_ids.*' => 'integer|exists:materials,id',
            'types' => 'required|array|min:1',
            'types.*' => Rule::in(array_keys(Question::TYPES)),
            'count' => 'required|integer|min:1|max:300',
            'difficulty' => ['required', Rule::in(['mixed', 'easy', 'medium', 'hard'])],
            'bloom' => 'nullable|string|max:100',
            'lang' => 'nullable|string|max:10',
            'notes' => 'nullable|string|max:2000',
        ]);

        $ready = Material::whereIn('id', $data['material_ids'])->where('has_text', true)->count();
        if (! $ready) {
            return response()->json(['message' => 'Нет материалов с распознанным текстом'], 422);
        }

        $run = GenerationRun::create([
            'course_id' => CurrentUser::course()->id,
            'user_id' => CurrentUser::teacher()->id,
            'status' => 'queued',
            'params' => $data,
        ]);

        try {
            RunGenerationPipeline::dispatch($run);
        } catch (Throwable $e) {
            // При синхронной очереди конвейер уже записал failed сам; при сбое постановки — записываем здесь
            report($e);
            GenerationPipeline::markFailed($run, 'Не удалось запустить агентов: '.$e->getMessage());
        }
        try {
            QueueWorker::ensureRunning();
        } catch (Throwable $e) {
            report($e);
        }

        return $this->data->run($run->fresh());
    }

    /** «Остановить»: конвейер завершится после текущего шага, готовые вопросы останутся в банке. */
    public function cancel(GenerationRun $run)
    {
        if (in_array($run->status, ['queued', 'running'], true)) {
            $run->update(['status' => 'cancelling']);
            $run->log('analyst', 'преподаватель остановил запуск, агенты закончат текущий шаг');
        }

        return $this->data->run($run->fresh());
    }

    /** Состояние конвейера для экрана «Работа агентов» (опрос раз в пару секунд). */
    public function show(GenerationRun $run)
    {
        return $this->data->run($run);
    }
}
