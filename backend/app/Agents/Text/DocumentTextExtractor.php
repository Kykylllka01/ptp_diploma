<?php

namespace App\Agents\Text;

use App\Agents\Contracts\TextExtractor;
use App\Models\Material;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

/**
 * Извлечение текста без нейросети: PDF — smalot/pdfparser, DOCX, PPTX, ODT и ODP — чтение XML
 * внутри архива, DOC, PPT и RTF — свои разборщики (MsDocReader, PptReader, RtfReader),
 * веб-страница — загрузка и очистка от разметки. В текст вставляются
 * метки [с. N] и [слайд N], по ним Аналитик указывает место цитаты.
 * Сканы без текстового слоя помечаются has_text = false: OCR пока не подключён.
 */
class DocumentTextExtractor implements TextExtractor
{
    /** Меньше стольких букв на страницу — считаем PDF сканом. */
    private const SCAN_LETTERS_PER_PAGE = 40;

    public function extract(Material $material, bool $ocr = false): array
    {
        try {
            return match ($material->kind) {
                'PDF' => $this->pdf($material, $ocr),
                'TXT', 'MD' => $this->txt($material),
                'DOCX' => $this->docx($material),
                'DOC' => $this->doc($material),
                'ODT' => $this->odt($material),
                'RTF' => $this->rtf($material),
                'PPTX' => $this->pptx($material),
                'PPT' => $this->ppt($material),
                'ODP' => $this->odp($material),
                'URL' => $this->url($material),
                'MP4' => ['text' => '', 'has_text' => false, 'meta' => 'видео: текст из видео пока не извлекается'],
                default => ['text' => '', 'has_text' => false, 'meta' => 'формат не поддерживается'],
            };
        } catch (Throwable $e) {
            report($e);

            return ['text' => '', 'has_text' => false, 'meta' => 'не удалось прочитать файл'];
        }
    }

    private function path(Material $m): string
    {
        return Storage::path($m->file_path);
    }

    private function txt(Material $m): array
    {
        // Сначала кодировка, потом чистка: регулярные выражения с /u на тексте в Windows-1251
        // возвращают null, и файл превращался в «текста не найдено»
        $raw = (string) Storage::get($m->file_path);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        }
        $text = $this->clean($raw);

