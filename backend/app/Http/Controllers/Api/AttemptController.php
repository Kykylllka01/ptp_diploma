<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Answer;
use App\Models\Attempt;
use App\Models\ProctorEvent;
use App\Services\AttemptService;
use App\Services\SqlSandbox;
use App\Support\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttemptController extends Controller
{
    /** Ответ строкой (развёрнутый, SQL) не длиннее 10 000 символов: его трижды читает Оценщик, это токены. */
    private const MAX_ANSWER_LENGTH = 10000;

    /** Сколько событий прокторинга хранится на одну попытку. */
    private const MAX_PROCTOR_EVENTS = 300;

    public function __construct(private AttemptService $attempts)
    {
    }

    /** Автосохранение ответов, отметок и текущего вопроса. */
    public function update(Request $request, Attempt $attempt)
    {
        $this->authorizeOwner($attempt);
        if ($attempt->isSubmitted()) {
            return response()->json(['message' => 'Попытка уже сдана'], 409);
        }

        if ($attempt->isOverdue()) {
            return response()->json(['message' => 'Время на попытку вышло'], 409);
        }

        $data = $request->validate($this->rules());
        $this->attempts->save($attempt, $data['answers'] ?? [], $data['flags'] ?? [], $data['current'] ?? 0);

        return ['saved' => true];
    }

    /** Сдать попытку: сервер оценивает все ответы. */
    public function submit(Request $request, Attempt $attempt)
    {
        $this->authorizeOwner($attempt);
        if (! $attempt->isSubmitted()) {
            // После окончания времени принимаем только то, что уже сохранено
            if (! $attempt->isOverdue()) {
                $data = $request->validate($this->rules());
                $this->attempts->save($attempt, $data['answers'] ?? [], $data['flags'] ?? [], $data['current'] ?? 0);
            }
            $attempt = $this->attempts->submit($attempt);
        }

        return ['attemptId' => $attempt->id, 'percent' => $attempt->percent, 'redirect' => route('student.result', ['attempt' => $attempt->id])];
    }

    /**
     * Прокторинг: студент ушёл со страницы теста (виды — ProctorEvent::KINDS).
     * Время берётся по часам сервера, от клиента — только вид ухода.
     */
    public function proctor(Request $request, Attempt $attempt)
    {
        $this->authorizeOwner($attempt);
        $data = $request->validate(['kind' => ['required', Rule::in(ProctorEvent::KINDS)]]);
        abort_unless($attempt->test->isProctored(), 422, 'В этом тесте прокторинг не включён');
        if ($attempt->isSubmitted() || $attempt->isOverdue()) {
            return response()->json(['message' => 'Попытка уже закончилась'], 409);
        }
        // Предел на попытку: журнал не раздувается, а 300 уходов и так говорят сами за себя
        $count = $attempt->proctorEvents()->count();
        if ($count >= self::MAX_PROCTOR_EVENTS) {
            return ['id' => null, 'count' => $count];
        }
        $event = $attempt->proctorEvents()->create(['kind' => $data['kind'], 'left_at' => now()]);

        return ['id' => $event->id, 'count' => $count + 1];
    }

    /**
     * Студент вернулся на страницу теста и видит вопросы: все незакрытые уходы получают время возврата
     * (по часам сервера). Шлёт только страница, где правила уже приняты, — новая вкладка с правилами уход не закрывает.
     */
    public function proctorBack(Attempt $attempt)
    {
        $this->authorizeOwner($attempt);
        $attempt->proctorEvents()->whereNull('returned_at')->update(['returned_at' => now()]);

        return ['ok' => true];
    }

    /** «Выполнить на учебной базе». */
    public function runSql(Request $request, SqlSandbox $sandbox)
    {
        $data = $request->validate(['sql' => 'required|string|max:5000']);

        return $sandbox->run($data['sql']);
    }

    /** Апелляция по развёрнутому ответу: ответ возвращается преподавателю на перепроверку. */
    public function appeal(Request $request, Answer $answer)
    {
        $this->authorizeOwner($answer->attempt);
        $data = $request->validate(['text' => 'required|string|min:5|max:2000']);
        $review = $answer->review;
        abort_unless($review !== null, 422, 'Апелляция возможна только по развёрнутому ответу');
        // Одна апелляция на ответ: повторная перетирала бы прежнюю и снова снимала подтверждённую оценку
        abort_if($review->appealed_at !== null, 409, 'Апелляция по этому ответу уже подана');

        DB::transaction(function () use ($review, $answer, $data) {
            $review->update(['appeal' => $data['text'], 'appealed_at' => now(), 'status' => 'pending']);
            $answer->update(['is_final' => false]);
            $this->attempts->recalculate($answer->attempt);
        });

        return ['ok' => true];
    }

    private function rules(): array
    {
        return [
            'answers' => 'array|max:300',
            'answers.*' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && mb_strlen($value) > self::MAX_ANSWER_LENGTH) {
                    $fail('Ответ длиннее '.self::MAX_ANSWER_LENGTH.' символов — сократите его');
                } elseif (is_array($value) && count($value) > 50) {
                    $fail('Слишком много вариантов в ответе');
                } elseif (! is_string($value) && ! is_array($value) && $value !== null) {
                    $fail('Неверный формат ответа');
                }
            }],
            'answers.*.*' => 'nullable|integer',
            'flags' => 'array|max:300',
            'current' => 'integer|min:0',
        ];
    }

    private function authorizeOwner(Attempt $attempt): void
    {
        abort_unless($attempt->user_id === CurrentUser::student()->id, 403);
    }
}
