<?php

namespace App\Services;

use App\Agents\Contracts\GraderAgent;
use App\Exceptions\SqlSandboxUnavailable;
use App\Models\Question;
use Illuminate\Support\Facades\Cache;

/**
 * Оценка одного ответа. Варианты — сразу и окончательно; SQL — сравнением
 * результата с эталоном на учебной базе; развёрнутые — агентом-оценщиком,
 * предварительно (итог подтверждает преподаватель).
 *
 * Результат: ['score', 'max', 'final', 'criteria' => [[балл, макс, комментарий]], 'agent' => ...]
 */
class GradingService
{
    public function __construct(private SqlSandbox $sandbox, private GraderAgent $grader)
    {
    }

    public function grade(Question $q, mixed $answer): array
    {
        return match ($q->type) {
            'sql' => $this->gradeSql($q, (string) ($answer ?? '')),
            'open' => $this->gradeOpen($q, (string) ($answer ?? '')),
            'match' => $this->gradeMatch($q, $answer),
            default => $this->gradeChoice($q, $answer),
        };
    }

    public static function isAnswered(mixed $answer): bool
    {
        if ($answer === null) {
            return false;
        }
        if (is_array($answer)) {
            return count($answer) > 0;
        }

        return trim((string) $answer) !== '';
    }

    private function gradeChoice(Question $q, mixed $answer): array
    {
        $given = array_map('intval', (array) ($answer ?? []));
        sort($given);
        $correct = $q->correctIndexes();
        sort($correct);

        return ['score' => $given && $given === $correct ? $q->points : 0, 'max' => $q->points, 'final' => true];
    }

    /** Сопоставление: засчитывается, если все термины сопоставлены верно. */
    private function gradeMatch(Question $q, mixed $answer): array
    {
        $key = $q->matchParts()['key'];
        $given = array_map('intval', array_values((array) ($answer ?? [])));

        return ['score' => $key && $given === $key ? $q->points : 0, 'max' => $q->points, 'final' => true];
    }

    private function gradeSql(Question $q, string $sql): array
    {
        $max = $q->points;
        if (trim($sql) === '') {
            return ['score' => 0, 'max' => $max, 'final' => true, 'criteria' => [[0, $max, 'Нет ответа']]];
        }

        $mine = $this->sandbox->run($sql);
        if (! $mine['ok'] && ($mine['kind'] ?? 'sql') === 'infra') {
            // Учебная база не запустилась — студент не виноват, 0 не ставим: сдача откатится
            throw new SqlSandboxUnavailable($mine['error']);
        }
        if (! $mine['ok']) {
            return ['score' => 0, 'max' => $max, 'final' => true, 'criteria' => [[0, $max, 'Запрос не выполнился: '.$mine['error']]]];
        }

        $ref = $q->reference ? $this->reference($q->reference) : ['ok' => false, 'kind' => 'sql'];
        if (! $ref['ok'] && ($ref['kind'] ?? 'sql') !== 'sql') {
            // Эталон завис или база недоступна — сравнивать не с чем, но и ставить 0 нельзя
            throw new SqlSandboxUnavailable($ref['error'] ?? '');
        }
        if (! $ref['ok']) {
            // Эталон не выполняется на учебной базе — сравнить не с чем; Критик не пропускает такие вопросы,
            // но если эталон испортили вручную, честно ставим 0 с пояснением (у SQL нет очереди ручной проверки)
            return ['score' => 0, 'max' => $max, 'final' => true, 'criteria' => [[0, $max, 'Эталонный запрос вопроса не выполняется — сообщите преподавателю']]];
        }

        $sameRows = $this->normalize($mine['rows']) === $this->normalize($ref['rows']);
        $sameSet = $this->normalize($mine['rows'], true) === $this->normalize($ref['rows'], true);
        $refHasOrder = (bool) preg_match('/order\s+by/i', $q->reference);

        $criteria = [];
        $score = 0;
        $criteria[] = [1, 1, 'Запрос выполняется без ошибок'];
        $score++;

        // Остальные баллы — за совпадение результата с эталоном. Если в эталоне есть ORDER BY
        // и баллов хватает, порядок оценивается отдельным баллом; иначе он входит в совпадение.
        $rest = $max - 1;
        $mismatch = count($mine['rows']) === count($ref['rows'])
            ? 'Строк столько же, сколько в эталоне, но столбцы или значения отличаются'
            : 'Результат отличается от эталона: '.count($mine['rows']).' '.$this->rows(count($mine['rows'])).' вместо '.count($ref['rows']);
        if ($rest > 0 && $refHasOrder && $rest >= 2) {
            $criteria[] = $sameSet ? [$rest - 1, $rest - 1, 'Набор строк совпадает с эталоном'] : [0, $rest - 1, $mismatch];
            $criteria[] = $sameSet && $sameRows ? [1, 1, 'Порядок строк верный'] : [0, 1, 'Порядок строк не совпадает с эталоном'];
            $score += ($sameSet ? $rest - 1 : 0) + ($sameSet && $sameRows ? 1 : 0);
        } elseif ($rest > 0) {
            $ok = $refHasOrder ? $sameRows : $sameSet;
            $criteria[] = $ok ? [$rest, $rest, 'Результат совпадает с эталоном'.($refHasOrder ? ', порядок строк верный' : '')]
                : [0, $rest, $sameSet ? 'Строки верные, но порядок не совпадает с эталоном' : $mismatch];
            $score += $ok ? $rest : 0;
        }

        $score = min($score, $max);

        // Результат сравнения на учебной базе окончательный: ручной проверки SQL нет
        return ['score' => $score, 'max' => $max, 'final' => true, 'criteria' => $criteria];
    }

    /** Результат эталона одинаков для всех студентов: успешный кэшируется, чтобы сдача не запускала его заново. */
    private function reference(string $sql): array
    {
        $key = 'sql-ref:'.sha1($sql);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $ref = $this->sandbox->run($sql);
        if ($ref['ok']) {
            Cache::put($key, $ref, now()->addDay());
        }

        return $ref;
    }

    private function gradeOpen(Question $q, string $text): array
    {
        $max = $q->points;
        $criteria = $q->criteria ?: [['Ответ по существу вопроса', $max]];
        if (trim($text) === '') {
            return ['score' => 0, 'max' => $max, 'final' => true,
                'criteria' => array_map(fn ($c) => [0, $c[1], 'Нет ответа'], $criteria)];
        }

        $agent = $this->grader->grade($q, $text);
        $notes = [];
        foreach ($criteria as $i => [$label, $cmax]) {
            [$s, , $note] = $agent['scores'][$i] ?? [0, 'no', ''];
            $notes[] = [$s, $cmax, $label.': '.$note];
        }

        return [
            'score' => array_sum(array_column($notes, 0)),
            'max' => $max,
            'final' => false,
            'criteria' => $notes,
            'agent' => $agent,
        ];
    }

    private function rows(int $n): string
    {
        $n10 = $n % 10;
        $n100 = $n % 100;

        return $n10 === 1 && $n100 !== 11 ? 'строка' : ($n10 >= 2 && $n10 <= 4 && ($n100 < 10 || $n100 >= 20) ? 'строки' : 'строк');
    }

    private function normalize(array $rows, bool $sorted = false): array
    {
        $rows = array_map(fn ($r) => implode('|', array_map(fn ($v) => is_numeric($v) ? (string) (0 + $v) : (string) $v, $r)), $rows);
        if ($sorted) {
            sort($rows);
        }

        return $rows;
    }
}
