<?php

namespace App\Agents\Contracts;

use App\Models\Material;

/**
 * Извлечение текста из материала: PDF, Word, OpenDocument, RTF, PowerPoint, веб-страница, OCR для сканов.
 */
interface TextExtractor
{
    /** @return array{text: string, has_text: bool, meta: ?string} */
    public function extract(Material $material, bool $ocr = false): array;
}
