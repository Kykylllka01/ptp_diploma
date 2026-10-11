<?php

namespace App\Console\Commands;

use App\Services\AttemptService;
use Illuminate\Console\Command;

/**
 * Сдать брошенные попытки: время вышло, срок прошёл или приём закрыт, а студент не нажал «Завершить».
 * Страницы делают это сами при открытии; команда — для планировщика (schedule:run раз в минуту).
 */
class FinalizeAttempts extends Command
{
    protected $signature = 'attempts:finalize';

    protected $description = 'Сдать попытки, у которых вышло время, с уже сохранёнными ответами';

    public function handle(AttemptService $attempts): int
    {
        $this->info('Сдано попыток: '.$attempts->finalizeOverdue());

        return self::SUCCESS;
    }
}
