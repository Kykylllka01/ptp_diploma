<?php

namespace App\Services;

use App\Agents\Contracts\ExaminerAgent;
use App\Jobs\GradeOpenAnswer;
use App\Models\Answer;
use App\Models\Attempt;
use App\Models\Question;
use App\Models\Review;
use App\Models\Test;
use App\Models\User;
use App\Support\QueueWorker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Попытки прохождения теста: старт, автосохранение, сдача и пересчёт после проверки преподавателем. */
class AttemptService
{
    public function __construct(private GradingService $grading, private ExaminerAgent $examiner)
    {
    }

    /**
     * Свой вариант для студента: Экзаменатор берёт из пула теста вопросы по матрице «тип × сложность»
     * (случайно внутри каждой клетки). Пул не заполняет матрицу — берётся сколько есть.
     *
     * @return int[]|null id вопросов в порядке показа; null — вопросы теста целиком
     */
    public function variant(Test $test): ?array
    {
        if (! $test->isIndividual()) {
            return null;
        }
        $ids = $this->examiner->assemble($test->questions, $test->settings['matrix'] ?? [], $test->settings['topics'] ?? [])
            ->pluck('id')->values()->all();

        return $ids ?: null;
    }

    public function current(Test $test, User $user): ?Attempt
    {
        return $test->attempts()->where('user_id', $user->id)->latest('id')->first();
    }

    public function start(Test $test, User $user): Attempt
    {
        // Двойной клик или предзагрузка страницы не должны создать две попытки и съесть пересдачу
        return Cache::lock('attempt-start:'.$test->id.':'.$user->id, 10)->block(5, function () use ($test, $user) {
            $attempt = $this->current($test, $user);
            if ($attempt && ! $attempt->isSubmitted()) {
                return $attempt;
            }

            return $test->attempts()->create([
                'user_id' => $user->id,
                'group_id' => $user->group_id,
                'status' => 'in_progress',
                'flags' => [],
                'question_ids' => $this->variant($test),
                'started_at' => now(),
            ]);
        });
    }

    /**
     * Брошенные попытки (закрыл вкладку, вышло время, приём закрыт) сдаются с тем, что успело
     * сохраниться: иначе они навсегда «в процессе», не видны в аналитике и закрывают студенту разбор.
     *
     * @return int сколько попыток сдано
     */
    public function finalizeOverdue(?Test $test = null, ?User $user = null): int
    {
        $done = 0;
        Attempt::with('test')->where('status', 'in_progress')
            ->when($test, fn ($q) => $q->where('test_id', $test->id))
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->get()
            ->filter(fn (Attempt $a) => $a->isOverdue())
            ->each(function (Attempt $a) use (&$done) {
                try {
                    $this->submit($a);
                    $done++;
                } catch (\Throwable $e) {
                    report($e);   // учебная база недоступна и т. п. — досдадим в следующий раз
                }
            });

        return $done;
    }

    /** Автосохранение: ответы по кодам вопросов, отметки и текущий вопрос. */
    public function save(Attempt $attempt, array $answers, array $flags, int $current): void
    {
        $questions = $attempt->questionList()->keyBy('code');

        DB::transaction(function () use ($attempt, $answers, $flags, $current, $questions) {
            // Автосохранение, пришедшее после сдачи, не должно переписать уже оценённые ответы
            if (Attempt::whereKey($attempt->id)->lockForUpdate()->value('status') !== 'in_progress') {
                return;
            }
            foreach ($answers as $code => $value) {
                if (! $q = $questions->get($code)) {
                    continue;
                }
                $value = self::normalize($q, $value);
                Answer::updateOrCreate(
                    ['attempt_id' => $attempt->id, 'question_id' => $q->id],
                    ['value' => $value, 'max_score' => $q->points],
                );
            }
            $attempt->update([
                'flags' => array_keys(array_filter($flags)),
                'current_index' => $current,
            ]);
        });
    }

    public function submit(Attempt $attempt): Attempt
    {
        // Двойная отправка: вторая ждёт первую и видит уже сданную попытку — без повторной оценки
        $claimed = DB::transaction(fn () => Attempt::whereKey($attempt->id)->where('status', 'in_progress')
            ->update(['status' => 'grading']) === 1);
        if (! $claimed) {
            return $attempt->fresh();
        }
        try {
            return $this->grade($attempt->fresh());
        } catch (\Throwable $e) {
            // Оценка не удалась (учебная база недоступна) — попытку можно сдать снова
            Attempt::whereKey($attempt->id)->where('status', 'grading')->update(['status' => 'in_progress']);
            throw $e;
        }
    }

