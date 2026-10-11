<?php

namespace App\Agents\Llm;

use RuntimeException;

/** Ответ модели обрезан по max_tokens. Несёт usage, чтобы потраченные токены попали в журнал. */
class LlmTruncated extends RuntimeException
{
    public function __construct(string $message, public readonly array $usage)
    {
        parent::__construct($message);
    }
}
