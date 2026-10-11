<?php

namespace App\Agents\Llm;

use RuntimeException;

/** Модель ответила, но не по формату (не JSON или не прошла проверку агента) даже после повтора. */
class LlmInvalidAnswer extends RuntimeException
{
}
