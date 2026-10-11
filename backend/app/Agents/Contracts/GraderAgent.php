<?php

namespace App\Agents\Contracts;

use App\Models\Question;

/**
 * Агент «Оценщик»: предварительно оценивает развёрнутый ответ по критериям вопроса.
 * Итоговый балл всегда подтверждает преподаватель.
 */
interface GraderAgent
{
    /**
     * @return array{
     *   scores: array<int, array{0:int, 1:'ok'|'part'|'no', 2:string}>,  по критериям вопроса
     *   confidence: int,          0–100
     *   comment: string,          комментарий для студента
     *   highlighted: string       ответ (HTML-экранированный) с <mark> на найденных фрагментах
     * }
     */
    public function grade(Question $question, string $answer): array;
}
