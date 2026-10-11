<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Answer;
use App\Models\Attempt;
use App\Services\AttemptService;
use App\Services\SqlSandbox;
use App\Support\CurrentUser;
use Illuminate\Http\Request;

class AttemptController extends Controller
{
    /** Ответ строкой (развёрнутый, SQL) не длиннее 10 000 символов: его трижды читает Оценщик, это токены. */
    private const MAX_ANSWER_LENGTH = 10000;

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

        if ($this->timeIsUp($attempt)) {
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
            if (! $this->timeIsUp($attempt)) {
                $data = $request->validate($this->rules());
                $this->attempts->save($attempt, $data['answers'] ?? [], $data['flags'] ?? [], $data['current'] ?? 0);
            }
            $attempt = $this->attempts->submit($attempt);
        }

        return ['attemptId' => $attempt->id, 'percent' => $attempt->percent, 'redirect' => route('student.result', ['attempt' => $attempt->id])];
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

        $review->update(['appeal' => $data['text'], 'appealed_at' => now(), 'status' => 'pending']);
        $answer->update(['is_final' => false]);
        $this->attempts->recalculate($answer->attempt);

        return ['ok' => true];
    }

    private function rules(): array
    {
        return [
            'answers' => 'array',
            'answers.*' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && mb_strlen($value) > self::MAX_ANSWER_LENGTH) {
                    $fail('Ответ длиннее '.self::MAX_ANSWER_LENGTH.' символов — сократите его');
                }
            }],
            'answers.*.*' => 'nullable|integer',
            'flags' => 'array',
            'current' => 'integer|min:0',
        ];
    }

    /** Время попытки или срок сдачи теста вышли (с запасом в минуту на сеть). */
    private function timeIsUp(Attempt $attempt): bool
    {
        $minutes = (int) $attempt->test->minutes;
        $deadline = $attempt->test->deadline_at;

        return ($minutes > 0 && $attempt->started_at && $attempt->started_at->copy()->addMinutes($minutes + 1)->isPast())
            || ($deadline && $deadline->copy()->addMinute()->isPast());
    }

    private function authorizeOwner(Attempt $attempt): void
    {
        abort_unless($attempt->user_id === CurrentUser::student()->id, 403);
    }
}
