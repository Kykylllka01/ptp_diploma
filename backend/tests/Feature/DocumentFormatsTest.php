<?php

namespace Tests\Feature;

use App\Agents\Text\DocumentTextExtractor;
use App\Models\Material;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MakesCourseData;
use Tests\TestCase;

/**
 * Форматы лекций и презентаций без LibreOffice: DOC, RTF, ODT, PPT, ODP, MD.
 * Файлы в tests/fixtures сохранены настоящими Word и PowerPoint.
 */
class DocumentFormatsTest extends TestCase
{
    use MakesCourseData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedAccountsAndCourse();
        Storage::fake();
    }

    private function extract(string $fixture): array
    {
        $kind = strtoupper(pathinfo($fixture, PATHINFO_EXTENSION));
        Storage::put('materials/'.$fixture, file_get_contents(base_path('tests/fixtures/'.$fixture)));

        return (new DocumentTextExtractor())->extract(new Material(['kind' => $kind, 'file_path' => 'materials/'.$fixture]));
    }

    public static function documents(): array
    {
        return [['word97.doc'], ['word.rtf'], ['writer.odt']];
    }

    #[DataProvider('documents')]
    public function test_document_keeps_cyrillic_symbol_arrows_and_tables(string $fixture): void
    {
        $r = $this->extract($fixture);
        $flat = preg_replace('/\s+/u', ' ', $r['text']);

        $this->assertTrue($r['has_text']);
        // «→» вставлена через «Вставка → Символ»: в .doc это «(» со свойством знака, в RTF — поле SYMBOL
        $this->assertStringContainsString('Функциональная зависимость: A → B, где ключ', $flat);
        $this->assertStringContainsString('Нормальная форма Бойса‑Кодда. Страница 1', $flat);
        $this->assertStringContainsString('Таблица Атрибут Студент ФИО', $flat);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B-\x1F]|PAGE|SYMBOL/u', $r['text']);
    }

    public static function presentations(): array
    {
        return [['slides.ppt'], ['slides.odp']];
    }

    #[DataProvider('presentations')]
    public function test_presentation_keeps_slide_order_notes_and_text_boxes(string $fixture): void
    {
        $r = $this->extract($fixture);
        $slides = preg_split('/\[слайд \d+\]/u', preg_replace('/\s+/u', ' ', $r['text']), -1, PREG_SPLIT_NO_EMPTY);

        $this->assertSame('3 слайда', $r['meta']);
        // Третий слайд перед сохранением перенесли в начало — порядок показа, а не создания
        $this->assertSame('Введение в базы данных Лекция 1', trim($slides[0]));
        $this->assertSame('Нормализация Первая нормальная форма Атомарные значения Заметки: Рассказать про повторяющиеся группы', trim($slides[1]));
        $this->assertSame('Зависимости A → B Надпись в отдельном блоке', trim($slides[2]));
    }

    public function test_markdown_is_read_as_text(): void
    {
        Storage::put('materials/lecture.md', "# Нормализация\n\nПервая нормальная форма требует атомарных значений в каждой ячейке таблицы.");

        $r = (new DocumentTextExtractor())->extract(new Material(['kind' => 'MD', 'file_path' => 'materials/lecture.md']));

        $this->assertTrue($r['has_text']);
        $this->assertStringContainsString('атомарных значений', $r['text']);
    }

    public function test_new_formats_upload_with_their_kind(): void
    {
        $this->actingAs($this->teacher());
        foreach (['word97.doc' => 'DOC', 'word.rtf' => 'RTF', 'writer.odt' => 'ODT', 'slides.ppt' => 'PPT', 'slides.odp' => 'ODP'] as $fixture => $kind) {
            $file = new UploadedFile(base_path('tests/fixtures/'.$fixture), 'Лекция.'.pathinfo($fixture, PATHINFO_EXTENSION), null, null, true);

            $m = $this->post('/api/materials', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json();

            $this->assertSame($kind, Material::find($m['id'])->kind);
        }
    }

    public static function impostors(): array
    {
        return [['fake.doc', 'DOC'], ['fake.ppt', 'PPT'], ['fake.rtf', 'RTF'], ['fake.odt', 'ODT'], ['fake.odp', 'ODP']];
    }

    #[DataProvider('impostors')]
    public function test_renamed_text_file_is_reported_not_crashed(string $name, string $kind): void
    {
        Storage::put('materials/'.$name, 'это просто текст, переименованный в другой формат');

        $r = (new DocumentTextExtractor())->extract(new Material(['kind' => $kind, 'file_path' => 'materials/'.$name]));

        $this->assertFalse($r['has_text']);
    }
}
