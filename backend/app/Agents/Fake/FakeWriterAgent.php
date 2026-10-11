<?php

namespace App\Agents\Fake;

use App\Agents\Contracts\WriterAgent;
use App\Models\Material;

/**
 * Заготовка Составителя: вопросы по шаблонам, с пометкой «[заготовка]».
 * Настоящий агент будет отправлять в LlmClient текст материала и параметры генерации.
 */
class FakeWriterAgent implements WriterAgent
{
    private const POINTS = ['single' => 1, 'tf' => 1, 'multiple' => 2, 'match' => 2, 'sql' => 2, 'open' => 5];

    public function generate(Material $material, array $analysis, array $params, int $count): array
    {
        $types = $params['types'] ?: ['single'];
        $topics = $analysis['topics'] ?: ['Общие вопросы'];
        $diffs = ($params['difficulty'] ?? 'mixed') === 'mixed' ? ['easy', 'medium', 'medium', 'hard'] : [$params['difficulty']];

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $type = $types[$i % count($types)];
            $topic = $topics[$i % count($topics)];
            $out[] = $this->draft($type, $topic, $diffs[$i % count($diffs)], $material, $analysis, $i + 1);
        }

        return $out;
    }

    public function rewrite(array $draft, array $criticChecks): array
    {
        $draft['text'] = preg_replace('/\s*\(вариант \d+\)$/u', '', $draft['text']).' (переписано)';

        return $draft;
    }

    private function draft(string $type, string $topic, string $difficulty, Material $m, array $analysis, int $n): array
    {
        $base = [
            'type' => $type,
            'difficulty' => $difficulty,
            'topic' => $topic,
            'source' => $m->title,
            'points' => self::POINTS[$type] ?? 1,
            'quote' => $analysis['fragments'][$topic] ?? null,
            'explanation' => '[заготовка] Пояснение со ссылкой на материал «'.$m->title.'».',
        ];

        return $base + match ($type) {
            'single' => [
                'text' => "[заготовка] Какое утверждение о теме «{$topic}» верно по материалу «{$m->title}»? (вариант {$n})",
                'options' => [
                    ['text' => 'Верное утверждение из материала', 'correct' => true],
                    ['text' => 'Правдоподобный неверный вариант 1'],
                    ['text' => 'Правдоподобный неверный вариант 2'],
                    ['text' => 'Правдоподобный неверный вариант 3'],
                ],
            ],
            'multiple' => [
                'text' => "[заготовка] Выберите все верные утверждения о теме «{$topic}». (вариант {$n})",
                'options' => [
                    ['text' => 'Верное утверждение 1', 'correct' => true],
                    ['text' => 'Верное утверждение 2', 'correct' => true],
                    ['text' => 'Неверное утверждение 1'],
                    ['text' => 'Неверное утверждение 2'],
                ],
            ],
            'tf' => [
                'text' => "[заготовка] Верно ли утверждение по теме «{$topic}»? (вариант {$n})",
                'options' => [['text' => 'Верно', 'correct' => true], ['text' => 'Неверно']],
            ],
            'match' => [
                'text' => "[заготовка] Сопоставьте понятия темы «{$topic}» и их определения. (вариант {$n})",
                'options' => [['text' => 'Понятие 1 — определение 1'], ['text' => 'Понятие 2 — определение 2']],
            ],
            'sql' => [
                'text' => "[заготовка] Напишите SQL-запрос по теме «{$topic}»: количество студентов в каждой группе, по убыванию. (вариант {$n})",
                'reference' => "SELECT g.name, COUNT(s.id) AS students\nFROM groups g LEFT JOIN students s ON s.group_id = g.id\nGROUP BY g.name\nORDER BY students DESC;",
                'sql_schema' => 'groups(id, name), students(id, name, group_id)',
            ],
            default => [
                'text' => "[заготовка] Объясните основные положения темы «{$topic}» и приведите пример. (вариант {$n})",
                'reference' => "[заготовка] Эталонный ответ по теме «{$topic}» на основе материала «{$m->title}».",
                'criteria' => [["Раскрыто основное понятие темы «{$topic}»", 2], ['Приведён корректный пример', 2], ['Ответ опирается на материал курса', 1]],
            ],
        };
    }
}
