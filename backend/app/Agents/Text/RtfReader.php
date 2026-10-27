<?php

namespace App\Agents\Text;

/**
 * Текст из RTF. Разбор управляющих слов: служебные группы (шрифты, стили, картинки,
 * колонтитулы, код полей) пропускаются, \'hh декодируется по кодовой странице шрифта
 * (\fcharset204 — Windows-1251) или документа (\ansicpg), \uN — Unicode. Знаки шрифта
 * Symbol отдаются как U+F0xx, их переводит DocumentTextExtractor — как и в .doc.
 */
class RtfReader
{
    /** Группы, текст которых не входит в документ. */
    private const SKIP = [
        'fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'object', 'objdata', 'header', 'headerl', 'headerr', 'headerf',
        'footer', 'footerl', 'footerr', 'footerf', 'listtable', 'listoverridetable', 'revtbl', 'rsidtbl',
        'latentstyles', 'themedata', 'colorschememapping', 'datastore', 'xmlnstbl', 'generator', 'filetbl', 'pgdsctbl',
        'mmathPr', 'footnote', 'annotation', 'atnid', 'atnauthor', 'bkmkstart', 'bkmkend', 'xe', 'tc', 'shppict', 'nonshppict',
    ];

    /** \fcharset → кодовая страница. */
    private const CHARSET = [
        0 => 1252, 161 => 1253, 162 => 1254, 163 => 1258, 177 => 1255, 178 => 1256, 186 => 1257,
        204 => 1251, 238 => 1250, 222 => 874, 128 => 932, 129 => 949, 134 => 936, 136 => 950,
    ];

    private const WORDS = [
        'par' => "\n\n", 'sect' => "\n\n", 'page' => "\n\n", 'line' => "\n", 'tab' => "\t", 'cell' => "\t", 'row' => "\n",
        'emdash' => '—', 'endash' => '–', 'lquote' => '‘', 'rquote' => '’', 'ldblquote' => '«', 'rdblquote' => '»', 'bullet' => '•',
    ];

