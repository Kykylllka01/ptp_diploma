<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\User;
use App\Services\SqlSandbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Проверка готовности перед тестированием со студентами: настройки, база, очередь, планировщик,
 * учебная база SQL и место на диске. Секреты не печатает — только «задан / не задан».
 * Код выхода 1, если есть хотя бы одна ошибка (FAIL).
 */
class PilotCheck extends Command
{
    protected $signature = 'pilot:check';

    protected $description = 'Проверить, готов ли сервер к тестированию с большим числом студентов';

    /** @var array<int, array{string, string, string}> */
    private array $rows = [];

    public function handle(SqlSandbox $sandbox): int
    {
        $this->check('Режим отладки', ! config('app.debug'), 'APP_DEBUG=true: студенты увидят трассировку ошибок. Поставьте APP_DEBUG=false', 'warn');
        $this->check('Окружение', app()->environment('production'), 'APP_ENV='.app()->environment().' — для пилота лучше production', 'warn');
        $this->check('Журнал', config('logging.channels.stack.channels') !== ['single'] || config('logging.default') !== 'stack',
            'журнал пишется в один растущий файл — LOG_STACK=daily', 'warn');

        try {
            DB::select('select 1');
            $this->check('База данных', true);
            $pending = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f, '.php'))
                ->diff(DB::table('migrations')->pluck('migration'));
            $this->check('Миграции', $pending->isEmpty(), 'не применены: '.$pending->implode(', ').' — php artisan migrate --force');
        } catch (Throwable $e) {
            report($e);
            $this->check('База данных', false, 'нет соединения (подробности — в журнале storage/logs)');
        }

        $this->check('Очередь', config('queue.default') === 'database', 'QUEUE_CONNECTION='.config('queue.default').': для оценки развёрнутых ответов в фоне нужен database');
        $php = config('agents.queue_autostart_php');
        $this->check('Запуск обработчиков очереди', $php && is_file($php),
            'QUEUE_AUTOSTART_PHP не задан или не найден — держите запущенным php artisan queue:work --timeout=1800', 'warn');
        $this->check('Параллельных обработчиков', true, '', 'ok', (string) config('agents.queue_max_workers'));
        try {
            $failed = DB::table('failed_jobs')->count();
            $stuck = DB::table('jobs')->whereNotNull('reserved_at')->where('reserved_at', '<', now()->subSeconds((int) config('queue.connections.database.retry_after', 1900))->getTimestamp())->count();
            $this->check('Упавшие задачи', $failed === 0, $failed.' в failed_jobs — php artisan queue:failed', 'warn');
            $this->check('Зависшие задачи', $stuck === 0, $stuck.' задач взяты давно и не завершены', 'warn');
        } catch (Throwable) {
            // Без таблиц очереди проверка миграций выше уже сообщила об ошибке
        }

        $beat = Cache::get('scheduler-heartbeat');
        $this->check('Планировщик', $beat && now()->getTimestamp() - $beat < 180,
            'schedule:run не запускался последние 3 минуты: брошенные попытки не будут досдаваться сами', 'warn');

        $driver = config('agents.driver');
        $this->check('Агенты', $driver !== 'llm' || filled(config('agents.llm.key')), 'AGENTS_DRIVER=llm, но ключ LLM_API_KEY не задан');
        $this->check('Режим агентов', true, '', 'ok', $driver === 'llm' ? 'языковая модель' : 'тестовые заглушки ('.$driver.')');

        $sql = $sandbox->run('SELECT count(*) FROM students');
        $this->check('Учебная база SQL', $sql['ok'], $sql['error'] ?? '');

        $dir = storage_path('app');
        $this->check('Папка storage', is_writable($dir), $dir.' недоступна для записи');
        $free = @disk_free_space($dir);
        $this->check('Свободно на диске', $free === false || $free > 2 * 1024 ** 3, 'меньше 2 ГБ', 'warn', $free ? round($free / 1024 ** 3, 1).' ГБ' : '');

        try {
            $teachers = User::where('role', User::ROLE_TEACHER)->count();
            $students = User::where('role', User::ROLE_STUDENT)->count();
            $groups = Group::count();
            $this->check('Пользователи', $teachers > 0, 'нет ни одного преподавателя — php artisan teacher:create', 'fail',
                $teachers.' преп., '.$students.' студ., '.$groups.' групп');
        } catch (Throwable) {
        }

        $this->table(['Проверка', 'Итог', 'Подробности'], $this->rows);
        $fails = collect($this->rows)->where(1, 'FAIL')->count();
        $warns = collect($this->rows)->where(1, 'WARN')->count();
        $fails ? $this->error("Ошибок: $fails, предупреждений: $warns") : $this->info($warns ? "Ошибок нет, предупреждений: $warns" : 'Всё готово');

        return $fails ? self::FAILURE : self::SUCCESS;
    }

    private function check(string $name, bool $ok, string $problem = '', string $level = 'fail', string $value = ''): void
    {
        $this->rows[] = [$name, $ok ? 'OK' : strtoupper($level), $ok ? $value : $problem];
    }
}
