<?php

namespace App\Agents\Fake;

use App\Agents\Contracts\LlmClient;

/** Заглушка модели: ничего не вызывает, возвращает пометку. */
class FakeLlmClient implements LlmClient
{
    public function complete(string $system, string $prompt, array $options = []): string
    {
        return ! empty($options['json']) ? '{}' : '[ответ модели появится после подключения нейросети]';
    }
}
