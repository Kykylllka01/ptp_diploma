<?php

namespace App\Agents\Llm;

use App\Agents\Contracts\GraderAgent;
use App\Models\Question;
use App\Models\Review;

/**
 * Оценщик: три независимых прогона модели (self-consistency), по каждому критерию —
 * медиана баллов. Уверенность считает код по согласию прогонов, а не спрашивает у модели.
 * Подтверждённые преподавателем оценки по этому вопросу идут модели как примеры строгости.
 */
class LlmGraderAgent implements GraderAgent
{
    private const STATUS_TEXT = ['ok' => 'выполнено', 'part' => 'частично', 'no' => 'не выполнено'];

    public function __construct(private AgentLlm $llm)
    {
    }

    public function grade(Question $question, string $answer): array
    {
        $criteria = $question->criteria ?: [['Ответ по существу вопроса', $question->points]];
        $user = $this->message($question, $criteria, $answer);
        $course = $question->course->title ?? 'Базы данных';

        // Адаптивное число прогонов: сначала min_runs (2); если они разошлись хоть по одному
        // критерию или модель сомневается — добираем до runs (3). Запрос у прогонов одинаковый,
        // поэтому со второго прогона вход берётся из кэша DeepSeek почти целиком.
        $maxRuns = max(1, (int) config('agents.agents.grader.runs', 3));
        $minRuns = min($maxRuns, max(1, (int) config('agents.agents.grader.min_runs', 2)));
        $runs = [];
        $failure = null;
        for ($i = 0; $i < $maxRuns && ($i < $minRuns || ! $this->settled($runs)); $i++) {
            try {
                $json = $this->llm->json('grader', $user, ['course' => $course], fn ($j) => $this->validate($j, $criteria));
                $runs[] = $this->normalize($json, $criteria);
            } catch (\Throwable $e) {
                // Сбой одного прогона не обесценивает уже оплаченные
                $failure = $e;
                $minRuns = min($maxRuns, $minRuns + 1);
            }
        }
        if (! $runs) {
            throw $failure ?? new \RuntimeException('Оценщик не дал ни одного ответа');
        }

        $scores = [];
        $agreed = 0;
        $evidence = [];
        foreach ($criteria as $i => [, $max]) {
            $values = array_column(array_column($runs, 'criteria'), $i);
            $points = array_column($values, 'score');
            sort($points);
            $median = $points[intdiv(count($points), 2)];
            $statuses = array_column($values, 'status');
            if (count(array_unique($statuses)) === 1) {
                $agreed++;
            }
            $status = $median >= $max ? 'ok' : ($median > 0 ? 'part' : 'no');
            // Пояснение — из прогона, чей балл совпал с медианой
            $reason = collect($values)->firstWhere('score', $median)['reason'] ?? '';
            $scores[] = [$median, $status, self::STATUS_TEXT[$status].($reason !== '' ? ' — '.$reason : '')];
            foreach ($values as $v) {
                $evidence = array_merge($evidence, $v['evidence']);
            }
        }

        $injection = collect($runs)->contains('injection', true);
        $confidence = (int) round(100 * $agreed / max(1, count($criteria)));
        if (collect($runs)->contains('uncertain', true)) {
            $confidence -= 20;
        }
        if (mb_strlen(trim($answer)) < 40) {
            $confidence = min($confidence, 60);
        }
        if (count($runs) < 2) {
            // Один прогон (остальные упали) — согласие не с чем сравнить
            $confidence = min($confidence, 40);
        }
        if ($injection) {
            $confidence = 0;
        }

        $total = array_sum(array_column($scores, 0));
        $comment = collect($runs)->sortBy(fn ($r) => abs(array_sum(array_column($r['criteria'], 'score')) - $total))->first()['comment'];
        if ($injection) {
            $comment = 'Проверьте вручную: в ответе есть обращение к проверяющему. '.$comment;
        }

        return [
            'scores' => $scores,
            'confidence' => max(0, min(100, $confidence)),
            'comment' => $comment,
            'highlighted' => $this->highlight($answer, $evidence),
        ];
    }

    private function message(Question $q, array $criteria, string $answer): string
    {
        $lines = [];
        foreach ($criteria as $i => [$text, $max]) {
            $lines[] = $i.'. '.$text.' — '.$max.' '.($max == 1 ? 'балл' : ($max < 5 ? 'балла' : 'баллов'));
        }
        $msg = '<вопрос>'.($q->context ? $q->context."\n\n" : '').$q->text.'</вопрос>'."\n"
            .'<критерии>'."\n".implode("\n", $lines)."\n".'</критерии>'."\n"
            .'<эталон>'.($q->reference ?: 'нет').'</эталон>'."\n";

        $examples = $this->examples($q);
        if ($examples) {
            $msg .= '<примеры_оценок_преподавателя>'."\n".implode("\n\n", $examples)."\n".'</примеры_оценок_преподавателя>'."\n";
        }

        // Угловые скобки в ответе заменены: студент не может «закрыть» тег и дописать инструкции проверяющему
        return $msg.'<ответ_студента>'."\n".strtr($answer, ['<' => '‹', '>' => '›'])."\n".'</ответ_студента>';
    }

