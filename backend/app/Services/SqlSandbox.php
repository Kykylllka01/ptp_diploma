<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Учебная база для заданий на SQL: SQLite в памяти, создаётся заново на каждый запрос,
 * поэтому студент ничего не может в ней испортить. Разрешён только один SELECT.
 *
 * Запрос выполняется в отдельном процессе PHP (resources/sandbox/sql-runner.php) с таймаутом:
 * внутри SQLite PHP выполнение не прерывает, и запрос вроде WITH RECURSIVE без условия
 * остановки или соединение пяти таблиц занимал бы поток Apache бесконечно.
 */
class SqlSandbox
{
    private const MAX_ROWS = 200;

    /** Функции, которыми можно быстро съесть память. */
    private const FORBIDDEN = '/\b(recursive|randomblob|zeroblob)\b/i';

    /** @return array{ok: bool, error?: string, columns?: string[], rows?: array, ms?: int} */
    public function run(string $sql): array
    {
        $sql = trim($sql);
        $body = rtrim($sql, "; \n\r\t");

        if (! preg_match('/^(select|with)\b/i', $body)) {
            return ['ok' => false, 'error' => 'На учебной базе можно выполнять только SELECT'];
        }
        if (str_contains($body, ';')) {
            return ['ok' => false, 'error' => 'Можно выполнить только один запрос'];
        }
        if (preg_match(self::FORBIDDEN, $body, $m)) {
            return ['ok' => false, 'error' => 'На учебной базе нельзя использовать '.strtoupper($m[1])];
        }

        $timeout = (float) config('agents.sql_sandbox_timeout', 3);
        $process = new Process([$this->php(), '-d', 'memory_limit=128M', resource_path('sandbox/sql-runner.php')]);
        $process->setInput(json_encode(['sql' => $body, 'max_rows' => self::MAX_ROWS], JSON_UNESCAPED_UNICODE));
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return ['ok' => false, 'error' => 'Запрос выполнялся дольше '.$this->seconds($timeout).' и был остановлен. Проверьте условия соединения таблиц'];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => 'Учебная база сейчас недоступна'];
        }

        $out = json_decode($process->getOutput(), true);
        if (! is_array($out) || ! isset($out['ok'])) {
            report(new \RuntimeException('SQL sandbox: '.trim($process->getErrorOutput() ?: $process->getOutput())));

            return ['ok' => false, 'error' => 'Запрос не удалось выполнить: слишком большой результат или ошибка учебной базы'];
        }

        return $out['ok'] ? $out : ['ok' => false, 'error' => $this->humanError((string) $out['error'])];
    }

    public function schema(): string
    {
        return 'groups(id, name), students(id, name, group_id)';
    }

    /**
     * php.exe для дочернего процесса. Под Apache PHP_BINARY указывает не на консольный PHP,
     * поэтому путь берётся из настроек (SQL_SANDBOX_PHP или QUEUE_AUTOSTART_PHP).
     */
    private function php(): string
    {
        $configured = config('agents.sql_sandbox_php');
        if ($configured && is_file($configured)) {
            return $configured;
        }
        if (PHP_SAPI === 'cli' && PHP_BINARY) {
            return PHP_BINARY;
        }

        return 'php';
    }

    private function seconds(float $s): string
    {
        $n = (int) $s;

        return $n.' '.($n === 1 ? 'секунды' : 'секунд');
    }

    private function humanError(string $message): string
    {
        $message = preg_replace('/^SQLSTATE\[\w+\]:?\s*(General error:\s*\d+\s*)?/', '', $message);

        return 'Ошибка: '.$message;
    }
}
