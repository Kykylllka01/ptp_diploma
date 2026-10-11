<?php

namespace App\Http\Controllers\Api;

use App\Agents\Contracts\CriticAgent;
use App\Agents\Contracts\WriterAgent;
use App\Http\Controllers\Controller;
use App\Models\AgentLog;
use App\Models\Attempt;
use App\Models\Question;
use App\Models\Test;
use App\Services\FrontendData;
use App\Services\GradingService;
use App\Support\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuestionController extends Controller
{
    public function __construct(private FrontendData $data)
    {
    }

    /** GET /api/questions?status=&type[]=&difficulty[]=&topic[]=&material=&q= */
    public function index(Request $request)
    {
        $query = Question::where('course_id', CurrentUser::course()->id);

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        foreach (['type' => 'type', 'difficulty' => 'difficulty', 'topic' => 'topic'] as $param => $column) {
            if ($request->filled($param)) {
                $query->whereIn($column, (array) $request->input($param));
            }
        }
        if ($request->filled('material')) {
            $query->where('material_id', $request->material);
        }
        if ($request->filled('q')) {
            $like = '%'.$request->q.'%';
            $query->where(fn ($w) => $w->where('text', 'like', $like)->orWhere('code', 'like', $like));
        }

        return $query->orderBy('code')->get()->map(fn ($q) => $this->data->question($q));
    }

    public function show(Question $question)
    {
        return $this->data->question($question);
    }

    /** «Добавить вручную»: пустой вопрос, который преподаватель заполняет в редакторе. */
    public function store(Request $request)
    {
        $data = $request->validate(['type' => ['sometimes', Rule::in(array_keys(Question::TYPES))]]);
        $course = CurrentUser::course();
        $type = $data['type'] ?? 'single';

        $q = new Question([
            'code' => Question::nextCode(),
            'course_id' => $course->id,
            'type' => $type,
            'difficulty' => 'medium',
            'topic' => ($course->topics ?? [])[0] ?? null,
            'source' => 'Добавлен вручную',
            'points' => 1,
            'status' => 'pending',
            'text' => 'Новый вопрос',
            'options' => [['text' => 'Верный вариант', 'correct' => true], ['text' => 'Неверный вариант'], ['text' => 'Неверный вариант'], ['text' => 'Неверный вариант']],
            'tags' => array_values(array_filter([($course->topics ?? [])[0] ?? null])),
        ]);
        $q->addHistory('Преподаватель добавил вопрос вручную');
        $q->save();

        return $this->data->question($q);
    }

    /** Экспорт вопросов в Moodle XML (по умолчанию — все одобренные). */
    public function export(Request $request)
    {
        $course = CurrentUser::course();
        $query = Question::where('course_id', $course->id);
        $request->filled('ids') ? $query->whereIn('code', (array) $request->input('ids')) : $query->where('status', 'approved');
        $questions = $query->orderBy('topic')->orderBy('code')->get();

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('quiz');
        foreach ($questions->groupBy('topic') as $topic => $list) {
            $xml->startElement('question');
            $xml->writeAttribute('type', 'category');
            $xml->startElement('category');
            $xml->writeElement('text', '$course$/'.$course->title.'/'.($topic ?: 'Без темы'));
            $xml->endElement();
            $xml->endElement();
            foreach ($list as $q) {
                $this->moodleQuestion($xml, $q);
            }
        }
        $xml->endElement();

        return response($xml->outputMemory(), 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="questions-moodle.xml"',
        ]);
    }

    private function moodleQuestion(\XMLWriter $xml, Question $q): void
    {
        $type = match ($q->type) {
            'single', 'multiple' => 'multichoice',
            'tf' => 'truefalse',
            'match' => 'matching',
            default => 'essay',
        };
        $html = fn (string $s) => nl2br(e($s), false);
        $text = ($q->context ? '<pre>'.e($q->context).'</pre>' : '').'<p>'.$html($q->text).'</p>';
        if ($q->type === 'sql') {
            $text .= '<p>Таблицы: '.e($q->sql_schema ?: '').'</p>';
        }
        $rich = function (string $tag, string $value, string $format = 'html') use ($xml) {
            $xml->startElement($tag);
            $xml->writeAttribute('format', $format);
            $xml->startElement('text');
            $xml->writeCdata($value);
            $xml->endElement();
            $xml->endElement();
        };

        $xml->startElement('question');
        $xml->writeAttribute('type', $type);
        $xml->startElement('name');
        $xml->writeElement('text', $q->code.' '.mb_substr($q->text, 0, 60));
        $xml->endElement();
        $rich('questiontext', $text);
        $rich('generalfeedback', $html((string) $q->explanation));
        $xml->writeElement('defaultgrade', (string) $q->points);

        if ($type === 'multichoice') {
            $correct = max(1, count($q->correctIndexes()));
            $xml->writeElement('single', $q->type === 'single' ? 'true' : 'false');
            $xml->writeElement('shuffleanswers', 'true');
            $xml->writeElement('answernumbering', 'abc');
            foreach ($q->options ?? [] as $o) {
                $xml->startElement('answer');
                $xml->writeAttribute('fraction', ! empty($o['correct']) ? (string) round(100 / $correct, 5) : ($q->type === 'multiple' ? (string) -round(100 / $correct, 5) : '0'));
                $xml->writeAttribute('format', 'html');
                $xml->startElement('text');
                $xml->writeCdata(e($o['text']));
                $xml->endElement();
                $xml->endElement();
            }
        } elseif ($type === 'truefalse') {
            $trueCorrect = ! empty(($q->options ?? [])[0]['correct']);
            foreach (['true' => $trueCorrect, 'false' => ! $trueCorrect] as $label => $ok) {
                $xml->startElement('answer');
                $xml->writeAttribute('fraction', $ok ? '100' : '0');
                $xml->writeElement('text', $label);
                $xml->endElement();
            }
        } elseif ($type === 'matching') {
            $parts = $q->matchParts();
            $xml->writeElement('shuffleanswers', 'true');
            foreach ($parts['terms'] as $i => $term) {
                $xml->startElement('subquestion');
                $xml->writeAttribute('format', 'html');
                $xml->writeElement('text', e($term));
                $xml->startElement('answer');
                $xml->writeElement('text', $parts['definitions'][$parts['key'][$i]]);
                $xml->endElement();
                $xml->endElement();
            }
            // Лишние определения — подвопросы без текста
            foreach ($parts['definitions'] as $j => $def) {
                if (! in_array($j, $parts['key'], true)) {
                    $xml->startElement('subquestion');
                    $xml->writeAttribute('format', 'html');
                    $xml->writeElement('text', '');
                    $xml->startElement('answer');
                    $xml->writeElement('text', $def);
                    $xml->endElement();
                    $xml->endElement();
                }
            }
        } else {
            $xml->writeElement('responseformat', $q->type === 'sql' ? 'monospaced' : 'editor');
            $xml->writeElement('responserequired', '1');
            $xml->writeElement('responsefieldlines', $q->type === 'sql' ? '8' : '12');
            $info = '<p><b>Эталон:</b></p><p>'.$html((string) $q->reference).'</p>';
            if ($q->criteria) {
                $info .= '<p><b>Критерии:</b></p><ol>'.collect($q->criteria)->map(fn ($c) => '<li>'.e($c[0]).' — '.$c[1].'</li>')->implode('').'</ol>';
            }
            $rich('graderinfo', $info);
        }
        $xml->endElement();
    }

    /** Сохранение правок преподавателя, одобрение или отклонение. */
    public function update(Request $request, Question $question)
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected'])],
            'text' => 'sometimes|string|max:5000',
            'context' => 'sometimes|nullable|string|max:5000',
            'type' => ['sometimes', Rule::in(array_keys(Question::TYPES))],
            'difficulty' => ['sometimes', Rule::in(array_keys(Question::DIFFICULTIES))],
            'points' => 'sometimes|integer|min:1|max:100',
            'options' => 'sometimes|nullable|array',
            'options.*.text' => 'required_with:options|string|max:1000',
            'options.*.correct' => 'sometimes|boolean',
            'explanation' => 'sometimes|nullable|string|max:5000',
            'reference' => 'sometimes|nullable|string|max:10000',
            'criteria' => 'sometimes|nullable|array|max:8',
            'criteria.*' => 'array|size:2',
            'criteria.*.0' => 'required|string|max:500',
            'criteria.*.1' => 'required|integer|min:1|max:20',
            'tags' => 'sometimes|nullable|array',
            'critic' => 'sometimes|nullable|array',
            'fixApplied' => 'sometimes|boolean',
            'event' => 'sometimes|string|max:255',
        ]);

        if (isset($data['fixApplied'])) {
            $data['fix_applied'] = $data['fixApplied'];
        }
        // Флаг критика снимаем, когда преподаватель принял решение
        if (in_array($data['status'] ?? null, ['approved', 'rejected'], true)) {
            $data['flag_text'] = null;
            $data['flag_level'] = null;
        }

        $question->fill(collect($data)->except(['fixApplied', 'event'])->all());
        $event = $data['event'] ?? match ($data['status'] ?? null) {
            'approved' => 'Преподаватель одобрил',
            'rejected' => 'Преподаватель отклонил',
            default => 'Преподаватель изменил вопрос',
        };
        $question->addHistory($event);
        $question->save();

        return $this->data->question($question);
    }

    /** POST /api/questions/bulk {ids: [...], action: approve|reject} */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'action' => ['required', Rule::in(['approve', 'reject'])],
        ]);
        $status = $data['action'] === 'approve' ? 'approved' : 'rejected';

        $questions = Question::whereIn('code', $data['ids'])->get();
        foreach ($questions as $q) {
            $q->status = $status;
            $q->flag_text = $q->flag_level = null;
            $q->addHistory($status === 'approved' ? 'Преподаватель одобрил' : 'Преподаватель отклонил');
            $q->save();
        }

        return ['updated' => $questions->count()];
    }

    /** Перегенерация: Составитель переписывает вопрос, Критик проверяет заново. */
    public function regenerate(Question $question, WriterAgent $writer, CriticAgent $critic)
    {
        $draft = $writer->rewrite($question->only($question->getFillable()), $question->critic['checks'] ?? []);
        $verdict = $critic->review($draft);

        $question->fill(array_intersect_key($draft, array_flip(['text', 'context', 'options', 'explanation', 'reference', 'criteria', 'quote', 'points'])));
        $question->critic = ['score' => $verdict['score'], 'checks' => $verdict['checks']];
        $question->flag_text = $verdict['flag']['text'] ?? null;
        $question->flag_level = $verdict['flag']['level'] ?? null;
        $question->status = 'pending';
        $question->addHistory('Составитель переписал вопрос');
        $question->save();

        AgentLog::create(['agent' => 'writer', 'message' => $question->code.' переписан по запросу преподавателя']);

        return $this->data->question($question);
    }

    /**
     * Тренировка студента: проверить один ответ и вернуть пояснение.
     * {test: код тренировки} — вопрос из этой тренировки; {attempt: id} — работа над ошибками по своей сданной попытке.
     * Проверка отвечает «верно/неверно», поэтому ею можно подобрать ответ: она закрыта для вопросов,
     * которые студент ещё может получить в идущем тесте, и для попыток, после которых остались пересдачи.
     */
    public function check(Request $request, Question $question, GradingService $grading)
    {
        $student = CurrentUser::student();
        $data = $request->validate([
            'test' => 'nullable|string|max:20',
            'attempt' => 'nullable|integer',
            'answer' => 'nullable',
            'answer.*' => 'nullable|integer',
        ]);
        abort_if(is_string($request->input('answer')) && mb_strlen($request->input('answer')) > 10000, 422, 'Ответ слишком длинный');

        if (! empty($data['attempt'])) {
            $attempt = Attempt::where('user_id', $student->id)->where('status', '!=', 'in_progress')->find($data['attempt']);
            abort_unless($attempt && $attempt->answers()->where('question_id', $question->id)->exists(), 403, 'Вопроса нет в этой попытке');
            abort_unless($attempt->test->reviewOpenFor($student), 403, 'Проверка откроется, когда закончатся попытки или тест закроется');
        } else {
            $test = Test::visibleTo($student)->where('status', 'training')->where('code', $data['test'] ?? '')->first();
            abort_unless($test && $test->questions()->whereKey($question->id)->exists(), 403, 'Проверка доступна только в тренировке');
        }
        // Тот же вопрос может стоять в идущем или ещё не опубликованном тесте — там ответ пока секрет
        abort_if(in_array($question->id, Test::secretQuestionIdsFor($student), true), 403, 'Этот вопрос есть в идущем тесте — проверка откроется после него');

        return $this->feedback($grading, $question, $request->input('answer'), false, false);
    }

    /**
     * Пробное прохождение преподавателем: проверка с ключом. Для развёрнутого ответа
     * по запросу (agent=1) — оценка агентом-оценщиком, как у студентов.
     */
    public function previewCheck(Request $request, Question $question, GradingService $grading)
    {
        return $this->feedback($grading, $question, $request->input('answer'), $request->boolean('agent'), true);
    }

    private function feedback(GradingService $grading, Question $q, mixed $answer, bool $withAgent, bool $withKey): array
    {
        $out = ['type' => $q->type, 'explanation' => $q->explanation, 'ref' => $q->source, 'max' => $q->points];

        if ($q->type === 'open') {
            // Развёрнутый ответ без агента не оценивается: показываем эталон и критерии для самопроверки
            $out += ['reference' => $q->reference, 'criteria' => $q->criteria ?? [], 'score' => null, 'correct' => null];
            if ($withAgent && GradingService::isAnswered($answer)) {
                $r = $grading->grade($q, (string) $answer);
                $out['score'] = $r['score'];
                $out['correct'] = $r['score'] >= $r['max'];
                $out['agent'] = $r['agent'] ?? null;
            }

            return $out;
        }

        $r = $grading->grade($q, $answer);
        $out += ['score' => $r['score'], 'correct' => $r['score'] >= $r['max'], 'criteria' => $r['criteria'] ?? []];
        if ($withKey || $q->type === 'sql') {
            $out['key'] = $this->keyText($q);
        }

        return $out;
    }

    /** Верный ответ словами — для разбора. */
    private function keyText(Question $q): string
    {
        return match ($q->type) {
            'match' => collect($q->matchParts()['terms'])->map(fn ($t, $i) => $t.' — '.$q->matchParts()['definitions'][$q->matchParts()['key'][$i]])->implode('; '),
            'sql', 'open' => (string) $q->reference,
            default => collect($q->options ?? [])->filter(fn ($o) => ! empty($o['correct']))->pluck('text')->implode('; '),
        };
    }
}
