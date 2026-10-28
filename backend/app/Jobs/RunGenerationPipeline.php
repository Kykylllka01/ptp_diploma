<?php

namespace App\Jobs;

use App\Models\GenerationRun;
use App\Services\GenerationPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Запуск конвейера агентов в очереди. При QUEUE_CONNECTION=sync выполняется сразу
 * в запросе (удобно для заготовок). Для настоящей нейросети переключите очередь на
 * database и запустите воркер: php artisan queue:work
 *
 * Запуск идёт частями (GenerationPipeline::step): задача берёт новые части несколько минут,
 * а остаток передаёт следующей задаче очереди. Так запуск на сотни вопросов не упирается
 * в предел времени одной задачи, и обрыв стоит только текущей части.
 */
class RunGenerationPipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Предел одной задачи с запасом: новые части берутся только первые agents.part_seconds,
    // а одна часть (порция из 6 вопросов с доработками) идёт минуты
    public int $timeout = 1800;

    public function __construct(public GenerationRun $run)
    {
    }

    public function handle(GenerationPipeline $pipeline): void
    {
        $started = microtime(true);
        while ($pipeline->step($this->run)) {
            // Синхронная очередь (заготовки, автотесты) — весь запуск сразу
            if (! $this->job || $this->job instanceof SyncJob) {
                continue;
            }
            if (microtime(true) - $started >= (int) config('agents.part_seconds', 480)) {
                self::dispatch($this->run);

                return;
            }
        }
    }

    /**
     * Задача упала или убита по таймауту — запуск не должен навсегда остаться «в работе»:
     * экран «Работа агентов» покажет ошибку, материалы вернутся в обычный статус.
     */
    public function failed(?Throwable $e): void
    {
        GenerationPipeline::markFailed($this->run, $e?->getMessage() ?: 'Задача генерации прервана');
    }
}
