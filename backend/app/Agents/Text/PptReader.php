<?php

namespace App\Agents\Text;

/**
 * Текст из презентации PowerPoint 97–2003 (.ppt) без внешних программ. Поток
 * «PowerPoint Document» — дерево записей; текст лежит в TextCharsAtom (UTF-16) и
 * TextBytesAtom (Latin-1). Порядок слайдов и заметки берутся так же, как их находит
 * PowerPoint: Current User → последняя правка → каталог объектов (persist directory)
 * → документ → список слайдов. Если файл устроен иначе, собираем текст слайдов подряд.
 */
class PptReader
{
    private const DOCUMENT = 0x03E8;

    private const SLIDE = 0x03EE;

    private const SLIDE_ATOM = 0x03EF;

    private const SLIDE_PERSIST = 0x03F3;

    private const SLIDE_LIST = 0x0FF0;

    private const USER_EDIT = 0x0FF5;

    private const PERSIST_DIRECTORY = 0x1772;

    private const TEXT_CHARS = 0x0FA0;

    private const TEXT_BYTES = 0x0FA8;

    private string $doc;

    /**
     * @return array<int, array{text: string, notes: string}> по порядку показа
     */
    public function slides(string $path): array
    {
        $cfb = new CfbReader($path, 'презентацию PowerPoint 97–2003');
        $this->doc = $cfb->stream('PowerPoint Document');

        $slides = $cfb->has('Current User') ? $this->ordered($cfb->stream('Current User')) : [];

        return $slides ?: $this->sequential();
    }

    private function ordered(string $currentUser): array
    {
        $persist = [];
        $docRef = null;
        $edit = CfbReader::u32($currentUser, 16);
        // Правки идут цепочкой от новой к старой; более новая запись объекта главнее
        for ($guard = 0; $edit > 0 && $guard < 1000 && $this->type($edit) === self::USER_EDIT; $guard++) {
            $docRef ??= CfbReader::u32($this->doc, $edit + 8 + 16);
            $dir = CfbReader::u32($this->doc, $edit + 8 + 12);
            if ($this->type($dir) === self::PERSIST_DIRECTORY) {
                $end = $dir + 8 + CfbReader::u32($this->doc, $dir + 4);
                for ($p = $dir + 8; $p + 4 <= $end;) {
                    $v = CfbReader::u32($this->doc, $p);
                    $p += 4;
                    for ($j = 0; $j < ($v >> 20) && $p + 4 <= $end; $j++, $p += 4) {
                        $persist[($v & 0xFFFFF) + $j] ??= CfbReader::u32($this->doc, $p);
                    }
                }
            }
            $edit = CfbReader::u32($this->doc, $edit + 8 + 8);
        }
        $docAt = $persist[$docRef] ?? null;
        if ($docAt === null || $this->type($docAt) !== self::DOCUMENT) {
            return [];
        }

        // Списки: instance 0 — слайды, 2 — заметки. За SlidePersistAtom идёт текст заглушек этого слайда
        $lists = [0 => [], 2 => []];
        foreach ($this->children($docAt) as $r) {
            if ($r['type'] !== self::SLIDE_LIST || ! isset($lists[$r['inst']])) {
                continue;
            }
            $cur = null;
            foreach ($this->children($r['at']) as $c) {
                if ($c['type'] === self::SLIDE_PERSIST) {
                    $cur = CfbReader::u32($this->doc, $c['data'] + 12);   // slideId
                    $lists[$r['inst']][$cur] = ['ref' => CfbReader::u32($this->doc, $c['data']), 'texts' => []];
                } elseif ($cur !== null && ($t = $this->atomText($c)) !== null) {
                    $lists[$r['inst']][$cur]['texts'][] = $t;
                }
            }
        }

        $out = [];
        foreach ($lists[0] as $slide) {
            $texts = $slide['texts'];
            $notes = [];
            $at = $persist[$slide['ref']] ?? null;
            if ($at !== null && $this->type($at) === self::SLIDE) {
                $this->collect($at, $texts);
                foreach ($this->children($at) as $c) {
                    if ($c['type'] === self::SLIDE_ATOM) {
                        $note = $lists[2][CfbReader::u32($this->doc, $c['data'] + 16)] ?? null;
                        if ($note) {
                            $notes = $note['texts'];
                            if (($nAt = $persist[$note['ref']] ?? null) !== null) {
                                $this->collect($nAt, $notes);
                            }
                        }
                    }
                }
            }
            $out[] = ['text' => $this->join($texts), 'notes' => $this->join($notes)];
        }

        return $out;
    }

    /** Запасной путь: контейнеры слайдов в порядке записи в файле. */
    private function sequential(): array
    {
        $out = [];
        foreach ($this->children(-8, strlen($this->doc)) as $r) {
            if ($r['type'] === self::SLIDE) {
                $texts = [];
                $this->collect($r['at'], $texts);
                $out[] = ['text' => $this->join($texts), 'notes' => ''];
            }
        }

        return $out;
    }

    /** Весь текст внутри записи (рекурсивно), без повторов. */
    private function collect(int $at, array &$texts, int $depth = 0): void
    {
        if ($depth > 50) {
            return;
        }
        foreach ($this->children($at) as $c) {
            if ($c['ver'] === 0xF) {
                $this->collect($c['at'], $texts, $depth + 1);
            } elseif (($t = $this->atomText($c)) !== null && ! in_array($t, $texts, true)) {
                $texts[] = $t;
            }
        }
    }

    private function atomText(array $r): ?string
    {
        $raw = substr($this->doc, $r['data'], $r['len']);
        $t = match ($r['type']) {
            self::TEXT_CHARS => mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE'),
            self::TEXT_BYTES => mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1'),
            default => null,
        };
        if ($t === null) {
            return null;
        }
        // \r — конец абзаца, \x0B — разрыв строки; «*» — поле номера слайда
        $t = trim(preg_replace('/[\x00-\x08\x0C\x0E-\x1F]/u', '', strtr($t, ["\r" => "\n", "\x0B" => "\n"])) ?? '');

        return $t === '' || $t === '*' ? null : $t;
    }

    private function join(array $texts): string
    {
        return implode("\n", $texts);
    }

    /**
     * Прямые потомки контейнера по смещению его заголовка (или -8 и длина — для всего потока).
     *
     * @return \Generator<array{type: int, inst: int, ver: int, at: int, data: int, len: int}>
     */
    private function children(int $at, ?int $len = null): \Generator
    {
        $p = $at + 8;
        $end = min($p + ($len ?? CfbReader::u32($this->doc, $at + 4)), strlen($this->doc));
        while ($p + 8 <= $end) {
            $vi = CfbReader::u16($this->doc, $p);
            $n = CfbReader::u32($this->doc, $p + 4);
            yield ['type' => CfbReader::u16($this->doc, $p + 2), 'inst' => $vi >> 4, 'ver' => $vi & 0xF, 'at' => $p, 'data' => $p + 8, 'len' => $n];
            $p += 8 + $n;
        }
    }

    private function type(int $at): int
    {
        return $at + 8 <= strlen($this->doc) ? CfbReader::u16($this->doc, $at + 2) : -1;
    }
}
