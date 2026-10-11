<?php

namespace App\Console\Commands;

use App\Agents\Contracts\GraderAgent;
use App\Agents\Llm\AgentLlm;
use App\Models\LlmCall;
use App\Models\Question;
use Illuminate\Console\Command;

/**
 * Сравнение Оценщика с преподавателем на наборе ответов (экспериментальная глава).
 * CSV в UTF-8, разделитель «;» или «,», первая строка — заголовок:
 *
 *   вопрос;ответ;балл
 *   Q-015;"Полная зависимость — это …";4
 *
 * Метрики: средняя абсолютная ошибка, доля точных совпадений и совпадений ±1 балл,
 * взвешенная (квадратичная) каппа Коэна, связь уверенности агента с ошибкой, стоимость.
 *
 *   php artisan agents:grader-eval answers.csv --set=grader.model=deepseek-flash
 */
class GraderEval extends Command
{
    protected $signature = 'agents:grader-eval
        {file : CSV с колонками «вопрос; ответ; балл преподавателя»}
        {--set=* : переопределение настройки агента, например grader.model=deepseek-flash}';

    protected $description = 'Сравнить оценки Оценщика с оценками преподавателя и посчитать стоимость';

    public function handle(GraderAgent $grader): int
    {
        foreach ($this->option('set') as $set) {
            [$key, $value] = array_pad(explode('=', $set, 2), 2, null);
            config(['agents.agents.'.$key => match ($value) {
                'true' => true, 'false' => false, 'null' => null,
                default => is_numeric($value) ? $value + 0 : $value,
            }]);
        }
        $rows = $this->readCsv((string) $this->argument('file'));
        if (! $rows) {
            $this->error('В файле нет строк с ответами');

            return self::FAILURE;
        }
        $since = LlmCall::max('id') ?? 0;

        $pairs = [];
        $table = [];
        foreach ($rows as [$code, $answer, $teacher]) {
            $q = Question::where('code', $code)->first();
            if (! $q || $q->type !== 'open') {
                $this->warn("{$code}: нет такого развёрнутого вопроса — строка пропущена");

                continue;
            }
            $before = LlmCall::max('id') ?? 0;
            try {
                $r = $grader->grade($q, $answer);
            } catch (\Throwable $e) {
                $table[] = [$code, mb_substr($answer, 0, 50), $teacher, 'сбой', '', '', mb_substr($e->getMessage(), 0, 40)];

                continue;
            }
            $agent = (int) array_sum(array_column($r['scores'], 0));
            $runs = LlmCall::where('id', '>', $before)->where('agent', 'grader')->count();
            $pairs[] = ['teacher' => (int) $teacher, 'agent' => $agent, 'max' => (int) $q->points, 'confidence' => (int) $r['confidence']];
            $table[] = [$code, mb_substr(preg_replace('/\s+/u', ' ', $answer), 0, 50), $teacher, $agent.' / '.$q->points, $r['confidence'].'%', $runs,
                abs($agent - (int) $teacher) > 1 ? '⚠ расхождение' : ''];
            $this->output->write('.');
        }
        AgentLlm::context([]);
        $this->newLine(2);
        $this->table(['вопрос', 'ответ', 'преподаватель', 'агент', 'уверенность', 'прогонов', ''], $table);

        if ($pairs) {
            $errors = array_map(fn ($p) => abs($p['agent'] - $p['teacher']), $pairs);
            $n = count($pairs);
            $this->line(sprintf('Ответов: %d. Средняя абсолютная ошибка: %.2f балла. Точно: %d%%, ±1 балл: %d%%. Взвешенная каппа Коэна: %.2f.',
                $n, array_sum($errors) / $n, round(100 * count(array_filter($errors, fn ($e) => $e === 0)) / $n),
                round(100 * count(array_filter($errors, fn ($e) => $e <= 1)) / $n), $this->kappa($pairs)));
            $sure = array_filter($pairs, fn ($p) => $p['confidence'] >= (int) config('agents.grader_confidence_threshold', 70));
            $unsure = array_filter($pairs, fn ($p) => $p['confidence'] < (int) config('agents.grader_confidence_threshold', 70));
            $mae = fn ($set) => $set ? array_sum(array_map(fn ($p) => abs($p['agent'] - $p['teacher']), $set)) / count($set) : 0;
            $this->line(sprintf('Уверенные оценки (≥ %d%%): %d, ошибка %.2f. Сомнительные: %d, ошибка %.2f — их агент сам отдаёт преподавателю.',
                config('agents.grader_confidence_threshold', 70), count($sure), $mae($sure), count($unsure), $mae($unsure)));
        }
        $calls = LlmCall::where('id', '>', $since)->where('agent', 'grader');
        $cost = (float) (clone $calls)->sum('cost_usd');
        $this->line(sprintf('Модель %s: вызовов %d, $%.4f всего, $%.5f за ответ, вход из кэша %d%%, рассуждение %d токенов.',
            config('agents.agents.grader.model'), (clone $calls)->count(), $cost, $pairs ? $cost / count($pairs) : 0,
            (clone $calls)->sum('tokens_in') ? round(100 * (clone $calls)->sum('tokens_cached') / (clone $calls)->sum('tokens_in')) : 0,
            (clone $calls)->sum('tokens_reasoning')));

        return self::SUCCESS;
    }

    /** @return array<int, array{0: string, 1: string, 2: int}> */
    private function readCsv(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $text = (string) file_get_contents($path);
        $text = str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
        $first = strtok($text, "\n");
        $sep = substr_count((string) $first, ';') >= substr_count((string) $first, ',') ? ';' : ',';
        $h = fopen('php://memory', 'r+');
        fwrite($h, $text);
        rewind($h);
        $rows = [];
        $header = true;
        while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) {
            if ($header) {
                $header = false;

                continue;
            }
            if (count($r) >= 3 && trim($r[0]) !== '') {
                $rows[] = [trim($r[0]), trim($r[1]), (int) trim($r[2])];
            }
        }

        return $rows;
    }

    /**
     * Взвешенная каппа Коэна с квадратичными весами по долям от максимума (баллы вопросов разные,
     * поэтому оценки переводятся в шкалу 0..4 — пять уровней).
     */
    private function kappa(array $pairs): float
    {
        $k = 5;
        $level = fn ($score, $max) => (int) round(($max > 0 ? $score / $max : 0) * ($k - 1));
        $obs = array_fill(0, $k, array_fill(0, $k, 0));
        foreach ($pairs as $p) {
            $obs[$level($p['teacher'], $p['max'])][$level($p['agent'], $p['max'])]++;
        }
        $n = count($pairs);
        $rowSum = array_map('array_sum', $obs);
        $colSum = array_map(fn ($j) => array_sum(array_column($obs, $j)), range(0, $k - 1));
        $num = $den = 0.0;
        for ($i = 0; $i < $k; $i++) {
            for ($j = 0; $j < $k; $j++) {
                $w = (($i - $j) ** 2) / (($k - 1) ** 2);
                $num += $w * $obs[$i][$j];
                $den += $w * $rowSum[$i] * $colSum[$j] / $n;
            }
        }

        return $den > 0 ? 1 - $num / $den : 1.0;
    }
}
