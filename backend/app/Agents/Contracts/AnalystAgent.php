<?php

namespace App\Agents\Contracts;

use App\Models\Material;

/**
 * Агент 01 «Аналитик»: разбирает материал курса, выделяет темы, понятия и примеры.
 */
interface AnalystAgent
{
    /**
     * @return array{
     *   topics: string[],
     *   concepts: int,
     *   examples: int,
     *   fragments: array<string, string>   тема => фрагмент источника для цитаты
     * }
     */
    public function analyze(Material $material, array $courseTopics): array;
}
