<?php

namespace App\Agents\Contracts;

use App\Models\Material;

/**
 * Агент 02 «Составитель»: генерирует черновики вопросов по результатам разбора.
 */
interface WriterAgent
{
    /**
     * План по материалу: какие вопросы (тип, сложность) составить, разбитые на порции.
     * Порция — один запрос к модели; конвейер выполняет их по одной, частями запуска.
     *
     * @param  array  $analysis  результат AnalystAgent::analyze()
     * @param  array  $params  types[], difficulty (easy|medium|hard|mixed), bloom[], count
     * @return array<int, array{slots: array}> порции; кроме slots агент кладёт в них то, что нужно write()
     */
    public function plan(array $analysis, array $params, int $count): array;

    /**
     * Черновики вопросов одной порции плана.
     *
     * @param  array  $portion  элемент из plan()
     * @return array<int, array{
     *   type: string, difficulty: string, topic: string, source: string, points: int,
     *   text: string, options?: array, explanation?: string, reference?: string,
     *   criteria?: array, sql_schema?: string, quote?: string
     * }>
     */
    public function write(Material $material, array $analysis, array $params, array $portion): array;

    /** Переписать вопрос с учётом замечаний критика. */
    public function rewrite(array $draft, array $criticChecks): array;
}
