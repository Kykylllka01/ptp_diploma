<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\Test;
use App\Services\AttemptService;
use App\Services\FrontendData;
use App\Support\CurrentUser;
use Illuminate\Http\Request;

/** Экраны студента (9–12). */
class StudentController extends Controller
{
    public function __construct(private FrontendData $data, private AttemptService $attempts)
    {
    }

    public function tests()
    {
        return view('student.tests', [
            'title' => 'Мои тесты', 'active' => 'tests', 'script' => 'student-tests',
            'appData' => $this->data->student(),
        ]);
    }

    /** Прохождение теста. ?test=m1 — какой тест, ?mode=training — тренировка без оценки. */
    public function test(Request $request)
    {
        $training = $request->query('mode') === 'training';
        $student = CurrentUser::student();

        // Работа над ошибками: вопросы, на которые в сданной попытке дан неверный ответ
        if ($training && $request->filled('attempt')) {
            $source = Attempt::where('user_id', $student->id)->where('status', '!=', 'in_progress')->findOrFail($request->query('attempt'));
            $wrong = $source->answers()->with('question')->get()
                ->filter(fn ($a) => $a->question->type !== 'open' && (float) $a->score < (float) $a->max_score)
                ->pluck('question_id');
            $test = $source->test;
            $test->setRelation('questions', $test->questions()->whereIn('questions.id', $wrong)->get());
            $test->title = 'Работа над ошибками · '.$test->title;

            return view('student.test', [
                'title' => $test->title, 'script' => 'test-player', 'testTitle' => $test->title,
                'appData' => $this->data->student() + $this->data->attempt($test, null),
            ]);
        }

        $test = $request->filled('test')
            ? Test::where('code', $request->query('test'))->firstOrFail()
            : Test::where('status', $training ? 'training' : 'running')->firstOrFail();

        if ($training) {
            $attempt = null;
        } else {
            abort_unless($test->isOpenNow(), 403, 'Тест сейчас недоступен');
            $current = $this->attempts->current($test, $student);
            if ($current && $current->isSubmitted() && $test->attempts_allowed <= $test->attempts()->where('user_id', $student->id)->count()) {
                return redirect()->route('student.result', ['attempt' => $current->id]);
            }
            $attempt = $this->attempts->start($test, $student);
        }

        return view('student.test', [
            'title' => $test->title, 'script' => 'test-player', 'testTitle' => $test->title,
            'appData' => $this->data->student() + $this->data->attempt($test, $attempt),
        ]);
    }

    /** Результат: ?attempt=ID, без параметра — последняя сданная попытка. */
    public function result(Request $request)
    {
        $student = CurrentUser::student();
        $attempt = $request->filled('attempt')
            ? Attempt::where('user_id', $student->id)->findOrFail($request->query('attempt'))
            : Attempt::where('user_id', $student->id)->where('status', '!=', 'in_progress')->latest('submitted_at')->first();

        $extra = $attempt && $attempt->isSubmitted() ? $this->data->result($attempt) : ['result' => null];

        return view('student.result', [
            'title' => 'Результат', 'active' => 'results', 'script' => 'result',
            'appData' => $this->data->student() + $extra,
        ]);
    }
}
