<?php

namespace App\Agents\Contracts;

/**
 * Агент 03 «Критик»: проверяет корректность и качество вопроса.
 */
interface CriticAgent
{
    /**
     * @param  array  $draft  черновик вопроса (поля как у модели Question)
     * @return array{
     *   verdict: 'accept'|'dispute'|'reject',
     *   score: int,                                   0–10
     *   checks: array<int, array{ok: bool, text: string}>,
     *   flag?: array{text: string, level: 'red'|'amber'},
     *   fix?: array{optionIndex: int, text: string}   предложение по исправлению
     * }
     */
    public function review(array $draft): array;
}
