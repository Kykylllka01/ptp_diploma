<?php

namespace App\Agents\Contracts;

/**
 * Клиент языковой модели. Через него настоящие агенты будут обращаться к нейросети.
 */
interface LlmClient
{
    /**
     * @param  string  $system  системный промпт (роль агента)
     * @param  string  $prompt  задание с материалом
     * @param  array  $options  temperature, max_tokens, json (true — ждать JSON-ответ)
     */
    public function complete(string $system, string $prompt, array $options = []): string;
}
