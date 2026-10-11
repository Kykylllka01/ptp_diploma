<?php

namespace App\Agents\Fake;

use App\Agents\Contracts\TextExtractor;
use App\Models\Material;
use Illuminate\Support\Facades\Storage;

/**
 * Заготовка извлечения текста. TXT читается по-настоящему, остальные форматы
 * пока считаются «распознанными» без реального текста. Сканы (имя содержит «скан»
 * или «scan») помечаются как файлы без текстового слоя, пока не вызван OCR.
 */
class FakeTextExtractor implements TextExtractor
{
    public function extract(Material $material, bool $ocr = false): array
    {
        $name = mb_strtolower($material->title);
        $isScan = str_contains($name, 'скан') || str_contains($name, 'scan');

        if ($isScan && ! $ocr) {
            return ['text' => '', 'has_text' => false, 'meta' => null];
        }

        if ($material->kind === 'TXT' && $material->file_path && Storage::exists($material->file_path)) {
            $text = (string) Storage::get($material->file_path);

            return ['text' => $text, 'has_text' => true, 'meta' => mb_strlen($text).' символов'];
        }

        $meta = match ($material->kind) {
            'PDF' => $ocr ? 'OCR' : 'PDF-документ',
            'PPTX' => 'презентация',
            'DOCX' => 'документ',
            'URL' => 'веб-страница',
            'MP4' => 'видео',
            default => null,
        };

        return [
            'text' => '[заготовка] Текст материала «'.$material->title.'» появится после подключения извлечения текста.',
            'has_text' => true,
            'meta' => $meta,
        ];
    }
}
