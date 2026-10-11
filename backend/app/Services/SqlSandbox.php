<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Учебная база для заданий на SQL: SQLite в памяти, создаётся заново на каждый запрос,
 * поэтому студент ничего не может в ней испортить. Разрешён только один SELECT.
 *
 * Запрос выполняется в отдельном процессе PHP (resources/sandbox/sql-runner.php): внутри SQLite
 * PHP выполнение не прерывает и память не считает. Процесс прерывается по таймауту,
 * а куча SQLite ограничена (PRAGMA hard_heap_limit).
 *
 * Ответ при ошибке содержит kind: sql — ошибка в запросе, timeout — слишком долго,
 * memory — слишком много памяти, infra — сама учебная база не запустилась (не вина студента).
 */
class SqlSandbox
{
    private const MAX_ROWS = 200;

    private const HEAP_BYTES = 64 * 1024 * 1024;

    /** RECURSIVE без условия остановки работает бесконечно; *blob — быстрый способ занять память. */
    private const FORBIDDEN = '/\b(recursive|randomblob|zeroblob)\b/i';

    /** Переменные окружения, которые дочернему процессу передаются; остальные (ключи из .env) — нет. */
    private const KEEP_ENV = ['PATH', 'SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP', 'COMSPEC', 'PATHEXT', 'SYSTEMDRIVE', 'PHPRC', 'PHP_INI_SCAN_DIR'];

    /** @return array{ok: bool, kind?: string, error?: string, columns?: string[], rows?: array, ms?: int} */
    public function run(string $sql): array
    {
        $sql = trim($sql);
        $body = rtrim($sql, "; \n\r\t");
        $code = $this->withoutLiterals($body);

        if (! preg_match('/^(select|with)\b/i', $code)) {
            return $this->fail('sql', 'На учебной базе можно выполнять только SELECT');
        }
        if (str_contains($code, ';')) {
            return $this->fail('sql', 'Можно выполнить только один запрос');
        }
        if (preg_match(self::FORBIDDEN, $code, $m)) {
            return $this->fail('sql', 'На учебной базе нельзя использовать '.strtoupper($m[1]));
        }

        $timeout = (float) config('agents.sql_sandbox_timeout', 3);
        $process = new Process(
            [$this->php(), '-d', 'memory_limit=128M', resource_path('sandbox/sql-runner.php')],
            null,
            $this->childEnv(),
        );
        $process->setInput(json_encode(['sql' => $body, 'max_rows' => self::MAX_ROWS, 'heap_bytes' => self::HEAP_BYTES], JSON_UNESCAPED_UNICODE));
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return $this->fail('timeout', 'Запрос выполнялся дольше '.$this->seconds($timeout).' и был остановлен. Проверьте условия соединения таблиц');
        } catch (Throwable $e) {
            report($e);

            return $this->fail('infra', 'Учебная база сейчас недоступна');
        }

        $out = json_decode($process->getOutput(), true);
        if (! is_array($out) || ! isset($out['ok'])) {
            report(new \RuntimeException('SQL sandbox: exit '.$process->getExitCode().' '.trim($process->getErrorOutput() ?: $process->getOutput())));

            return $this->fail('infra', 'Учебная база сейчас недоступна');
        }
        if ($out['ok']) {
            return $out;
        }

        return ($out['kind'] ?? 'sql') === 'memory'
            ? $this->fail('memory', 'Запросу не хватило памяти учебной базы. Уменьшите результат или проверьте условия соединения таблиц')
            : $this->fail('sql', $this->humanError((string) $out['error']));
    }

    public function schema(): string
    {
        return 'groups(id, name), students(id, name, group_id)';
    }

    private function fail(string $kind, string $error): array
    {
        return ['ok' => false, 'kind' => $kind, 'error' => $error];
    }

    /** Запрос без строковых литералов и идентификаторов в кавычках: WHERE name = 'recursive' — не запрет. */
    private function withoutLiterals(string $sql): string
    {
        return preg_replace(["/'(?:[^']|'')*'/", '/"(?:[^"]|"")*"/', '/`[^`]*`/', '/\[[^\]]*\]/'], "''", $sql) ?? $sql;
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

    /** Окружение без ключей приложения: Symfony Process наследует его целиком, false — убрать переменную. */
    private function childEnv(): array
    {
        $env = [];
        foreach (array_keys(getenv() + $_ENV + $_SERVER) as $name) {
            if (is_string($name) && ! in_array(strtoupper($name), self::KEEP_ENV, true)) {
                $env[$name] = false;
            }
        }

        return $env;
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
