<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Запуск обработчика очереди из веб-запроса, чтобы на локальном OSPanel не держать
 * открытым окно с queue:work. Обработчик стартует в фоне с --stop-when-empty
 * и сам завершается, когда задачи кончатся. Включается путём к php.exe
 * в QUEUE_AUTOSTART_PHP; без него очередь нужно запускать вручную.
 */
class QueueWorker
{
    public static function ensureRunning(): void
    {
        $php = config('agents.queue_autostart_php');
        if (! $php || config('queue.default') !== 'database' || ! is_file($php)) {
            return;
        }
        // Обработчик уже взял задачу или его только что запустили — второй не нужен
        if (! Cache::add('queue-worker-kick', true, 15)) {
            return;
        }
        if (DB::table('jobs')->whereNotNull('reserved_at')->exists() && ! DB::table('jobs')->whereNull('reserved_at')->exists()) {
            return;
        }

        $cmd = '"'.$php.'" "'.base_path('artisan').'" queue:work --stop-when-empty --tries=1 --timeout=1800';
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                pclose(popen('start "" /B '.$cmd.' > NUL 2>&1', 'r'));
            } else {
                exec($cmd.' > /dev/null 2>&1 &');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