    /**
     * Прогоны сошлись: по каждому критерию одинаковые балл и статус, никто не сомневается
     * и не заметил попытки «уговорить» проверяющего. Тогда третий прогон ничего не добавит.
     */
    private function settled(array $runs): bool
    {
        if (count($runs) < 2 || collect($runs)->contains(fn ($r) => $r['uncertain'] || $r['injection'])) {
            return false;
        }
        $first = array_map(fn ($c) => [$c['score'], $c['status']], $runs[0]['criteria']);
        foreach (array_slice($runs, 1) as $r) {
            if (array_map(fn ($c) => [$c['score'], $c['status']], $r['criteria']) !== $first) {
                return false;
            }
        }

        return true;
    }

    /** До трёх подтверждённых преподавателем ответов на этот вопрос: сильный, средний, слабый. */
    private function examples(Question $q): array
    {
        $confirmed = Review::where('question_id', $q->id)->where('status', 'confirmed')->whereNotNull('scores')
            ->orderByDesc('final_score')->limit(30)->get();
        if ($confirmed->isEmpty()) {
            return [];
        }
        $picked = collect([$confirmed->first(), $confirmed->get(intdiv($confirmed->count(), 2)), $confirmed->last()])->unique('id');

        return $picked->map(function (Review $r) {
            $parts = [];
            foreach ($r->scores as $i => $s) {
                $parts[] = $i.': '.$s;
            }
            $text = mb_substr(trim(html_entity_decode(strip_tags($r->answer_html), ENT_QUOTES, 'UTF-8')), 0, 1500);

            return 'Ответ: «'.$text.'» → '.implode('; ', $parts).' (итого '.$r->final_score.')';
        })->values()->all();
    }

    private function validate(array $j, array $criteria): ?string
    {
        if (! is_array($j['criteria'] ?? null)) {
            return 'нужно поле criteria (массив)';
        }
        $indexes = array_map(fn ($c) => (int) ($c['index'] ?? -1), $j['criteria']);
        foreach (array_keys($criteria) as $i) {
            if (! in_array($i, $indexes, true)) {
                return 'нет оценки по критерию '.$i;
            }
        }
        foreach ($j['criteria'] as $c) {
            $max = $criteria[(int) ($c['index'] ?? -1)][1] ?? null;
            if ($max !== null && (! is_numeric($c['score'] ?? null) || $c['score'] < 0 || $c['score'] > $max)) {
                return 'балл по критерию '.$c['index'].' должен быть от 0 до '.$max;
            }
        }

        return null;
    }

    /** Приводит прогон к согласованному виду: статус следует из балла. */
    private function normalize(array $j, array $criteria): array
    {
        $byIndex = collect($j['criteria'])->keyBy(fn ($c) => (int) $c['index']);
        $out = [];
        $conflict = false;
        foreach ($criteria as $i => [, $max]) {
            $c = $byIndex->get($i);
            $score = (int) round(min($max, max(0, (float) $c['score'])));
            $status = in_array($c['status'] ?? '', ['ok', 'part', 'no'], true) ? $c['status'] : 'no';
            // Модель сама себе противоречит («выполнено», но 0 баллов) — это сомнение, а не уверенный ответ
            $conflict = $conflict || ($status === 'ok' && $score < $max) || ($status === 'no' && $score > 0);
            // Балл и статус должны быть согласованы: ok — максимум, no — ноль
            if ($status === 'ok') {
                $score = $max;
            } elseif ($status === 'no') {
                $score = 0;
            } elseif ($score <= 0 || $score >= $max) {
                $status = $score >= $max ? 'ok' : 'no';
            }
            $out[$i] = [
                'score' => $score,
                'status' => $status,
                'reason' => trim((string) ($c['reason'] ?? '')),
                'evidence' => array_values(array_filter(array_map('strval', (array) ($c['evidence'] ?? [])))),
            ];
        }

        return [
            'criteria' => $out,
            'injection' => ! empty($j['injection']),
            'uncertain' => ! empty($j['uncertain']) || $conflict,
            'comment' => trim((string) ($j['comment'] ?? '')),
        ];
    }

    /** Экранирует ответ и подсвечивает только те цитаты, которые в нём действительно есть. */
    private function highlight(string $answer, array $evidence): string
    {
        $ranges = [];
        foreach (array_unique($evidence) as $e) {
            $e = trim($e, " .…\"«»");
            if (mb_strlen($e) < 4) {
                continue;
            }
            $pos = mb_stripos($answer, $e);
            if ($pos !== false) {
                $ranges[] = [$pos, $pos + mb_strlen($e)];
            }
        }
        if (! $ranges) {
            return e($answer);
        }
        // Склеиваем пересекающиеся фрагменты
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [array_shift($ranges)];
        foreach ($ranges as $r) {
            $last = &$merged[count($merged) - 1];
            if ($r[0] <= $last[1]) {
                $last[1] = max($last[1], $r[1]);
            } else {
                $merged[] = $r;
            }
            unset($last);
        }

        $html = '';
        $at = 0;
        foreach ($merged as [$from, $to]) {
            $html .= e(mb_substr($answer, $at, $from - $at)).'<mark class="good">'.e(mb_substr($answer, $from, $to - $from)).'</mark>';
            $at = $to;
        }

        return $html.e(mb_substr($answer, $at));
    }
}
