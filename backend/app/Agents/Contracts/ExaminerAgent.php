<?php

namespace App\Agents\Contracts;

use App\Models\Question;
use Illuminate\Support\Collection;

/**
 * Агент «Экзаменатор»: подбирает вопросы для теста. В адаптивном режиме
 * выбирает следующий вопрос по ответам студента.
 */
interface ExaminerAgent
{
    /**
     * Сборка теста по матрице «тип × сложность».
     *
     * @param  Collection<int, Question>  $pool  одобренные вопросы банка
     * @param  array  $matrix  [[type, points, easy, medium, hard], ...]
     * @param  string[]  $topics
     * @return Collection<int, Question>
     */
    public function assemble(Collection $pool, array $matrix, array $topics): Collection;

    /**
     * Адаптивный режим: следующий вопрос по истории ответов.
     *
     * @param  array<int, array{difficulty: string, correct: bool}>  $history
     */
    public function next(Collection $pool, array $history): ?Question;
}
