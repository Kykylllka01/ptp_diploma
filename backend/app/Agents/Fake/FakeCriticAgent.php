<?php

namespace App\Agents\Fake;

use App\Agents\Contracts\CriticAgent;
use App\Agents\Support\Text;

/**
 * Заготовка Критика: формальные проверки (варианты, верные ответы, критерии)
 * плюс детерминированные «сомнения», чтобы в банке были спорные вопросы.
 */
class FakeCriticAgent implements CriticAgent
{
    public function review(array $draft): array
    {
        $checks = [];
        $problems = [];
        $options = $draft['options'] ?? [];
        $correct = count(array_filter($options, fn ($o) => ! empty($o['correct'])));

        if (in_array($draft['type'], ['single', 'tf'], true)) {
            $ok = $correct === 1;
            $checks[] = ['ok' => $ok, 'text' => $ok ? 'Ровно один верный вариант' : 'Верных вариантов: '.$correct.', должен быть один'];
            if (! $ok) {
                $problems[] = ['Два верных варианта', 'red'];
            }
        }
        if ($draft['type'] === 'multiple') {
            $ok = $correct >= 2;
            $checks[] = ['ok' => $ok, 'text' => $ok ? 'Отмечено несколько верных вариантов' : 'Для типа «несколько верных» нужно минимум два верных'];
            if (! $ok) {
                $problems[] = ['Проверьте ключ', 'red'];
            }
        }
        if ($draft['type'] === 'open') {
            $ok = ! empty($draft['criteria']);
            $checks[] = ['ok' => $ok, 'text' => $ok ? 'Критерии оценки сформулированы и проверяемы' : 'Нет критериев оценки'];
        }
        if (in_array($draft['type'], ['sql', 'open'], true) && empty($draft['reference'])) {
            $checks[] = ['ok' => false, 'text' => 'Нет эталонного ответа'];
            $problems[] = ['Проверьте эталон', 'amber'];
        }

        // Имитация смысловых замечаний модели
        $h = Text::hash($draft['text'], 10);
        if ($h === 0) {
            $checks[] = ['ok' => false, 'text' => '[заготовка] Формулировка допускает двоякое толкование'];
            $problems[] = ['Неоднозначная формулировка', 'amber'];
        } elseif ($h === 1) {
            $checks[] = ['ok' => false, 'text' => '[заготовка] Часть ответа не подтверждается источником'];
            $problems[] = ['Вне источника', 'amber'];
        } else {
            $checks[] = ['ok' => true, 'text' => 'Верный ответ подтверждается источником'];
        }

        $reject = Text::hash($draft['text'].'r', 12) === 0;
        $score = max(2, 9 - 2 * count($problems) - ($reject ? 4 : 0));

        $verdict = $reject ? 'reject' : ($problems ? 'dispute' : 'accept');
        $result = ['verdict' => $verdict, 'score' => $score, 'checks' => $checks];
        if ($problems) {
            $result['flag'] = ['text' => $problems[0][0], 'level' => $problems[0][1]];
        }

        return $result;
    }
}