    private function grade(Attempt $attempt): Attempt
    {
        $test = $attempt->test;
        $answers = $attempt->answers()->get()->keyBy('question_id');
        // С настоящей нейросетью развёрнутые ответы оцениваются в фоне: сдача их не ждёт
        $deferOpen = config('agents.driver') !== 'fake';
        $deferred = [];

        DB::transaction(function () use ($attempt, $test, $answers, $deferOpen, &$deferred) {
            foreach ($attempt->questionList() as $q) {
                $answer = $answers->get($q->id) ?? new Answer(['attempt_id' => $attempt->id, 'question_id' => $q->id]);
                if ($deferOpen && $q->type === 'open' && GradingService::isAnswered($answer->value)) {
                    $answer->fill([
                        'score' => 0,
                        'max_score' => $q->points,
                        'is_final' => false,
                        'criteria' => array_map(fn ($c) => [0, $c[1], $c[0].': оценивается…'], $q->criteria ?: [['Ответ', $q->points]]),
                    ])->save();
                    $deferred[] = $answer->id;
                    continue;
                }
                $r = $this->grading->grade($q, $answer->value);
                $answer->fill([
                    'score' => $r['score'],
                    'max_score' => $r['max'],
                    'is_final' => $r['final'],
                    'criteria' => $r['criteria'] ?? null,
                ])->save();

                if ($q->type === 'open' && isset($r['agent'])) {
                    $this->createReview($attempt, $answer, $q, $r['agent']);
                }
            }

            $attempt->update(['status' => 'submitted', 'submitted_at' => now()]);
            $this->recalculate($attempt);
        });

        foreach ($deferred as $id) {
            GradeOpenAnswer::dispatch($id);
        }
        if ($deferred) {
            QueueWorker::ensureRunning();
        }

        return $attempt->fresh();
    }

    /** После подтверждения преподавателем переносим итоговый балл в ответ и пересчитываем попытку. */
    /**
     * Ответ к виду, который понимает проверка: номера вариантов — целые в пределах вопроса,
     * SQL и развёрнутый ответ — строка. Иначе массив в SQL-вопросе ронял сдачу целиком.
     */
    public static function normalize(Question $q, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (in_array($q->type, ['sql', 'open'], true)) {
            return is_scalar($value) ? (string) $value : null;
        }
        if (! is_array($value)) {
            return null;
        }
        if ($q->type === 'match') {
            $defs = count($q->matchParts()['definitions']);

            return array_map(fn ($v) => is_numeric($v) && (int) $v >= 0 && (int) $v < $defs ? (int) $v : -1, array_values($value));
        }
        $count = count($q->options ?? []);

        return array_values(array_unique(array_filter(array_map('intval', array_filter($value, 'is_numeric')), fn ($v) => $v >= 0 && $v < $count)));
    }

    public function applyReview(Review $review): void
    {
        if (! $answer = $review->answer) {
            return;
        }
        $criteria = $answer->criteria ?? [];
        foreach ($review->scores ?? [] as $i => $s) {
            if (isset($criteria[$i])) {
                $criteria[$i][0] = $s;
            }
        }
        $answer->update(['score' => $review->final_score, 'is_final' => true, 'criteria' => $criteria]);
        $this->recalculate($answer->attempt);
    }

    public function recalculate(Attempt $attempt): void
    {
        $answers = $attempt->answers()->get();
        $questions = $attempt->questionList();
        $max = (float) $questions->sum('points');
        $score = (float) $answers->sum('score');
        $allFinal = $answers->count() >= $questions->count() && $answers->every('is_final');

        $attempt->update([
            'score' => $score,
            'max_score' => $max,
            'percent' => $max > 0 ? (int) round($score / $max * 100) : 0,
            'status' => $attempt->isSubmitted() ? ($allFinal ? 'confirmed' : 'submitted') : $attempt->status,
        ]);
    }

    public function createReview(Attempt $attempt, Answer $answer, Question $q, array $agent): void
    {
        Review::updateOrCreate(['answer_id' => $answer->id], [
            'question_id' => $q->id,
            'student_id' => $attempt->user_id,
            'answer_html' => $agent['highlighted'],
            'confidence' => $agent['confidence'],
            'minutes' => $attempt->minutesSpent(),
            'agent_scores' => $agent['scores'],
            'comment' => $agent['comment'],
            'status' => 'pending',
        ]);
    }
}
