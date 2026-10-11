<?php

namespace App\Agents\Llm;

use App\Agents\Contracts\CriticAgent;
use App\Agents\Support\Text;
use App\Models\Material;
use App\Services\SqlSandbox;

/**
 * Критик: сначала формальные проверки кодом (варианты, ключ, критерии, эталонный SQL,
 * цитата в источнике, запреты из промпта научного руководителя). Если формальная
 * проверка не прошла, модель не вызывается: вопрос уходит на доработку.
 * Иначе — семь смысловых проверок моделью.
 */
class LlmCriticAgent implements CriticAgent
{
    private const LABELS = [
        'correct_by_source' => ['Верный ответ подтверждается источником', 'Ответ не подтверждается источником'],
        'single_answer' => ['Верных ответов ровно столько, сколько отмечено', 'Ключ ответа вызывает сомнение'],
        'clarity' => ['Формулировка однозначна', 'Формулировка допускает двоякое толкование'],
        'no_leak' => ['Вопрос не подсказывает ответ', 'Вопрос подсказывает ответ'],
        'distractors' => ['Неверные варианты правдоподобны', 'Неверные варианты слабые'],
        'level' => ['Сложность и уровень по Блуму соответствуют заявленным', 'Сложность не соответствует заявленной'],
        'relevance' => ['Проверяется существенное знание', 'Проверяется второстепенная деталь'],
    ];

    /** Названия типов, которые нельзя писать в тексте вопроса. */
    private const TYPE_WORDS = ['множественный выбор', 'множественного выбора', 'одиночный выбор', 'сопоставление', 'верно/неверно', 'верно или неверно', 'открытый вопрос', 'тестовое задание'];

    /** Ссылки на материал, которого студент не видит. */
    private const SOURCE_REFS = '/табл\.\s*\d|таблиц[аеуы]?\s+\d|рис\.\s*\d|рисун\w*\s+\d|в\s+источник|из\s+источник|по\s+источник|источнику|в\s+лекци|из\s+лекци|в\s+материал|по\s+материал|как\s+отмечено|как\s+показано|см\.\s/u';

    public function __construct(private AgentLlm $llm, private SqlSandbox $sandbox)
    {
    }

