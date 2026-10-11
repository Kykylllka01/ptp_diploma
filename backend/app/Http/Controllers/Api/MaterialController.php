<?php

namespace App\Http\Controllers\Api;

use App\Agents\Contracts\TextExtractor;
use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Services\FrontendData;
use App\Support\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MaterialController extends Controller
{
    public function __construct(private FrontendData $data, private TextExtractor $extractor)
    {
    }

    public function index()
    {
        return Material::where('course_id', CurrentUser::course()->id)->latest()->get()->map(fn ($m) => $this->data->material($m));
    }

    /** Загрузка файла, ссылки или вставленного текста. */
    public function store(Request $request)
    {
        $request->validate([
            // Проверяем расширение, а не MIME: DOCX и PPTX часто определяются как application/zip
            'file' => ['required_without_all:url,text', 'file', 'max:51200', function ($attr, $file, $fail) {
                if (! in_array(strtolower($file->getClientOriginalExtension()), ['pdf', 'docx', 'pptx', 'txt', 'mp4'], true)) {
                    $fail('Поддерживаются файлы PDF, DOCX, PPTX, TXT и MP4.');
                }
            }],
            'url' => 'required_without_all:file,text|nullable|url|max:1000',
            'text' => 'required_without_all:file,url|nullable|string|max:500000',
            'title' => 'nullable|string|max:255',
        ]);

        $course = CurrentUser::course();
        $material = new Material(['course_id' => $course->id, 'status' => 'uploaded']);

        if ($file = $request->file('file')) {
            $material->title = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $material->kind = Material::kindFromName($file->getClientOriginalName());
            $material->size_bytes = $file->getSize();
            $material->file_path = $file->store('materials');
        } elseif ($request->filled('url')) {
            $material->title = $request->input('title') ?: $request->url;
            $material->kind = 'URL';
            $material->url = $request->url;
        } else {
            $material->title = $request->input('title') ?: 'Текст из буфера обмена';
            $material->kind = 'TXT';
            $material->file_path = 'materials/'.uniqid('text_').'.txt';
            Storage::put($material->file_path, $request->text);
            $material->size_bytes = strlen($request->text);
        }
        $material->save();

        $this->extract($material);

        return $this->data->material($material->fresh());
    }

    /** Распознать скан без текстового слоя (OCR — заготовка). */
    public function ocr(Material $material)
    {
        $this->extract($material, true);

        return $this->data->material($material->fresh());
    }

    public function destroy(Material $material)
    {
        if ($material->questions()->exists()) {
            return response()->json(['message' => 'По материалу уже есть вопросы в банке, удалить нельзя'], 422);
        }
        if ($material->file_path) {
            Storage::delete($material->file_path);
        }
        $material->delete();

        return response()->noContent();
    }

    private function extract(Material $material, bool $ocr = false): void
    {
        $r = $this->extractor->extract($material, $ocr);
        $material->update([
            'has_text' => $r['has_text'],
            'extracted_text' => $r['text'],
            'meta' => $r['meta'] ?? $material->meta,
            'status' => $r['has_text'] ? 'ready' : 'error',
        ]);
    }
}