    public function text(string $path): string
    {
        $rtf = (string) file_get_contents($path);
        if (! str_starts_with(ltrim($rtf), '{\rtf')) {
            throw new \RuntimeException('Файл не похож на RTF');
        }

        $out = '';
        $bytes = '';        // подряд идущие \'hh — декодируются вместе (многобайтовые кодировки)
        $bytesCp = 1252;
        $docCp = 1252;
        $fonts = [];        // номер шрифта → ['cp' => int, 'symbol' => bool]
        $fontNo = null;     // шрифт, который сейчас описывается в fonttbl
        $fontName = '';
        $st = ['skip' => false, 'uc' => 1, 'font' => null, 'fonttbl' => false, 'inst' => false];
        $inst = '';         // код поля: «Вставка → Символ» Word пишет как поле SYMBOL 174 \f "Symbol"
        $stack = [];
        $pendingSkip = 0;   // сколько запасных знаков пропустить после \uN

        $flush = function () use (&$out, &$bytes, &$bytesCp) {
            if ($bytes !== '') {
                $out .= $bytesCp === 0
                    ? implode('', array_map(fn ($b) => mb_chr(0xF000 + ord($b)), str_split($bytes)))
                    : self::decode($bytes, $bytesCp);
                $bytes = '';
            }
        };
        $deff = null;       // шрифт по умолчанию: им набран текст без явного \f
        $cpOf = function () use (&$st, &$fonts, &$docCp, &$deff) {
            $f = $fonts[$st['font'] ?? $deff] ?? null;

            return $f ? ($f['symbol'] ? 0 : $f['cp'] ?? $docCp) : $docCp;
        };
        $emit = function (string $s, bool $isByte = false) use (&$st, &$out, &$bytes, &$bytesCp, &$pendingSkip, $flush, $cpOf, &$fontName, &$inst) {
            if ($pendingSkip > 0) {
                $pendingSkip--;

                return;
            }
            if ($st['inst']) {
                $inst .= $s;

                return;
            }
            if ($st['fonttbl']) {
                $fontName .= $s;

                return;
            }
            if ($st['skip']) {
                return;
            }
            if ($isByte) {
                $cp = $cpOf();
                if ($bytes !== '' && $cp !== $bytesCp) {
                    $flush();
                }
                $bytesCp = $cp;
                $bytes .= $s;

                return;
            }
            $flush();
            if ($cpOf() === 0 && strlen($s) === 1 && ord($s) >= 0x20) {
                $s = mb_chr(0xF000 + ord($s));
            }
            $out .= $s;
        };

        $len = strlen($rtf);
        for ($i = 0; $i < $len; $i++) {
            $c = $rtf[$i];
            if ($c === '{') {
                $stack[] = $st;
                $pendingSkip = 0;
                continue;
            }
            if ($c === '}') {
                if ($st['fonttbl'] && $fontNo !== null) {
                    $this->defineFont($fonts, $fontNo, $fontName);
                }
                $closing = $st;
                $st = array_pop($stack) ?? $st;
                if ($closing['inst'] && ! $st['inst']) {
                    if (preg_match('/^\s*SYMBOL\s+(\d+)(?:.*?\\\\f\s+"?([^"\\\\]+))?/i', $inst, $sym)) {
                        $code = (int) $sym[1];
                        $font = $sym[2] ?? '';
                        $emit(mb_chr($code < 256 && preg_match('/symbol|wingdings|webdings/i', $font) ? 0xF000 + $code : $code) ?: '');
                    }
                    $inst = '';
                }
                if (! $stack) {
                    break;
                }
                continue;
            }
            if ($c === "\r" || $c === "\n") {
                continue;
            }
            if ($c !== '\\') {
                if ($st['fonttbl'] && $c === ';') {
                    if ($fontNo !== null) {
                        $this->defineFont($fonts, $fontNo, $fontName);
                    }
                    $fontName = '';
                    continue;
                }
                $emit($c, ord($c) > 0x7F);
                continue;
            }

            $next = $rtf[$i + 1] ?? '';
            if ($next === "'") {
                $emit(chr(hexdec(substr($rtf, $i + 2, 2))), true);
                $i += 3;
                continue;
            }
            if (! ctype_alpha($next)) {
                $i++;
                match ($next) {
                    '\\', '{', '}' => $emit($next),
                    '~' => $emit("\u{00A0}"),
                    '_' => $emit('-'),
                    '*' => $st['skip'] = true,      // неизвестная служебная группа
                    "\n", "\r" => $emit("\n\n"),
                    default => null,
                };
                continue;
            }

            preg_match('/\G([a-zA-Z]{1,32})(-?\d{1,10})? ?/', $rtf, $m, 0, $i + 1);
            $i += strlen($m[0]);
            $word = $m[1];
            $arg = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;

            if (in_array($word, self::SKIP, true)) {
                $st['skip'] = true;
                if ($word === 'fonttbl') {
                    $st['fonttbl'] = true;
                }
                continue;
            }
            switch ($word) {
                case 'fldinst':
                    $st['inst'] = true;
                    $inst = '';
                    break;
                case 'deff':
                    $deff = $arg;
                    break;
                case 'ansicpg':
                    $docCp = $arg ?: 1252;
                    break;
                case 'f':
                    if ($st['fonttbl']) {
                        $fontNo = $arg;
                        $fontName = '';
                        $fonts[$arg] ??= ['cp' => null, 'symbol' => false];
                    } else {
                        $st['font'] = $arg;
                    }
                    break;
                case 'fcharset':
                    if ($st['fonttbl'] && $fontNo !== null) {
                        $fonts[$fontNo]['cp'] = self::CHARSET[$arg] ?? null;
                        $fonts[$fontNo]['symbol'] = $arg === 2;
                    }
                    break;
                case 'bin':
                    // Двоичные данные могут содержать { и } — перешагиваем их целиком
                    $i += max(0, (int) $arg);
                    break;
                case 'uc':
                    $st['uc'] = max(0, (int) $arg);
                    break;
                case 'u':
                    $code = $arg < 0 ? $arg + 65536 : $arg;
                    $emit(mb_chr($code) ?: '');
                    $pendingSkip = $st['uc'];
                    break;
                default:
                    if (isset(self::WORDS[$word])) {
                        $emit(self::WORDS[$word]);
                    }
            }
        }
        $flush();

        return $out;
    }

    private static function decode(string $bytes, int $cp): string
    {
        $s = function_exists('iconv') ? @iconv('CP'.$cp, 'UTF-8//IGNORE', $bytes) : false;

        return $s !== false ? $s : (string) mb_convert_encoding($bytes, 'UTF-8', $cp === 1251 ? 'Windows-1251' : 'Windows-1252');
    }

    private function defineFont(array &$fonts, int $no, string $name): void
    {
        if (stripos($name, 'symbol') !== false) {
            $fonts[$no]['symbol'] = true;
        }
    }
}
