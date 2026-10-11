<?php

namespace App\Agents\Contracts;

use App\Models\Question;
use Illuminate\Support\Collection;

/**
 * Агент 04 «Каталогизатор»: теги, уровень по Блуму, сложность и поиск дублей в банке.
 */
interface CatalogAgent
{
    /**
     * @param  Collection<int, Question>  $bank  вопросы курса, с которыми сравнивать
     * @return array{tags: string[], difficulty: string, similar?: array{code: string, percent: int}}
     */
    public function catalog(Question $question, Collection $bank): array;
}
