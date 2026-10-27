<?php

namespace App\Agents\Support;

/** Текстовые помощники агентов. */
class Text
{
    /** Основы значимых слов (первые 5 букв слов длиной от 5 букв). */
    public static function stems(string $text): array
    {
        preg_match_all('/[\p{L}]{5,}/u', mb_strtolower($text), $m);
        $stop = ['котор', 'други', 'нужно', 'может', 'также', 'чтобы', 'когда', 'этого', 'всего', 'будет', 'приве', 'объяс'];

        return array_values(array_diff(array_unique(array_map(fn ($w) => mb_substr($w, 0, 5), $m[0])), $stop));
    }

    /** Детерминированное «случайное» число 0..$mod-1 по строке. */
    public static function hash(string $text, int $mod): int
    {
        return crc32($text) % $mod;
    }

    public static function similarity(string $a, string $b): int
    {
        similar_text(mb_strtolower($a), mb_strtolower($b), $percent);

        return (int) round($percent);
    }

    /** Нормализация для сравнения цитат: регистр, ё/е, кавычки, тире и пробелы. */
    public static function norm(string $text): string
    {
        $text = mb_strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = strtr($text, ['ё' => 'е', '«' => '"', '»' => '"', '„' => '"', '“' => '"', '”' => '"', '’' => "'", '—' => '-', '–' => '-', "\u{00A0}" => ' ']);
        $text = preg_replace('/\[(?:с\.|слайд)\s*\d+\]/u', ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** Есть ли цитата в источнике (без учёта регистра, пробелов, кавычек и многоточий по краям). */
    public static function contains(string $source, string $quote): bool
    {
        $q = self::trimEdges(self::norm($quote), ' .…"\'');
        if (mb_strlen($q) < 8) {
            return false;
        }
        $s = self::norm($source);
        if (str_contains($s, $q)) {
            return true;
        }
        // Цитата из нескольких кусков через «…»: каждый кусок должен найтись
        $parts = array_filter(array_map(fn ($p) => trim($p, " .\"'"), preg_split('/\.{3}|…/u', $q)), fn ($p) => mb_strlen($p) >= 8);

        return count($parts) > 1 && collect($parts)->every(fn ($p) => str_contains($s, $p));
    }

    /**
     * trim() по символам, а не по байтам. Обычный trim с «…» в списке отрезает байты
     * 0x80 и 0xA6 у последней буквы («р», «Ц»), строка становится невалидным UTF-8,
     * и preg_* дальше возвращают false.
     */
    public static function trimEdges(string $text, string $chars): string
    {
        $set = preg_quote($chars, '/');

        return preg_replace('/^['.$set.']+|['.$set.']+$/u', '', $text) ?? trim($text);
    }

    /**
     * Фрагмент источника вокруг цитаты: HTML-экранированный, цитата в <mark>.
     * Если цитата не найдена дословно, возвращается сама цитата в <mark>.
     */
    public static function excerpt(string $source, string $quote, int $around = 220): string
    {
        $flat = trim(preg_replace('/\s+/u', ' ', $source));
        $q = self::trimEdges(preg_replace('/\s+/u', ' ', $quote), ' .…"«»');
        $pos = $q !== '' ? mb_stripos($flat, $q) : false;
        if ($pos === false) {
            return '<mark>'.e($q).'</mark>';
        }
        $start = max(0, $pos - $around);
        $end = min(mb_strlen($flat), $pos + mb_strlen($q) + $around);

        return ($start > 0 ? '…' : '')
            .e(mb_substr($flat, $start, $pos - $start))
            .'<mark>'.e(mb_substr($flat, $pos, mb_strlen($q))).'</mark>'
            .e(mb_substr($flat, $pos + mb_strlen($q), $end - $pos - mb_strlen($q)))
            .($end < mb_strlen($flat) ? '…' : '');
    }

    /**
     * Нарезка текста на фрагменты по ~$max символов. Метки страниц и слайдов
     * вида [с. 12] / [слайд 3] превращаются в место фрагмента: «с. 12–13».
     *
     * @return array<int, array{place: string, text: string}>
     */
    public static function chunks(string $text, int $max): array
    {
        $parts = preg_split('/(\[(?:с\.|слайд)\s*\d+\])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $units = [];   // [метка, текст]
        $label = null;
        foreach ($parts as $p) {
            if (preg_match('/^\[(с\.|слайд)\s*(\d+)\]$/u', $p, $m)) {
                $label = [$m[1] === 'с.' ? 'с.' : 'слайды', (int) $m[2]];
                continue;
            }
            foreach (preg_split('/\n\s*\n/u', $p) as $para) {
                $para = trim($para);
                if ($para === '') {
                    continue;
                }
                // Слишком длинный абзац режем по предложениям
                while (mb_strlen($para) > $max) {
                    $cut = mb_strrpos(mb_substr($para, 0, $max), '. ') ?: $max;
                    $units[] = [$label, mb_substr($para, 0, $cut + 1)];
                    $para = trim(mb_substr($para, $cut + 1));
                }
                $units[] = [$label, $para];
            }
        }

        $chunks = [];
        $buf = '';
        $labels = [];
        $flush = function () use (&$chunks, &$buf, &$labels) {
            if (trim($buf) === '') {
                return;
            }
            $place = 'фрагмент '.(count($chunks) + 1);
            if ($labels) {
                $kind = $labels[0][0];
                $nums = array_column($labels, 1);
                $from = min($nums);
                $to = max($nums);
                $place = ($kind === 'с.' ? 'с. ' : ($from === $to ? 'слайд ' : 'слайды ')).$from.($to > $from ? '–'.$to : '');
            }
            $chunks[] = ['place' => $place, 'text' => trim($buf)];
            $buf = '';
            $labels = [];
        };
        foreach ($units as [$l, $t]) {
            if ($buf !== '' && mb_strlen($buf) + mb_strlen($t) > $max) {
                $flush();
            }
            $buf .= ($buf === '' ? '' : "\n\n").$t;
            if ($l) {
                $labels[] = $l;
            }
        }
        $flush();

        return $chunks;
    }
}
