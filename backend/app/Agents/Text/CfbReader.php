<?php

namespace App\Agents\Text;

use RuntimeException;

/**
 * Составной документ OLE (CFB) — контейнер старых форматов Office: .doc, .ppt.
 * Внутри — «файловая система» из потоков; отдаёт поток по имени.
 */
class CfbReader
{
    private const END_OF_CHAIN = -2;

    private string $data;

    private int $sectorSize;

    private int $miniSectorSize;

    private int $miniCutoff;

    /** @var array<int, int> */
    private array $fat = [];

    /** @var array<int, int> */
    private array $miniFat = [];

    /** @var array<string, array{start: int, size: int}> */
    private array $streams = [];

    private string $miniStream = '';

    public function __construct(string $path, string $what = 'документ Office 97–2003')
    {
        $this->data = (string) file_get_contents($path);
        if (strlen($this->data) < 512 || substr($this->data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new RuntimeException('Файл не похож на '.$what);
        }
        $this->read();
    }

    public function has(string $name): bool
    {
        return isset($this->streams[$name]);
    }

    public function stream(string $name): string
    {
        $e = $this->streams[$name] ?? throw new RuntimeException('В файле нет потока '.$name);
        if ($e['size'] < $this->miniCutoff) {
            $out = '';
            for ($s = $e['start'], $guard = 0; $s >= 0 && $guard < 1000000; $s = $this->miniFat[$s] ?? self::END_OF_CHAIN, $guard++) {
                $out .= substr($this->miniStream, $s * $this->miniSectorSize, $this->miniSectorSize);
            }

            return substr($out, 0, $e['size']);
        }

        return substr($this->chain($e['start']), 0, $e['size']);
    }

    private function read(): void
    {
        $this->sectorSize = 1 << self::u16($this->data, 0x1E);
        $this->miniSectorSize = 1 << self::u16($this->data, 0x20);
        $this->miniCutoff = self::u32($this->data, 0x38);

        // Номера секторов FAT: первые 109 — в заголовке, остальные — в цепочке DIFAT
        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $fatSectors[] = self::i32($this->data, 0x4C + $i * 4);
        }
        $difat = self::i32($this->data, 0x44);
        $perSector = intdiv($this->sectorSize, 4) - 1;
        for ($guard = 0; $difat >= 0 && $guard < 10000; $guard++) {
            $sector = $this->sector($difat);
            for ($i = 0; $i < $perSector; $i++) {
                $fatSectors[] = self::i32($sector, $i * 4);
            }
            $difat = self::i32($sector, $perSector * 4);
        }
        foreach (array_slice($fatSectors, 0, self::u32($this->data, 0x2C)) as $s) {
            if ($s >= 0) {
                array_push($this->fat, ...array_values(unpack('l*', $this->sector($s))));
            }
        }

        $dir = $this->chain(self::i32($this->data, 0x30));
        $root = null;
        for ($off = 0; $off + 128 <= strlen($dir); $off += 128) {
            $type = ord($dir[$off + 66]);
            if ($type !== 2 && $type !== 5) {
                continue;
            }
            $nameLen = self::u16($dir, $off + 64);
            $name = mb_convert_encoding(substr($dir, $off, max(0, $nameLen - 2)), 'UTF-8', 'UTF-16LE');
            $entry = ['start' => self::i32($dir, $off + 116), 'size' => self::u32($dir, $off + 120)];
            if ($type === 5) {
                $root = $entry;
            } else {
                $this->streams[$name] ??= $entry;
            }
        }

        $miniFatStart = self::i32($this->data, 0x3C);
        if ($miniFatStart >= 0) {
            $this->miniFat = array_values(unpack('l*', $this->chain($miniFatStart)));
        }
        if ($root && $root['start'] >= 0) {
            $this->miniStream = substr($this->chain($root['start']), 0, $root['size']);
        }
    }

    private function chain(int $start): string
    {
        $out = '';
        // Ограничение длины цепочки защищает от зацикленной FAT в испорченном файле
        for ($s = $start, $guard = 0; $s >= 0 && $guard < 1000000; $s = $this->fat[$s] ?? self::END_OF_CHAIN, $guard++) {
            $out .= $this->sector($s);
        }

        return $out;
    }

    private function sector(int $n): string
    {
        return substr($this->data, ($n + 1) * $this->sectorSize, $this->sectorSize);
    }

    public static function u16(string $s, int $off): int
    {
        return unpack('v', str_pad(substr($s, $off, 2), 2, "\0"))[1];
    }

    public static function u32(string $s, int $off): int
    {
        return unpack('V', str_pad(substr($s, $off, 4), 4, "\0"))[1];
    }

    public static function i32(string $s, int $off): int
    {
        $b = substr($s, $off, 4);

        return strlen($b) === 4 ? unpack('l', $b)[1] : -1;
    }
}
