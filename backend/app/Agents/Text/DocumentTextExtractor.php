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
 * Извлечение текста без нейросети: PDF — smalot/pdfparser, DOCX и PPTX — чтение XML
 * внутри архива, веб-страница — загрузка и очистка от разметки. В текст вставляются
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
                'TXT' => $this->txt($material),
                'PDF' => $this->pdf($material, $ocr),
                'DOCX' => $this->docx($material),
                'PPTX' => $this->pptx($material),
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
     * Знаки шрифта Symbol (стрелки функциональных зависимостей, ≤, ≠ и т. п.) Word хранит
     * как обычные буквы Latin-1: «®» вместо «→». Переводим их в Unicode.
     * Символ может быть записан и как <w:sym w:char="F0AE"/>.
     */
    private function symbolFont(string $xml): string
    {
        $map = [
            "\u{AE}" => '→', "\u{AC}" => '←', "\u{AD}" => '↑', "\u{AF}" => '↓', "\u{AB}" => '↔',
            "\u{DE}" => '⇒', "\u{DC}" => '⇐', "\u{DB}" => '⇔', "\u{B4}" => '×', "\u{B1}" => '±',
            "\u{A3}" => '≤', "\u{B3}" => '≥', "\u{B9}" => '≠', "\u{BB}" => '≈', "\u{CE}" => '∈',
            "\u{CF}" => '∉', "\u{C7}" => '∩', "\u{C8}" => '∪', "\u{CC}" => '⊂', "\u{CD}" => '⊆',
            "\u{C9}" => '⊃', "\u{CA}" => '⊇', "\u{C6}" => '∅', "\u{D9}" => '∧', "\u{DA}" => '∨',
            "\u{D8}" => '¬', "\u{A5}" => '∞', "\u{22}" => '∀', "\u{24}" => '∃', "\u{B7}" => '•',
            'a' => 'α', 'b' => 'β', 'g' => 'γ', 'd' => 'δ', 'p' => 'π', 's' => 'σ', 'l' => 'λ', 'S' => 'Σ', 'P' => 'Π',
        ];
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
        if ($zip->open($this->path($m)) !== true) {
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
        if ($zip->open($path) !== true) {
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
