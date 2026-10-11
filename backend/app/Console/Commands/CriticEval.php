<?php

namespace App\Console\Commands;

use App\Agents\Contracts\CriticAgent;
use App\Agents\Llm\AgentLlm;
use App\Models\LlmCall;
use App\Models\Question;
use Illuminate\Console\Command;

/**
 * Контрольный набор для Критика: вопросы из готовых запусков (исправные) и их копии
 * с подложенной ошибкой — неверный ключ, перепутанные пары, чужой эталон, не тот SQL.
 * Считает, сколько ошибок Критик поймал (полнота), сколько исправных зря забраковал
 * и сколько это стоило. Настройки Критика переопределяются как в agents:bench:
 *
 *   php artisan agents:critic-eval 1 2 --set=critic.model=deepseek-flash
 */
class CriticEval extends Command
{
    protected $signature = 'agents:critic-eval
        {runs* : id запусков генерации, из которых брать вопросы}
        {--set=* : переопределение настройки агента, например critic.model=deepseek-flash}';

    protected $description = 'Проверить, как Критик ловит подложенные ошибки в вопросах, и сколько это стоит';

    public function handle(): int
    {
        foreach ($this->option('set') as $set) {
            [$key, $value] = array_pad(explode('=', $set, 2), 2, null);
            config(['agents.agents.'.$key => match ($value) {
                'true' => true, 'false' => false, 'null' => null,
                default => is_numeric($value) ? $value + 0 : $value,
            }]);
        }
        $critic = app(CriticAgent::class);
        $questions = Question::whereIn('generation_run_id', $this->argument('runs'))->orderBy('id')->get();
        $since = LlmCall::max('id') ?? 0;

        $rows = [];
        $caught = $flawedTotal = $falseAlarms = 0;
        foreach ($questions as $i => $q) {
            $clean = $q->only($q->getFillable());
            $flawed = $this->spoil($clean, $questions[($i + 1) % $questions->count()]);

            $v1 = $this->verdict($critic, $clean);
            $falseAlarms += $v1['bad'] ? 1 : 0;
            $v2 = $flawed ? $this->verdict($critic, $flawed['draft']) : null;
            if ($flawed) {
                $flawedTotal++;
                $caught += $v2['bad'] ? 1 : 0;
            }
            $rows[] = [$q->code, $q->type, $v1['label'], $flawed['what'] ?? '—', $v2['label'] ?? '—'];
            $this->output->write('.');
        }
        AgentLlm::context([]);
        $this->newLine(2);

        $this->table(['код', 'тип', 'исправный → Критик', 'подложенная ошибка', 'испорченный → Критик'], $rows);
        $calls = LlmCall::where('id', '>', $since)->where('agent', 'critic');
        $n = (clone $calls)->count();
        $this->line(sprintf('Поймано ошибок: %d из %d (%d%%). Ложных тревог на исправных: %d из %d.',
            $caught, $flawedTotal, $flawedTotal ? round(100 * $caught / $flawedTotal) : 0, $falseAlarms, $questions->count()));
        $this->line(sprintf('Модель %s, вызовов %d, $%.4f всего, $%.5f за проверку, выход %d токенов (рассуждение %d), в среднем %.1f с.',
            config('agents.agents.critic.model'), $n, (clone $calls)->sum('cost_usd'), $n ? (clone $calls)->sum('cost_usd') / $n : 0,
            (clone $calls)->sum('tokens_out'), (clone $calls)->sum('tokens_reasoning'), $n ? (clone $calls)->avg('ms') / 1000 : 0));

        return self::SUCCESS;
    }

    /** Вердикт Критика: «плохой» — отклонён или спорный с красным флагом либо формальной ошибкой. */
    private function verdict(CriticAgent $critic, array $draft): array
    {
        try {
            $v = $critic->review($draft);
        } catch (\Throwable $e) {
            return ['bad' => false, 'label' => 'сбой: '.mb_substr($e->getMessage(), 0, 40)];
        }
        $bad = $v['verdict'] === 'reject' || ($v['verdict'] === 'dispute' && (($v['flag']['level'] ?? '') === 'red' || ! empty($v['formal'])));
        $label = $v['verdict'].($v['flag']['text'] ?? false ? ': '.mb_substr($v['flag']['text'], 0, 45) : '');

        return ['bad' => $bad, 'label' => $label];
    }

    /** Копия вопроса с одной содержательной ошибкой, которую формальные проверки не ловят. */
    private function spoil(array $d, Question $other): ?array
    {
        $opts = $d['options'] ?? [];
        switch ($d['type']) {
            case 'single':
            case 'tf':
                $right = array_search(true, array_map(fn ($o) => ! empty($o['correct']), $opts), true);
                $wrong = collect($opts)->keys()->first(fn ($k) => $k !== $right);
                if ($right === false || $wrong === null) {
                    return null;
                }
                $opts[$right]['correct'] = false;
                $opts[$wrong]['correct'] = true;

                return ['draft' => ['options' => $opts] + $d, 'what' => 'неверный ключ'];
            case 'multiple':
                $right = collect($opts)->keys()->first(fn ($k) => ! empty($opts[$k]['correct']));
                $wrong = collect($opts)->keys()->first(fn ($k) => empty($opts[$k]['correct']));
                if ($right === null || $wrong === null) {
                    return null;
                }
                $opts[$right]['correct'] = false;
                $opts[$wrong]['correct'] = true;

                return ['draft' => ['options' => $opts] + $d, 'what' => 'верный ↔ неверный вариант'];
            case 'match':
                $pairs = array_keys(array_filter($opts, fn ($o) => ! str_starts_with($o['text'], 'Лишнее:')));
                if (count($pairs) < 2) {
                    return null;
                }
                [$a, $b] = [explode(' — ', $opts[$pairs[0]]['text'], 2), explode(' — ', $opts[$pairs[1]]['text'], 2)];
                if (count($a) < 2 || count($b) < 2) {
                    return null;
                }
                $opts[$pairs[0]]['text'] = $a[0].' — '.$b[1];
                $opts[$pairs[1]]['text'] = $b[0].' — '.$a[1];

                return ['draft' => ['options' => $opts] + $d, 'what' => 'перепутаны две пары'];
            case 'open':
                $foreign = $other->explanation ?: $other->text;

                return ['draft' => ['reference' => $foreign] + $d, 'what' => 'эталон от другого вопроса'];
            case 'sql':
                return ['draft' => ['reference' => 'SELECT name FROM groups ORDER BY name'] + $d, 'what' => 'эталон не решает задачу'];
        }

        return null;
    }
}
