<?php

namespace App\Agents\Contracts;

use App\Models\Material;

/**
 * Агент 02 «Составитель»: генерирует черновики вопросов по результатам разбора.
 */
interface WriterAgent
{
    /**
     * @param  array  $analysis  результат AnalystAgent::analyze()
     * @param  array  $params  types[], difficulty (easy|medium|hard|mixed), bloom[], count
     * @return array<int, array{
     *   type: string, difficulty: string, topic: string, source: string, points: int,
     *   text: string, options?: array, explanation?: string, reference?: string,
     *   criteria?: array, sql_schema?: string, quote?: string
     * }>
     */
    public function generate(Material $material, array $analysis, array $params, int $count): array;

    /** Переписать вопрос с учётом замечаний критика. */
    public function rewrite(array $draft, array $criticChecks): array;
}
