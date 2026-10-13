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
 * Пока ждут задачи, запускается до QUEUE_MAX_WORKERS обработчиков (по умолчанию 3):
 * поток сдач с развёрнутыми ответами оценивается параллельно.
 */
class QueueWorker
{
    public static function ensureRunning(): void
    {
        $php = config('agents.queue_autostart_php');
        if (! $php || config('queue.default') !== 'database' || ! is_file($php)) {
            return;
        }
        // Не чаще раза в 5 секунд: при потоке сдач проверка не должна идти на каждый запрос
        if (! Cache::add('queue-worker-kick', true, 5)) {
            return;
        }
        // Ждущих задач нет — все уже у обработчиков
        if (! DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', now()->getTimestamp())->exists()) {
            return;
        }
        // Занятые обработчики (по взятым задачам) плюс только что запущенные, ещё не взявшие задачу
        $busy = DB::table('jobs')->whereNotNull('reserved_at')->count();
        $starting = (int) Cache::get('queue-worker-starting', 0);
        if ($busy + $starting >= (int) config('agents.queue_max_workers', 3)) {
            return;
        }
        Cache::put('queue-worker-starting', $starting + 1, 20);

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
