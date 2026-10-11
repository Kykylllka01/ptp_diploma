<?php

namespace App\Agents\Llm;

use App\Models\LlmCall;
use RuntimeException;
use Throwable;

/**
 * Вызов модели от имени агента: системный промпт из resources/prompts/<версия>.md,
 * данные в сообщении пользователя, ответ — JSON. Ответ разбирается и проверяется
 * функцией агента; если проверка не прошла, запрос повторяется один раз с текстом
 * ошибки. Каждый вызов пишется в таблицу llm_calls.
 */
class AgentLlm
{
    /** Связь вызовов с запуском генерации или ответом студента (для журнала). */
    private static array $context = [];

    public function __construct(private HttpLlmClient $client)
    {
    }

    public static function context(array $context): void
    {
        self::$context = $context;
    }

    /**
     * @param  string  $agent  analyst | writer | critic | catalog | grader
     * @param  array  $vars  подстановки {{name}} в системный промпт
     * @param  callable|null  $validate  fn(array $json): ?string — текст ошибки или null
     */
    public function json(string $agent, string $user, array $vars = [], ?callable $validate = null): array
    {
        $cfg = config('agents.agents.'.$agent);
        $system = $this->prompt($cfg['prompt'], $vars);
        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
        $options = [
            'model' => $cfg['model'],
            'temperature' => $cfg['temperature'],
            'max_tokens' => $cfg['max_tokens'],
            'effort' => $cfg['effort'] ?? null,
            'json' => true,
        ];

        $lastError = null;
        for ($try = 0; $try < 2; $try++) {
            $log = [
                'agent' => $agent,
                'model' => $cfg['model'],
                'prompt_version' => $cfg['prompt'],
                'generation_run_id' => self::$context['generation_run_id'] ?? null,
                'answer_id' => self::$context['answer_id'] ?? null,
                'request' => $messages[count($messages) - 1]['content'],
            ];
            try {
                $r = $this->client->chat($messages, $options);
            } catch (Throwable $e) {
                LlmCall::create($log + ['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
                throw $e;
            }

            $log += [
                'model' => $r['model'],
                'tokens_in' => $r['tokens_in'],
                'tokens_out' => $r['tokens_out'],
                'tokens_reasoning' => $r['tokens_reasoning'],
                'ms' => $r['ms'],
                'response' => $r['content'],
            ];

            $json = self::decode($r['content']);
            $error = $json === null ? 'ответ не является JSON-объектом' : ($validate ? $validate($json) : null);
            LlmCall::create($log + ['status' => $error ? 'invalid_json' : 'ok', 'error' => $error]);

            if (! $error) {
                return $json;
            }

            $lastError = $error;
            $messages[] = ['role' => 'assistant', 'content' => $r['content']];
            $messages[] = ['role' => 'user', 'content' => 'Ответ не соответствует требованиям: '.$error.'. Верни исправленный JSON целиком, без пояснений.'];
        }

        throw new RuntimeException('Агент «'.$agent.'» вернул неверный ответ: '.$lastError);
    }

    private function prompt(string $version, array $vars): string
    {
        $path = resource_path('prompts/'.$version.'.md');
        if (! is_file($path)) {
            throw new RuntimeException('Нет файла промпта '.$path);
        }
        $text = (string) file_get_contents($path);
        foreach ($vars as $k => $v) {
            $text = str_replace('{{'.$k.'}}', (string) $v, $text);
        }

        return $text;
    }

    /** JSON из ответа модели: допускает обёртку ```json … ```. */
    public static function decode(string $content): ?array
    {
        $content = trim($content);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $content, $m)) {
            $content = $m[1];
        }
        $data = json_decode($content, true);

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }
}
