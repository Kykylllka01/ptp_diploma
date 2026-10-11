<?php

namespace App\Services;

use PDO;
use Throwable;

/**
 * Учебная база для заданий на SQL: SQLite в памяти, создаётся заново на каждый запрос,
 * поэтому студент ничего не может в ней испортить. Разрешён только один SELECT.
 */
class SqlSandbox
{
    private const GROUPS = [
        'ИСиТ-23-1' => 28, 'ИСиТ-23-2' => 26, 'ПИ-23-1' => 25,
        'ПИ-23-2' => 24, 'ФИТ-23-1' => 22, 'ФИТ-23-2' => 0,
    ];

    private const MAX_ROWS = 200;

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

        try {
            $pdo = $this->connect();
            $start = microtime(true);
            $stmt = $pdo->query($body);
            $rows = [];
            while (count($rows) < self::MAX_ROWS && ($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                $rows[] = $row;
            }
            $columns = [];
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $columns[] = $stmt->getColumnMeta($i)['name'] ?? 'col'.$i;
            }

            return ['ok' => true, 'columns' => $columns, 'rows' => $rows, 'ms' => (int) max(1, round((microtime(true) - $start) * 1000))];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $this->humanError($e->getMessage())];
        }
    }

    public function schema(): string
    {
        return 'groups(id, name), students(id, name, group_id)';
    }

    private function connect(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE "groups" (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE students (id INTEGER PRIMARY KEY, name TEXT NOT NULL, group_id INTEGER REFERENCES "groups"(id))');

        $g = $pdo->prepare('INSERT INTO "groups" (id, name) VALUES (?, ?)');
        $s = $pdo->prepare('INSERT INTO students (name, group_id) VALUES (?, ?)');
        $gid = 0;
        $n = 0;
        foreach (self::GROUPS as $name => $count) {
            $g->execute([++$gid, $name]);
            for ($i = 0; $i < $count; $i++) {
                $s->execute(['Студент '.(++$n), $gid]);
            }
        }

        return $pdo;
    }

    private function humanError(string $message): string
    {
        $message = preg_replace('/^SQLSTATE\[\w+\]:?\s*(General error:\s*\d+\s*)?/', '', $message);

        return 'Ошибка: '.$message;
    }
}
