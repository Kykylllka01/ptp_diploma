<?php

namespace App\Support;

/**
 * Имя человека в трёх видах, как их показывает сайт: «Петрова А. С.» (шапка и списки),
 * «Анна Сергеевна» (обращение) и «ПА» (аватар без картинки).
 */
class PersonName
{
    /** @return array{name: string, full_name: string, initials: string} */
    public static function make(string $last, string $first = '', string $middle = ''): array
    {
        [$last, $first, $middle] = [self::word($last), self::word($first), self::word($middle)];

        return [
            'name' => trim($last.($first !== '' ? ' '.mb_substr($first, 0, 1).'.' : '').($middle !== '' ? ' '.mb_substr($middle, 0, 1).'.' : '')),
            'full_name' => trim($first.' '.$middle) ?: $last,
            'initials' => mb_strtoupper(mb_substr($last, 0, 1).($first !== '' ? mb_substr($first, 0, 1) : mb_substr($last, 1, 1))),
        ];
    }

    /** «Фамилия Имя Отчество» одной строкой. */
    public static function fromFull(string $full): array
    {
        $parts = preg_split('/\s+/u', trim($full)) ?: [''];

        return self::make($parts[0], $parts[1] ?? '', $parts[2] ?? '');
    }

    /** «иВАНОВ» → «Иванов», «анна-мария» → «Анна-Мария». */
    public static function word(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', trim($s));

        return $s === '' ? '' : mb_convert_case(mb_strtolower($s), MB_CASE_TITLE);
    }
}
