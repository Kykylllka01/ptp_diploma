<?php

namespace App\Jobs;

use App\Models\GenerationRun;
use App\Services\GenerationPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Запуск конвейера агентов в очереди. При QUEUE_CONNECTION=sync выполняется сразу
 * в запросе (удобно для заготовок). Для настоящей нейросети переключите очередь на
 * database и запустите воркер: php artisan queue:work
 */
class RunGenerationPipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(public GenerationRun $run)
    {
    }

    public function handle(GenerationPipeline $pipeline): void
    {
        $pipeline->run($this->run);
    }
}
