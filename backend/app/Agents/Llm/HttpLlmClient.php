<?php

namespace App\Agents\Llm;

use App\Agents\Contracts\LlmClient;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Клиент к OpenAI-совместимому API (/chat/completions): DeepSeek, Yandex AI Studio,
 * локальная модель (LM Studio, Ollama, vLLM). Настройки — в .env: LLM_API_URL,
 * LLM_API_KEY, LLM_MODEL. При 429, 5xx и обрыве соединения запрос повторяется
 * с нарастающей паузой.
 */
class HttpLlmClient implements LlmClient
{
    public function __construct(private array $config)
    {
    }

    public function complete(string $system, string $prompt, array $options = []): string
    {
        return $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $prompt],
        ], $options)['content'];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array  $options  model, temperature, max_tokens, effort (low|high), json
     * @return array{content: string, model: string, tokens_in: int, tokens_out: int, tokens_reasoning: int, ms: int}
     */
    public function chat(array $messages, array $options = []): array
    {
        if (empty($this->config['url']) || empty($this->config['key'])) {
            throw new RuntimeException('Нейросеть не подключена: заполните LLM_API_URL и LLM_API_KEY в .env');
        }

        $model = $options['model'] ?? $this->config['model'];
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.3,
        ];
        if (! empty($options['max_tokens'])) {
            $payload['max_tokens'] = $options['max_tokens'];
        }
        if (! empty($options['effort'])) {
            $payload['reasoning_effort'] = $options['effort'];
        }
        if (! empty($options['json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $retries = (int) ($this->config['retries'] ?? 3);
        $started = microtime(true);
        for ($attempt = 0; ; $attempt++) {
            try {
                $response = Http::withToken((string) $this->config['key'])
                    ->timeout($this->config['timeout'] ?? 300)
                    ->connectTimeout(20)
                    ->post(rtrim($this->config['url'], '/').'/chat/completions', $payload)
                    ->throw();
                break;
            } catch (ConnectionException|RequestException|TransferException $e) {
                // TransferException — ошибки cURL (обрыв, сбой проверки сертификата): тоже повторяем
                $status = $e instanceof RequestException ? $e->response->status() : 0;
                $retryable = $status === 0 || $status === 429 || $status >= 500;
                if (! $retryable || $attempt >= $retries) {
                    $body = $e instanceof RequestException ? mb_substr((string) $e->response->body(), 0, 500) : $e->getMessage();
                    throw new RuntimeException('Ошибка API модели'.($status ? ' (HTTP '.$status.')' : '').': '.$body, 0, $e);
                }
                sleep(2 ** ($attempt + 1));
            }
        }

        if ($response->json('choices.0.finish_reason') === 'length') {
            throw new RuntimeException('Ответ модели обрезан: не хватило max_tokens');
        }

        return [
            'content' => (string) $response->json('choices.0.message.content', ''),
            'model' => (string) $response->json('model', $model),
            'tokens_in' => (int) $response->json('usage.prompt_tokens', 0),
            'tokens_out' => (int) $response->json('usage.completion_tokens', 0),
            'tokens_reasoning' => (int) $response->json('usage.completion_tokens_details.reasoning_tokens', 0),
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}
