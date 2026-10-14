<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Курс доллара для пересчёта расходов на нейросеть в рубли: официальный курс ЦБ на сегодня
 * (cbr-xml-daily.ru, кэш на 12 часов). Если сайт недоступен — курс из USD_RUB_RATE.
 */
class UsdRate
{
    private const URL = 'https://www.cbr-xml-daily.ru/daily_json.js';

    /** @return array{rate: float, date: ?string, source: string} */
    public static function get(): array
    {
        $fallback = ['rate' => (float) config('agents.usd_rub.rate'), 'date' => null, 'source' => 'config'];
        if (! config('agents.usd_rub.live')) {
            return $fallback;
        }

        $cached = Cache::get('usd-rub-rate');
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $json = Http::timeout(3)->acceptJson()->get(self::URL)->throw()->json();
            $rate = (float) ($json['Valute']['USD']['Value'] ?? 0);
            if ($rate > 0) {
                $live = ['rate' => round($rate, 2), 'date' => isset($json['Date']) ? substr((string) $json['Date'], 0, 10) : null, 'source' => 'cbr'];
                Cache::put('usd-rub-rate', $live, now()->addHours(12));

                return $live;
            }
        } catch (Throwable $e) {
            report($e);
        }
        // Сайт ЦБ недоступен: курс из настроек, повторная попытка через час, а не на каждой странице
        Cache::put('usd-rub-rate', $fallback, now()->addHour());

        return $fallback;
    }
}
