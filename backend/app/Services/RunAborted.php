<?php

namespace App\Services;

use RuntimeException;

/** Запуск уже помечен упавшим извне (задача очереди признана зависшей) — конвейер просто останавливается. */
class RunAborted extends RuntimeException
{
}
