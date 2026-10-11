<?php

namespace App\Http\Controllers;

use App\Models\GenerationRun;
use App\Models\Test;
use App\Services\AttemptService;
use App\Services\FrontendData;
use Illuminate\Http\Request;

/** Экраны преподавателя (1–8). Данные уходят в шаблон как window.APP_DATA. */
class TeacherController extends Controller
{
    public function __construct(private FrontendData $data, private AttemptService $attempts)
    {
    }

    public function dashboard()
    {
        return $this->page('dashboard', 'Обзор');
    }

    public function upload()
    {
        return $this->page('upload', 'Загрузка материалов', 'materials');
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
            'appData' => $this->data->teacher() + $this->data->attempt($test, null, $test->isIndividual()
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

        return $this->page('analytics', 'Результаты и аналитика', 'analytics', $this->data->analytics($test));
    }

    public function review()
    {
        return $this->page('review', 'Проверка открытых ответов', 'analytics');
    }

    private function page(string $view, string $title, ?string $active = null, array $extra = [])
    {
        return view('teacher.'.$view, [
            'title' => $title,
            'active' => $active ?? $view,
            'appData' => $this->data->teacher() + $extra,
            'script' => $view,
        ]);
    }
}
