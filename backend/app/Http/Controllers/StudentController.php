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
        // Брошенные попытки (время вышло, вкладка закрыта) сдаются с тем, что сохранилось
        $this->attempts->finalizeOverdue(null, CurrentUser::student());
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
        $this->attempts->finalizeOverdue(null, $student);

        // Работа над ошибками: вопросы, на которые в сданной попытке дан неверный ответ
        if ($training && $request->filled('attempt')) {
            $source = Attempt::where('user_id', $student->id)->where('status', '!=', 'in_progress')->findOrFail($request->query('attempt'));
            // Пока остались попытки, проверка с ответами подсказала бы их для пересдачи
            abort_unless($source->test->reviewOpenFor($student), 403, 'Работа над ошибками откроется, когда закончатся попытки или тест закроется');
            $wrong = $source->answers()->with('question')->get()
                ->filter(fn ($a) => $a->question->type !== 'open' && (float) $a->score < (float) $a->max_score)
                ->pluck('question_id')
                // Вопросы, которые стоят и в идущем тесте, проверка всё равно не пропустит
                ->diff(Test::secretQuestionIdsFor($student, $source->test));
            $test = $source->test;
            $test->setRelation('questions', $test->questions()->whereIn('questions.id', $wrong)->get());
            $test->title = 'Работа над ошибками · '.$test->title;

            return view('student.test', [
                'title' => $test->title, 'script' => 'test-player', 'testTitle' => $test->title,
                'appData' => $this->data->student() + $this->data->attempt($test, null),
            ]);
        }

        // Только тесты курса, которые студент видит в списке: не черновики, своей группы или без группы.
        // Тренировка — только по тестам-тренировкам, иначе по ссылке видны вопросы контрольного теста.
        $tests = Test::visibleTo($student)->where('course_id', CurrentUser::course()->id);
        $test = $request->filled('test')
            ? $tests->where('code', $request->query('test'))->firstOrFail()
            : $tests->where('status', $training ? 'training' : 'running')->orderBy('id')->firstOrFail();
        abort_if($training && $test->status !== 'training', 403, 'Этот тест нельзя открыть как тренировку');

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
        $this->attempts->finalizeOverdue(null, $student);
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