        return $this->result($text, mb_strlen($text).' символов');
    }

    private function pdf(Material $m, bool $ocr): array
    {
        $pdf = (new PdfParser())->parseFile($this->path($m));
        $pages = $pdf->getPages();
        $out = [];
        $letters = 0;
        foreach ($pages as $i => $page) {
            $t = $this->clean($page->getText());
            $letters += preg_match_all('/\p{L}/u', $t);
            $out[] = '[с. '.($i + 1).']'."\n".$t;
        }
        $count = count($pages);
        $meta = $count.' '.$this->plural($count, 'стр.', 'стр.', 'стр.');

        if ($letters < self::SCAN_LETTERS_PER_PAGE * max(1, $count)) {
            return ['text' => '', 'has_text' => false, 'meta' => $meta.' · скан без текстового слоя'.($ocr ? ', распознавание (OCR) пока не подключено' : '')];
        }

        return $this->result(implode("\n\n", $out), $meta);
    }

    private function docx(Material $m): array
    {
        $xml = $this->zipEntry($this->path($m), 'word/document.xml');
        $xml = $this->symbolFont($xml);
        // Абзацы — w:p, разрывы строк — w:br, табуляции — w:tab
        $xml = preg_replace(['/<w:tab\/>/', '/<w:br[^>]*\/>/', '/<\/w:p>/'], ["\t", "\n", "\n\n"], $xml);
        $text = $this->clean(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        $pages = preg_match('/<Pages>(\d+)</', $this->zipEntry($this->path($m), 'docProps/app.xml', false), $p) ? (int) $p[1] : 0;

        return $this->result($text, $pages ? $pages.' стр.' : 'документ');
    }

    /**
     * Документ Word 97–2003. Знаки шрифта Symbol в .doc лежат в области U+F020–U+F0FF
     * (стрелка «→» — U+F0AE): переводим их по той же таблице, что и в DOCX.
     */
    private function doc(Material $m): array
    {
        $text = $this->symbolPua((new MsDocReader())->text($this->path($m)));

        return $this->result($this->clean($text), 'документ Word 97–2003');
    }

    private function rtf(Material $m): array
    {
        $text = $this->symbolPua((new RtfReader())->text($this->path($m)));

        return $this->result($this->clean($text), 'документ RTF');
    }

    /** Документ OpenDocument (LibreOffice, «Р7-Офис», «МойОфис»): content.xml внутри архива. */
    private function odt(Material $m): array
    {
        $xml = $this->zipEntry($this->path($m), 'content.xml');
        $body = preg_match('#<office:body>(.*)</office:body>#s', $xml, $b) ? $b[1] : $xml;
        $text = $this->clean($this->odfText($body));
        $pages = preg_match('/meta:page-count="(\d+)"/', $this->zipEntry($this->path($m), 'meta.xml', false), $p) ? (int) $p[1] : 0;

        return $this->result($text, $pages ? $pages.' стр.' : 'документ OpenDocument');
    }

    /** Презентация OpenDocument: слайды — draw:page, заметки докладчика — presentation:notes. */
    private function odp(Material $m): array
    {
        $xml = $this->zipEntry($this->path($m), 'content.xml');
        preg_match_all('#<draw:page\b.*?</draw:page>#s', $xml, $pages);
        $out = [];
        foreach ($pages[0] as $i => $page) {
            $notes = preg_match('#<presentation:notes\b.*?</presentation:notes>#s', $page, $n) ? $this->clean($this->odfText($n[0])) : '';
            $text = $this->clean($this->odfText(preg_replace('#<presentation:notes\b.*?</presentation:notes>#s', '', $page)));
            $out[] = '[слайд '.($i + 1).']'."\n".$text.($notes !== '' ? "\n".'Заметки: '.$notes : '');
        }
        $count = count($out);

        return $this->result(implode("\n\n", $out), $count.' '.$this->plural($count, 'слайд', 'слайда', 'слайдов'));
    }

    private function odfText(string $xml): string
    {
        // Комментарии рецензентов — не часть текста
        $xml = preg_replace('#<office:annotation\b.*?</office:annotation>#s', '', $xml);
        $xml = preg_replace_callback('#<text:s(?:\s+text:c="(\d+)")?\s*/>#', fn ($s) => str_repeat(' ', (int) ($s[1] ?? 1) ?: 1), $xml);
        $xml = preg_replace(
            ['#<text:tab\s*/>#', '#<text:line-break\s*/>#', '#</text:(p|h)>#', '#</table:table-cell>#', '#</table:table-row>#'],
            ["\t", "\n", "\n\n", "\t", "\n"],
            $xml
        );

        return $this->symbolPua(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private function ppt(Material $m): array
    {
        $out = [];
        foreach ((new PptReader())->slides($this->path($m)) as $i => $s) {
            $text = $this->clean($this->symbolPua($s['text']));
            $notes = $this->clean($this->symbolPua($s['notes']));
            $out[] = '[слайд '.($i + 1).']'."\n".$text.($notes !== '' ? "\n".'Заметки: '.$notes : '');
        }
        $count = count($out);

        return $this->result(implode("\n\n", $out), $count.' '.$this->plural($count, 'слайд', 'слайда', 'слайдов'));
    }

    /** Знаки шрифта Symbol в старых форматах лежат в области U+F020–U+F0FF («→» — U+F0AE). */
    private function symbolPua(string $text): string
    {
        return preg_replace_callback('/[\x{F020}-\x{F0FF}]/u', function ($c) {
            $ch = mb_chr(mb_ord($c[0]) - 0xF000, 'UTF-8');

            return self::SYMBOL[$ch] ?? $ch;
        }, $text) ?? $text;
    }

    /**
     * Знаки шрифта Symbol (стрелки функциональных зависимостей, ≤, ≠ и т. п.) Word хранит
     * как обычные буквы Latin-1: «®» вместо «→». Переводим их в Unicode.
     */
    private const SYMBOL = [
        "\u{AE}" => '→', "\u{AC}" => '←', "\u{AD}" => '↑', "\u{AF}" => '↓', "\u{AB}" => '↔',
        "\u{DE}" => '⇒', "\u{DC}" => '⇐', "\u{DB}" => '⇔', "\u{B4}" => '×', "\u{B1}" => '±',
        "\u{A3}" => '≤', "\u{B3}" => '≥', "\u{B9}" => '≠', "\u{BB}" => '≈', "\u{CE}" => '∈',
        "\u{CF}" => '∉', "\u{C7}" => '∩', "\u{C8}" => '∪', "\u{CC}" => '⊂', "\u{CD}" => '⊆',
        "\u{C9}" => '⊃', "\u{CA}" => '⊇', "\u{C6}" => '∅', "\u{D9}" => '∧', "\u{DA}" => '∨',
        "\u{D8}" => '¬', "\u{A5}" => '∞', "\u{22}" => '∀', "\u{24}" => '∃', "\u{B7}" => '•',
        'a' => 'α', 'b' => 'β', 'g' => 'γ', 'd' => 'δ', 'p' => 'π', 's' => 'σ', 'l' => 'λ', 'S' => 'Σ', 'P' => 'Π',
    ];

    /** Символ шрифта Symbol может быть записан и как <w:sym w:char="F0AE"/>. */
    private function symbolFont(string $xml): string
    {
        $map = self::SYMBOL;
        $xml = preg_replace_callback('/<w:sym\b[^>]*w:char="F0([0-9A-Fa-f]{2})"[^>]*\/>/', function ($m) use ($map) {
            $ch = mb_chr(hexdec($m[1]), 'UTF-8');

            return '<w:t>'.($map[$ch] ?? $ch).'</w:t>';
        }, $xml);

        return preg_replace_callback('/<w:r\b[^>]*>(?:(?!<\/w:r>).)*?w:ascii="Symbol"(?:(?!<\/w:r>).)*<\/w:r>/s', function ($run) use ($map) {
            return preg_replace_callback('/(<w:t\b[^>]*>)(.*?)(<\/w:t>)/s', fn ($t) => $t[1].strtr($t[2], $map).$t[3], $run[0]);
        }, $xml);
    }

    private function pptx(Material $m): array
    {
        $zip = new ZipArchive();
        if ($zip->open($this->path($m), ZipArchive::RDONLY) !== true) {
            return ['text' => '', 'has_text' => false, 'meta' => 'не удалось открыть презентацию'];
        }
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $mm)) {
                $slides[(int) $mm[1]] = $zip->getFromIndex($i);
            }
        }
        ksort($slides);
        $out = [];
        foreach ($slides as $n => $xml) {
            $notes = $zip->getFromName('ppt/notesSlides/notesSlide'.$n.'.xml') ?: '';
            $text = $this->slideText($xml);
            $noteText = $notes ? $this->slideText($notes) : '';
            $out[] = '[слайд '.$n.']'."\n".$text.($noteText !== '' ? "\n".'Заметки: '.$noteText : '');
        }
        $zip->close();
        $count = count($slides);

        return $this->result(implode("\n\n", $out), $count.' '.$this->plural($count, 'слайд', 'слайда', 'слайдов'));
    }

    private function slideText(string $xml): string
    {
        $xml = preg_replace(['/<\/a:p>/', '/<a:br\/>/'], ["\n", "\n"], $xml);
        preg_match_all('/<a:t>(.*?)<\/a:t>|\n/s', $xml, $m);
        $text = '';
        foreach ($m[0] as $i => $whole) {
            $text .= $whole === "\n" ? "\n" : html_entity_decode($m[1][$i], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return $this->clean($text);
    }

    private function url(Material $m): array
    {
        $html = Http::timeout(30)->withHeaders(['User-Agent' => 'Mozilla/5.0 (UTMN tests)'])->get($m->url)->throw()->body();
        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $t) && $m->title === $m->url) {
            $m->title = mb_substr(trim(html_entity_decode($t[1])), 0, 255);
            $m->save();
        }
        $html = preg_replace('#<(script|style|nav|header|footer|noscript|svg)\b.*?</\1>#si', ' ', $html);
        if (preg_match('#<(main|article)\b.*?</\1>#si', $html, $main)) {
            $html = $main[0];
        }
        $html = preg_replace('#</(p|div|h\d|li|tr|pre|section)>|<br\s*/?>#i', "\n\n", $html);
        $text = $this->clean(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $this->result($text, 'веб-страница');
    }

    private function zipEntry(string $path, string $entry, bool $required = true): string
    {
        $zip = new ZipArchive();
        // Только чтение: иначе close() на архиве без записей удаляет загруженный файл с диска
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Не удалось открыть архив');
        }
        $data = $zip->getFromName($entry);
        $zip->close();
        if ($data === false && $required) {
            throw new \RuntimeException('В файле нет '.$entry);
        }

        return (string) $data;
    }

    private function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        // preg_replace возвращает null на невалидном UTF-8 — тогда оставляем текст как есть, а не теряем его
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;

        return trim(preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text);
    }

    private function result(string $text, string $meta): array
    {
        $hasText = preg_match_all('/\p{L}/u', $text) >= 50;

        return ['text' => $text, 'has_text' => $hasText, 'meta' => $hasText ? $meta : $meta.' · текста не найдено'];
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;

        return $n > 10 && $n < 20 ? $many : ($n1 > 1 && $n1 < 5 ? $few : ($n1 === 1 ? $one : $many));
    }
}
