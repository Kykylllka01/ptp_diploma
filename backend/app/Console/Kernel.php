<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Брошенные попытки досдаются и без захода студента или преподавателя на страницы
        $schedule->command('attempts:finalize')->everyMinute()->withoutOverlapping();
        // Обработчик очереди упал посреди потока сдач, а новых сдач нет — задачи не должны ждать вечно
        // Заодно отметка «планировщик жив» для php artisan pilot:check
        $schedule->call(function () {
            \Illuminate\Support\Facades\Cache::put('scheduler-heartbeat', now()->getTimestamp(), 600);
            \App\Support\QueueWorker::ensureRunning();
        })->name('queue:kick')->everyMinute();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
