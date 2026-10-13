<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\GenerationRun;
use App\Models\Group;
use App\Models\Material;
use App\Models\Test;
use App\Services\AttemptService;
use App\Services\FrontendData;
use App\Services\GradebookService;
use App\Support\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Экраны преподавателя (1–8). Данные уходят в шаблон как window.APP_DATA. */
class TeacherController extends Controller
{
    /** Какие данные нужны каждой странице (по скриптам resources/js/pages); остальное не считается. */
    private const PAGE_DATA = [
        'dashboard' => ['materials', 'agents', 'questions', 'participants', 'tests', 'reviews'],
        'upload' => ['materials'],
        'pipeline' => [],
        'bank' => ['questions', 'materials', 'tests'],
        'question' => ['questions'],
        'test-builder' => ['groups'],
        'tests' => ['tests', 'groups'],
        'analytics' => ['tests'],
        'analytics-tests' => [],
        'review' => ['reviews'],
        'attempt' => [],
        'groups' => [],
        'gradebook' => [],
    ];

    public function __construct(private FrontendData $data, private AttemptService $attempts, private GradebookService $gradebook)
    {
    }

    public function dashboard()
    {
        return $this->page('dashboard', 'Обзор');
    }

    public function upload()
    {
        return $this->page('upload', 'Материалы', 'materials');
    }

    public function pipeline(Request $request)
    {
        $run = $request->filled('run') ? GenerationRun::findOrFail($request->run) : GenerationRun::latest('id')->first();

        return $this->page('pipeline', 'Работа агентов', 'materials', $this->data->pipeline($run));
    }

    public function bank()
    {
        return $this->page('bank', 'Банк вопросов');
    }

    public function question()
    {
        return $this->page('question', 'Проверка вопроса', 'bank');
    }

    public function testBuilder()
    {
        return $this->page('test-builder', 'Сборка теста', 'tests');
    }

    public function tests()
    {
        $this->attempts->finalizeOverdue();
        return $this->page('tests', 'Тесты', 'tests');
    }

    /** Пробное прохождение теста преподавателем: без попытки, с проверкой и ключами. */
    public function preview(Request $request)
    {
        $test = Test::where('code', $request->query('test'))->firstOrFail();

        return view('student.test', [
            'title' => 'Проверка теста: '.$test->title,
            'script' => 'test-player',
            'testTitle' => $test->title,
            // У индивидуального теста показываем один случайный вариант — как его увидит студент
            'appData' => $this->data->teacher([]) + $this->data->attempt($test, null, $test->isIndividual()
                ? $test->questions->whereIn('id', $this->attempts->variant($test) ?? [])->values() : null) + ['mode' => 'preview'],
        ]);
    }

    /** Аналитика: без ?test — список тестов для выбора, с ?test=код — разбор результатов этого теста. */
    public function analytics(Request $request)
    {
        // Брошенные студентами попытки досдаются, иначе их нет в результатах
        $this->attempts->finalizeOverdue();
        if (! $request->filled('test')) {
            return $this->page('analytics-tests', 'Аналитика', 'analytics', $this->data->analyticsTests());
        }
        $test = Test::where('code', $request->test)->firstOrFail();

        return $this->page('analytics', 'Результаты и аналитика', 'analytics',
            $this->data->analytics($test) + ['results' => $this->gradebook->forTest($test)]);
    }

    /** Ответы конкретного студента в попытке — с ключами и баллами по критериям. */
    public function attempt(Request $request)
    {
        $attempt = Attempt::with(['user.group', 'test'])->findOrFail((int) $request->query('id'));
        abort_unless($attempt->test->course_id === CurrentUser::course()->id && $attempt->isSubmitted(), 404);
        $others = Attempt::where('test_id', $attempt->test_id)->where('user_id', $attempt->user_id)
            ->whereIn('status', ['submitted', 'confirmed'])->orderBy('id')->get(['id', 'percent', 'status', 'submitted_at']);

        return $this->page('attempt', 'Ответы студента', 'journal', $this->data->result($attempt, true) + [
            'mode' => 'teacher',
            'who' => ['name' => $attempt->user->name, 'email' => $attempt->user->email, 'group' => $attempt->group?->name ?? $attempt->user->group?->name],
            'others' => $others->map(fn ($a) => ['id' => $a->id, 'percent' => (int) $a->percent, 'final' => $a->status === 'confirmed',
                'date' => $a->submitted_at?->format('d.m H:i')])->all(),
        ], 'result');
    }

    /** Журнал для переноса в Модеус: группа × тесты, баллы лучшей попытки. */
    public function gradebook(Request $request)
    {
        $this->attempts->finalizeOverdue();
        $course = CurrentUser::course();
        $groups = Group::where('course_id', $course->id)->orderBy('name')->get(['id', 'name']);
        $ungrouped = \App\Models\User::where('role', 'student')->whereNull('group_id')->exists();
        $id = $request->query('group', $groups->first()?->id ?? 0);
        $group = $id ? $groups->firstWhere('id', (int) $id) : null;
        abort_if($id && ! $group, 404);

        return $this->page('gradebook', 'Журнал', 'journal', [
            'groups' => $groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->all(),
            'hasUngrouped' => $ungrouped,
            'journal' => $this->gradebook->forGroup($group, $course->id),
        ]);
    }

    public function review()
    {
        return $this->page('review', 'Проверка развёрнутых ответов', 'analytics');
    }

    /**
     * Исходный файл материала: PDF, текст и видео открываются во вкладке, DOCX и PPTX скачиваются,
     * материал-ссылка ведёт на свою страницу. Тип отдаём по расширению, а не по содержимому:
     * загруженный «.txt» с HTML внутри не должен открыться как страница сайта.
     */
    public function materialFile(Material $material)
    {
        abort_unless($material->course_id === CurrentUser::course()->id, 404);
        if ($material->url) {
            abort_unless(preg_match('#^https?://#i', $material->url), 404);

            return redirect()->away($material->url);
        }
        abort_unless($material->file_path && Storage::exists($material->file_path), 404);

        // Расширение — из исходного имени файла: при сохранении Laravel угадывает его по содержимому (DOCX → zip)
        $ext = in_array($material->kind, Material::KINDS, true) ? strtolower($material->kind) : strtolower(pathinfo($material->file_path, PATHINFO_EXTENSION));
        $base = trim((string) preg_replace('~[\p{Cc}\\\\/:*?"<>|]+~u', ' ', (string) $material->title));
        $name = Str::limit($base !== '' ? $base : 'material', 150, '').'.'.$ext;
        $inline = ['pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8', 'mp4' => 'video/mp4'];
        $headers = ['X-Content-Type-Options' => 'nosniff'];

        if (isset($inline[$ext])) {
            // Текст — в песочнице: даже если браузер решит его разобрать, скрипты не выполнятся
            return Storage::response($material->file_path, $name, $headers + ['Content-Type' => $inline[$ext]]
                + ($ext === 'txt' ? ['Content-Security-Policy' => 'sandbox'] : []), 'inline');
        }

        return Storage::download($material->file_path, $name, $headers);
    }

    /** Группы курса и ссылки-приглашения для регистрации студентов. */
    public function groups()
    {
        return $this->page('groups', 'Группы', 'groups', $this->data->groupsPage());
    }

    private function page(string $view, string $title, ?string $active = null, array $extra = [], ?string $script = null)
    {
        return view('teacher.'.$view, [
            'title' => $title,
            'active' => $active ?? $view,
            'appData' => $this->data->teacher(self::PAGE_DATA[$view] ?? null) + $extra,
            'script' => $script ?? $view,
        ]);
    }
}
