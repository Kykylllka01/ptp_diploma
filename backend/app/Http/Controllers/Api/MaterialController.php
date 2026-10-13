<?php

namespace App\Http\Controllers\Api;

use App\Agents\Contracts\TextExtractor;
use App\Http\Controllers\Controller;
use App\Models\GenerationRun;
use App\Models\LlmCall;
use App\Models\Material;
use App\Models\Question;
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
        return Material::where('course_id', CurrentUser::course()->id)->with('author:id,name')->withCount('questions')->latest()->get()->map(fn ($m) => $this->data->material($m));
    }

    /**
     * Карточка материала на «Обзоре»: сколько текста извлечено, вопросы по статусам
     * и оценке Критика, запуски агентов с их стоимостью.
     */
    public function show(Material $material)
    {
        abort_unless($material->course_id === CurrentUser::course()->id, 404);
        $text = (string) $material->extracted_text;
        $questions = Question::where('material_id', $material->id)->get(['status', 'type', 'difficulty', 'critic', 'flag_text']);
        // Запуск может охватывать несколько материалов: берём те, где этот материал был в задании
        $runs = GenerationRun::where('course_id', $material->course_id)->latest('id')->limit(500)->get(['id', 'params', 'status', 'written', 'rejected', 'disputed', 'created_at'])
            ->filter(fn ($r) => in_array($material->id, array_map('intval', $r->params['material_ids'] ?? []), true))
            ->take(5)->values();
        $cost = LlmCall::whereIn('generation_run_id', $runs->pluck('id'))->groupBy('generation_run_id')
            ->selectRaw('generation_run_id, sum(cost_usd) as usd')->pluck('usd', 'generation_run_id');

        return $this->data->material($material) + [
            'chars' => mb_strlen($text),
            // Аналитик режет текст на фрагменты примерно по 7000 символов
            'fragments' => $text === '' ? 0 : (int) ceil(mb_strlen($text) / 7000),
            'questions' => [
                'total' => $questions->count(),
                'pending' => $questions->where('status', 'pending')->count(),
                'approved' => $questions->where('status', 'approved')->count(),
                'rejected' => $questions->where('status', 'rejected')->count(),
                'clean' => $questions->filter(fn ($q) => ($q->critic['score'] ?? null) !== null && ! $q->flag_text)->count(),
                'flagged' => $questions->filter(fn ($q) => $q->flag_text)->count(),
                'byType' => $questions->countBy('type')->all(),
            ],
            'runs' => $runs->map(fn ($r) => [
                'id' => $r->id,
                'date' => $r->created_at?->translatedFormat('j F, H:i'),
                'status' => $r->status,
                'written' => $r->written,
                'rejected' => $r->rejected,
                'disputed' => $r->disputed,
                'cost' => isset($cost[$r->id]) ? round((float) $cost[$r->id], 4) : null,
            ])->all(),
        ];
    }

    /** Загрузка файла, ссылки или вставленного текста. */
    public function store(Request $request)
    {
        $request->validate([
            // Проверяем расширение, а не MIME: DOCX и PPTX часто определяются как application/zip
            'file' => ['required_without_all:url,text', 'file', 'max:51200', function ($attr, $file, $fail) {
                if (! in_array(strtolower($file->getClientOriginalExtension()), ['pdf', 'docx', 'pptx', 'txt'], true)) {
                    $fail('Поддерживаются только файлы PDF, DOCX, PPTX и TXT.');
                }
            }],
            'url' => 'required_without_all:file,text|nullable|url:http,https|max:1000',
            'text' => 'required_without_all:file,url|nullable|string|max:500000',
            'title' => 'nullable|string|max:255',
        ]);

        $course = CurrentUser::course();
        $material = new Material(['course_id' => $course->id, 'user_id' => CurrentUser::teacher()->id, 'status' => 'uploaded']);

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
        abort_unless($material->isOwnedBy(CurrentUser::teacher()), 403, 'Удалить материал может только тот, кто его загрузил: '.$material->author?->name);
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
