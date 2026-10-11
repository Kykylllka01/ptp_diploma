<?php

namespace App\Agents\Fake;

use App\Agents\Contracts\AnalystAgent;
use App\Agents\Support\Text;
use App\Models\Material;

/** Заготовка Аналитика: темы подбираются по совпадению слов названия материала с темами курса. */
class FakeAnalystAgent implements AnalystAgent
{
    public function analyze(Material $material, array $courseTopics): array
    {
        $titleStems = Text::stems($material->title.' '.$material->extracted_text);

        $topics = array_values(array_filter($courseTopics, function ($topic) use ($titleStems) {
            return (bool) array_intersect(Text::stems($topic), $titleStems);
        }));
        if (! $topics) {
            $topics = array_slice($courseTopics, Text::hash($material->title, max(1, count($courseTopics) - 1)), 2);
        }

        $fragments = [];
        foreach ($topics as $t) {
            $fragments[$t] = '…[заготовка] фрагмент материала «'.$material->title.'» по теме <mark>'.e($t).'</mark>…';
        }

        return [
            'topics' => $topics,
            'concepts' => 10 + Text::hash($material->title, 20),
            'examples' => 3 + Text::hash($material->title.'x', 10),
            'fragments' => $fragments,
        ];
    }
}
