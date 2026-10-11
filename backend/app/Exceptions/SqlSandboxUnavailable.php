<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Учебная база SQL не смогла выполнить запрос не по вине студента (не запустился php.exe,
 * завис эталон). Оценка не ставится: сдача откатывается, и её можно повторить.
 */
class SqlSandboxUnavailable extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => 'Учебная база SQL сейчас недоступна, ответы сохранены. Попробуйте ещё раз через минуту'], 503);
    }
}
