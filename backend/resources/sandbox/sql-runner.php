<?php

/*
| Исполнитель учебной базы SQL. Запускается отдельным процессом из App\Services\SqlSandbox,
| чтобы «бесконечный» запрос можно было прервать по таймауту: внутри SQLite PHP
| выполнение не прерывает. Laravel не загружается — только PDO SQLite.
|
| Вход (stdin): JSON {"sql": "...", "max_rows": 200}
| Выход (stdout): JSON {"ok": true, "columns": [...], "rows": [...], "ms": 3} или {"ok": false, "error": "..."}
*/

const GROUPS = [
    'ИСиТ-23-1' => 28, 'ИСиТ-23-2' => 26, 'ПИ-23-1' => 25,
    'ПИ-23-2' => 24, 'ФИТ-23-1' => 22, 'ФИТ-23-2' => 0,
];

$input = json_decode((string) stream_get_contents(STDIN), true);
$sql = (string) ($input['sql'] ?? '');
$maxRows = (int) ($input['max_rows'] ?? 200);

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE "groups" (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE students (id INTEGER PRIMARY KEY, name TEXT NOT NULL, group_id INTEGER REFERENCES "groups"(id))');

    $g = $pdo->prepare('INSERT INTO "groups" (id, name) VALUES (?, ?)');
    $s = $pdo->prepare('INSERT INTO students (name, group_id) VALUES (?, ?)');
    $gid = 0;
    $n = 0;
    foreach (GROUPS as $name => $count) {
        $g->execute([++$gid, $name]);
        for ($i = 0; $i < $count; $i++) {
            $s->execute(['Студент '.(++$n), $gid]);
        }
    }

    $start = microtime(true);
    $stmt = $pdo->query($sql);
    $rows = [];
    while (count($rows) < $maxRows && ($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
        $rows[] = $row;
    }
    $columns = [];
    for ($i = 0; $i < $stmt->columnCount(); $i++) {
        $columns[] = $stmt->getColumnMeta($i)['name'] ?? 'col'.$i;
    }

    $out = ['ok' => true, 'columns' => $columns, 'rows' => $rows, 'ms' => (int) max(1, round((microtime(true) - $start) * 1000))];
} catch (Throwable $e) {
    $out = ['ok' => false, 'error' => $e->getMessage()];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