    public function review(array $draft): array
    {
        [$checks, $problems] = $this->formal($draft);
        $failed = array_filter($problems);
        if ($failed) {
            $first = reset($failed);

            return [
                'verdict' => 'dispute',
                'score' => max(1, 5 - 2 * count($failed)),
                'checks' => $checks,
                'flag' => ['text' => $first[0], 'level' => $first[1]],
                'formal' => true,
            ];
        }

        $source = QuestionDraft::sourceText($draft);
        $course = optional(Material::find($draft['material_id'] ?? null))->course->title ?? 'Базы данных';
        // Источник перед вопросом: у вопросов одного фрагмента он общий и берётся из кэша DeepSeek
        $user = '<источник>'."\n".$source."\n".'</источник>'."\n\n"
            .'<вопрос>'."\n".json_encode(QuestionDraft::toModel($draft), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n".'</вопрос>';

        // Рассуждение — только там, где ошибка тонкая (развёрнутый ответ, SQL): на вопросах с выбором
        // и сопоставлении Критик без рассуждения ловит подложенные ошибки так же (agents:critic-eval)
        $think = in_array($draft['type'] ?? '', (array) config('agents.agents.critic.thinking_types', []), true);
        $json = $this->llm->json('critic', $user, ['course' => $course], fn ($j) => $this->validate($j, $draft), ['thinking' => $think]);

        foreach ($json['checks'] as $c) {
            $label = self::LABELS[$c['code']] ?? [$c['code'], $c['code']];
            $text = $c['ok'] ? $label[0] : $label[1];
            if (! $c['ok'] && ! empty($c['comment'])) {
                $text .= ': '.rtrim(mb_strtolower(mb_substr($c['comment'], 0, 1)).mb_substr($c['comment'], 1), '.');
            }
            $checks[] = ['ok' => (bool) $c['ok'], 'text' => $text];
        }

        $result = [
            'verdict' => $json['verdict'],
            'score' => max(0, min(10, (int) $json['score'])),
            'checks' => $checks,
        ];
        if (! empty($json['flag']['text']) && $json['verdict'] !== 'accept') {
            $result['flag'] = ['text' => mb_substr($json['flag']['text'], 0, 120), 'level' => ($json['flag']['level'] ?? '') === 'red' ? 'red' : 'amber'];
        } elseif ($json['verdict'] === 'dispute') {
            $result['flag'] = ['text' => 'Критик сомневается в вопросе', 'level' => 'amber'];
        }
        $fix = $json['fix'] ?? null;
        if (is_array($fix) && isset($fix['option_index'], $draft['options'][(int) $fix['option_index']]) && ! empty($fix['text'])) {
            $result['fix'] = ['optionIndex' => (int) $fix['option_index'], 'text' => (string) $fix['text']];
        }

        return $result;
    }

    private function validate(array $j, array $draft): ?string
    {
        if (! in_array($j['verdict'] ?? null, ['accept', 'dispute', 'reject'], true)) {
            return 'verdict должен быть accept, dispute или reject';
        }
        if (! is_array($j['checks'] ?? null) || count($j['checks']) < 7) {
            return 'нужны все семь проверок в checks';
        }
        foreach ($j['checks'] as $c) {
            if (! is_array($c) || ! isset($c['code'], $c['ok'])) {
                return 'у каждой проверки должны быть code и ok';
            }
        }
        if (! is_numeric($j['score'] ?? null)) {
            return 'score должен быть числом от 0 до 10';
        }

        return null;
    }

    /**
     * Формальные проверки.
     *
     * @return array{0: array<int, array{ok: bool, text: string}>, 1: array<int, ?array{0: string, 1: string}>}
     */
    private function formal(array $d): array
    {
        $checks = [];
        $problems = [];
        $add = function (bool $ok, string $okText, string $badText, string $flag, string $level = 'red') use (&$checks, &$problems) {
            $checks[] = ['ok' => $ok, 'text' => $ok ? $okText : $badText];
            $problems[] = $ok ? null : [$flag, $level];
        };

        $type = $d['type'];
        $options = $d['options'] ?? [];
        $texts = array_map(fn ($o) => Text::norm($o['text']), $options);
        $correct = count(array_filter($options, fn ($o) => ! empty($o['correct'])));
        $level = $d['difficulty'] ?? 'medium';
        $lower = mb_strtolower($d['text']);

        if (in_array($type, ['single', 'multiple', 'match'], true)) {
            $add(count(array_unique($texts)) === count($texts), 'Варианты не повторяются', 'Есть повторяющиеся варианты', 'Повтор вариантов', 'amber');
        }
        if ($type === 'single') {
            $add(count($options) === 4, 'Четыре варианта ответа', 'Вариантов '.count($options).', нужно 4', 'Не 4 варианта', 'amber');
            $add($correct === 1, 'Ровно один верный вариант', 'Верных вариантов: '.$correct.', должен быть один', $correct ? 'Несколько верных' : 'Нет верного ответа');
        }
        if ($type === 'tf') {
            $add($correct === 1, 'Ровно один верный вариант', 'Не отмечен верный вариант', 'Нет верного ответа');
        }
        if ($type === 'multiple') {
            $add($correct >= 2 && $correct < count($options), 'Несколько верных и есть неверные варианты', 'Верных '.$correct.' из '.count($options).': так не получится вопрос с несколькими ответами', 'Проверьте ключ');
            if (preg_match('/выберите\s+(\d+)/u', $lower, $m)) {
                $n = (int) $m[1];
                $add($n === $correct, 'Число верных в тексте совпадает с ключом', 'В тексте «выберите '.$n.'», а верных '.$correct, 'Число верных не совпадает');
            } elseif ($level === 'easy') {
                $add(false, '', 'На лёгком уровне в тексте нужно «Выберите N верных ответов»', 'Не указано число верных', 'amber');
            }
        }
        if ($type === 'match') {
            $pairs = count(array_filter($options, fn ($o) => ! empty($o['correct'])));
            $extra = count($options) - $pairs;
            $add($pairs >= 2 && $extra === 1, 'Пары и ровно один лишний вариант', 'Пар '.$pairs.', лишних '.$extra.': нужно от 2 пар и ровно один лишний', 'Состав сопоставления', 'amber');
        }
        foreach ($texts as $t) {
            if (str_contains($t, 'все перечисленное') || str_contains($t, 'всё перечисленное')) {
                $add(false, '', 'Вариант «всё перечисленное» не допускается', 'Вариант «всё перечисленное»', 'amber');
                break;
            }
        }
        $visible = $d['text'].' '.implode(' ', array_column($options, 'text'));
        if (preg_match(self::SOURCE_REFS, mb_strtolower($visible), $ref)) {
            $add(false, '', 'Вопрос ссылается на материал, которого студент не видит («'.trim($ref[0]).'»): перенесите нужные данные в исходные данные вопроса', 'Ссылка на материал лекции', 'amber');
        }
        foreach (self::TYPE_WORDS as $w) {
            if (str_contains($lower, $w)) {
                $add(false, '', 'В тексте назван тип вопроса («'.$w.'»)', 'Назван тип вопроса', 'amber');
                break;
            }
        }
        if ($type === 'open') {
            $criteria = $d['criteria'] ?? [];
            $sum = array_sum(array_column($criteria, 1));
            $add(count($criteria) >= 2 && count($criteria) <= 4, 'Критерии оценки сформулированы', 'Критериев '.count($criteria).', нужно 2–4', 'Проверьте критерии', 'amber');
            $add($sum === (int) $d['points'], 'Сумма баллов критериев равна баллу вопроса', 'Сумма критериев '.$sum.' ≠ '.$d['points'].' баллов', 'Сумма критериев', 'amber');
            $add(! empty($d['reference']), 'Есть эталонный ответ', 'Нет эталонного ответа', 'Нет эталона');
        }
        if ($type === 'sql') {
            $ref = $d['reference'] ? $this->sandbox->run($d['reference']) : ['ok' => false, 'error' => 'эталона нет'];
            $add($ref['ok'], 'Эталонный запрос выполняется на учебной базе', 'Эталонный запрос не выполняется: '.($ref['error'] ?? ''), 'Эталон SQL с ошибкой');
        }
        $quote = $d['_quote'] ?? QuestionDraft::markedQuote($d['quote'] ?? '');
        $source = QuestionDraft::sourceText($d);
        if ($quote !== '' && $source !== '' && $source !== $quote) {
            $add(Text::contains($source, $quote), 'Цитата-опора есть в источнике', 'Цитаты-опоры нет в источнике дословно', 'Цитата не из источника', 'amber');
        } elseif ($quote === '') {
            $add(false, '', 'Нет цитаты-опоры из источника', 'Нет опоры на источник', 'amber');
        }

        return [$checks, $problems];
    }
}
