<?php

namespace App\Agents\Text;

use RuntimeException;

/**
 * Текст из документа Word 97–2003 (.doc) без внешних программ: на хостинге нет
 * LibreOffice и antiword. В потоке WordDocument лежит текст, в потоке 0Table/1Table —
 * таблица кусков (piece table), по которой текст собирается в правильном порядке:
 * куски бывают в UTF-16 и в однобайтовой кодировке. Возвращается основной текст
 * документа без колонтитулов и сносок.
 */
class MsDocReader
{
    public function text(string $path): string
    {
        $cfb = new CfbReader($path, 'документ Word 97–2003');
        $word = $cfb->stream('WordDocument');
        if (CfbReader::u16($word, 0) !== 0xA5EC) {
            throw new RuntimeException('В файле нет документа Word');
        }
        $flags = CfbReader::u16($word, 0x0A);
        if ($flags & 0x0100) {
            throw new RuntimeException('Документ защищён паролем');
        }
        $table = $cfb->stream($flags & 0x0200 ? '1Table' : '0Table');
        $ccpText = CfbReader::u32($word, 0x4C);
        $symbols = $this->symbolRuns($word, $table, CfbReader::u32($word, 0xFA), CfbReader::u32($word, 0xFE));

        return $this->pieces($word, $table, CfbReader::u32($word, 0x1A2), CfbReader::u32($word, 0x1A6), $ccpText, $symbols);
    }

    /**
     * Знак из «Вставка → Символ» хранится в тексте как «(», а настоящий код (U+F0AE —
     * стрелка шрифта Symbol) лежит в свойствах знака: sprmCSymbol в страницах CHPX.
     * Собираем такие участки как [начало, конец, код] в байтовых смещениях WordDocument.
     *
     * @return array<int, array{0: int, 1: int, 2: int}>
     */
    private function symbolRuns(string $word, string $table, int $fc, int $lcb): array
    {
        $runs = [];
        $n = $lcb >= 12 ? intdiv($lcb - 4, 8) : 0;
        for ($i = 0; $i < $n; $i++) {
            $page = substr($word, (CfbReader::u32($table, $fc + ($n + 1) * 4 + $i * 4) & 0x3FFFFF) * 512, 512);
            if (strlen($page) < 512) {
                continue;
            }
            $crun = ord($page[511]);
            for ($r = 0; $r < $crun; $r++) {
                $at = ord($page[4 * ($crun + 1) + $r]) * 2;
                if ($at === 0) {
                    continue;
                }
                $grpprl = substr($page, $at + 1, ord($page[$at]));
                for ($p = 0; $p + 2 <= strlen($grpprl);) {
                    $sprm = CfbReader::u16($grpprl, $p);
                    $p += 2;
                    if ($sprm === 0x6A09) {
                        $runs[] = [CfbReader::u32($page, $r * 4), CfbReader::u32($page, ($r + 1) * 4), CfbReader::u16($grpprl, $p + 2)];
                    }
                    $p += match ($sprm >> 13) {
                        0, 1 => 1,
                        2, 4, 5 => 2,
                        3 => 4,
                        7 => 3,
                        default => 1 + ord($grpprl[$p] ?? "\0"),
                    };
                }
            }
        }

        return $runs;
    }

    /** Таблица кусков: CP-границы и для каждого куска — смещение в WordDocument и кодировка. */
    private function pieces(string $word, string $table, int $fcClx, int $lcbClx, int $ccpText, array $symbols): string
    {
        $pos = $fcClx;
        $end = min($fcClx + $lcbClx, strlen($table));
        // Перед таблицей кусков могут идти блоки форматирования (Prc) — пропускаем
        while ($pos < $end && ord($table[$pos]) === 0x01) {
            $pos += 3 + unpack('s', substr($table, $pos + 1, 2))[1];
        }
        if ($pos >= $end || ord($table[$pos]) !== 0x02) {
            throw new RuntimeException('Не найдена таблица текста документа');
        }
        $lcb = CfbReader::u32($table, $pos + 1);
        $plc = $pos + 5;
        $n = intdiv($lcb - 4, 12);

        $text = '';
        for ($i = 0; $i < $n; $i++) {
            $cpStart = CfbReader::u32($table, $plc + $i * 4);
            $cpEnd = min(CfbReader::u32($table, $plc + ($i + 1) * 4), $ccpText);
            if ($cpStart >= $cpEnd) {
                continue;
            }
            $fc = CfbReader::u32($table, $plc + ($n + 1) * 4 + $i * 8 + 2);
            $chars = $cpEnd - $cpStart;
            // Кусок приводим к UTF-16LE: так k-й знак всегда по смещению 2k, и символы легко подставить
            if ($fc & 0x40000000) {
                $start = intdiv($fc & ~0x40000000, 2);
                $width = 1;
                $utf16 = mb_convert_encoding(substr($word, $start, $chars), 'UTF-16LE', 'Windows-1252');
            } else {
                $start = $fc;
                $width = 2;
                $utf16 = substr($word, $fc, $chars * 2);
            }
            foreach ($symbols as [$from, $to, $code]) {
                for ($b = max($from, $start); $b < min($to, $start + $chars * $width); $b += $width) {
                    $k = intdiv($b - $start, $width) * 2;
                    if (substr($utf16, $k, 2) === "(\0") {
                        $utf16 = substr_replace($utf16, pack('v', $code), $k, 2);
                    }
                }
            }
            $text .= mb_convert_encoding($utf16, 'UTF-8', 'UTF-16LE');
        }

        return $this->plain($text);
    }

    /**
     * Служебные знаки Word: \r — конец абзаца, \x07 — конец ячейки, \x0B — разрыв строки,
     * \x0C — разрыв страницы. Поля ({ PAGE }, оглавление, ссылки) записаны как
     * \x13 код \x14 результат \x15 — оставляем только результат.
     */
    private function plain(string $text): string
    {
        $out = '';
        $code = [];     // по уровню вложенности поля: идёт ли ещё код поля
        foreach (mb_str_split($text) as $ch) {
            if ($ch === "\x13") {
                $code[] = true;
                continue;
            }
            if ($ch === "\x14") {
                if ($code) {
                    $code[array_key_last($code)] = false;
                }
                continue;
            }
            if ($ch === "\x15") {
                array_pop($code);
                continue;
            }
            if (in_array(true, $code, true)) {
                continue;
            }
            $out .= match ($ch) {
                "\r", "\x0C" => "\n\n",
                "\x07" => "\t",
                "\x0B" => "\n",
                "\x1E" => '-',  // неразрывный дефис
                default => (mb_ord($ch) < 0x20 && $ch !== "\t") ? '' : $ch,
            };
        }

        return $out;
    }
}
