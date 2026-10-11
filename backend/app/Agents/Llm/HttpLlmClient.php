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
 * LLM_API_KEY, LLM_MODEL. Повторяется только то, что точно не дошло до модели или
 * отклонено ею без оплаты: 429, 5xx, сбой DNS, соединения или TLS. Таймаут чтения
 * не повторяется — запрос уже обработан и оплачен.
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
     * @param  array  $options  model, temperature, max_tokens, effort (low|high), thinking (true|false|null), json
     * @return array{content: string, model: string, tokens_in: int, tokens_cached: int, tokens_out: int, tokens_reasoning: int, ms: int}
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
        // Режим рассуждения (DeepSeek: thinking.type). Без рассуждения модель отвечает сразу —
        // в разы меньше выходных токенов там, где задача простая (выписать понятия, теги)
        if (($options['thinking'] ?? null) === false) {
            $payload['thinking'] = ['type' => 'disabled'];
        } else {
            if (($options['thinking'] ?? null) === true) {
                $payload['thinking'] = ['type' => 'enabled'];
            }
            if (! empty($options['effort'])) {
                $payload['reasoning_effort'] = $options['effort'];
            }
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
                $status = $e instanceof RequestException ? $e->response->status() : 0;
                $retryable = $status === 429 || $status >= 500 || ($status === 0 && self::notSent($e->getMessage()));
                if (! $retryable || $attempt >= $retries) {
                    $body = $e instanceof RequestException ? mb_substr((string) $e->response->body(), 0, 500) : $e->getMessage();
                    throw new RuntimeException('Ошибка API модели'.($status ? ' (HTTP '.$status.')' : '').': '.$body, 0, $e);
                }
                // 429 — лимит частоты: ждём, сколько просит сервер, иначе дольше обычного
                $wait = $status === 429 ? max(5, (int) ($e->response->header('Retry-After') ?: 0), 5 * 2 ** $attempt) : 2 ** ($attempt + 1);
                sleep(min(60, $wait));
            }
        }

        $result = [
            'content' => (string) $response->json('choices.0.message.content', ''),
            'model' => (string) $response->json('model', $model),
            'tokens_in' => (int) $response->json('usage.prompt_tokens', 0),
            // DeepSeek кэширует общий префикс запросов: эти входные токены стоят в 50 раз дешевле
            'tokens_cached' => (int) $response->json('usage.prompt_cache_hit_tokens', 0),
            'tokens_out' => (int) $response->json('usage.completion_tokens', 0),
            'tokens_reasoning' => (int) $response->json('usage.completion_tokens_details.reasoning_tokens', 0),
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
        if ($response->json('choices.0.finish_reason') === 'length') {
            // Токены уже потрачены — передаём их в журнал вместе с ошибкой
            throw new LlmTruncated('Ответ модели обрезан: не хватило max_tokens', $result);
        }

        return $result;
    }

    /**
     * Сбой до отправки запроса: имя не разрешилось, соединение не установилось, TLS не согласован.
     * Такой запрос модель не видела, повтор ничего не стоит. «Operation timed out … bytes received»
     * и обрыв во время ответа сюда не входят: запрос мог быть обработан и оплачен.
     */
    private static function notSent(string $message): bool
    {
        return (bool) preg_match('/Could not resolve|Failed to connect|Connection timed out|Connection refused|SSL connect|handshake|certificate|cURL error (6|7|35|60):/i', $message);
    }
}
