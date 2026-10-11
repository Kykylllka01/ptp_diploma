<?php

namespace App\Agents\Llm;

use App\Agents\Contracts\CatalogAgent;
use App\Agents\Support\Text;
use App\Models\Question;
use Illuminate\Support\Collection;

/**
 * Каталогизатор: теги берёт из темы и уровня по Блуму, которые проставили Аналитик
 * и Составитель. Дубли: похожесть по словам отбирает до трёх кандидатов, и только
 * по ним модель решает, один ли это вопрос по смыслу.
 */
class LlmCatalogAgent implements CatalogAgent
{
    public function __construct(private AgentLlm $llm)
    {
    }

    public function catalog(Question $question, Collection $bank): array
    {
        $result = [
            'tags' => array_values(array_unique(array_filter($question->tags ?: [$question->topic]))),
            'difficulty' => $question->difficulty,
        ];

        $candidates = $bank
            ->filter(fn (Question $o) => $o->id !== $question->id && $o->type === $question->type)
            ->map(fn (Question $o) => ['q' => $o, 'percent' => Text::similarity($question->text, $o->text)])
            ->filter(fn ($c) => $c['percent'] >= config('agents.duplicate_candidate_threshold'))
            ->sortByDesc('percent')->take(3)->values();
        if ($candidates->isEmpty()) {
            return $result;
        }

        $user = '<новый_вопрос>'."\n".$this->describe($question)."\n".'</новый_вопрос>'."\n\n"
            .'<кандидаты>'."\n".$candidates->map(fn ($c) => $c['q']->code.': '.$this->describe($c['q']))->implode("\n\n")."\n".'</кандидаты>';
        $course = $question->course->title ?? 'Базы данных';
        $json = $this->llm->json('catalog', $user, ['course' => $course], fn ($j) => is_array($j['duplicates'] ?? null) ? null : 'нужно поле duplicates (массив)');

        $same = collect($json['duplicates'])->filter(fn ($d) => ! empty($d['same']))->pluck('code')->all();
        $best = $candidates->first(fn ($c) => in_array($c['q']->code, $same, true));
        if ($best) {
            $result['similar'] = ['code' => $best['q']->code, 'percent' => max($best['percent'], (int) config('agents.duplicate_threshold'))];
        }

        return $result;
    }

    private function describe(Question $q): string
    {
        $options = collect($q->options ?? [])->map(fn ($o) => (! empty($o['correct']) ? '[+] ' : '[ ] ').$o['text'])->implode('; ');

        return $q->text.($options ? "\n".'Варианты: '.$options : '').($q->reference ? "\n".'Эталон: '.mb_substr($q->reference, 0, 400) : '');
    }
}
