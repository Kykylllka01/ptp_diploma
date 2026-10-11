<?php

namespace App\Services;

use App\Jobs\GradeOpenAnswer;
use App\Models\Answer;
use App\Models\Attempt;
use App\Models\Question;
use App\Models\Review;
use App\Models\Test;
use App\Models\User;
use App\Support\QueueWorker;
use Illuminate\Support\Facades\DB;

/** Попытки прохождения теста: старт, автосохранение, сдача и пересчёт после проверки преподавателем. */
class AttemptService
{
    public function __construct(private GradingService $grading)
    {
    }

    public function current(Test $test, User $user): ?Attempt
    {
        return $test->attempts()->where('user_id', $user->id)->latest('id')->first();
    }

    public function start(Test $test, User $user): Attempt
    {
        $attempt = $this->current($test, $user);
        if ($attempt && ! $attempt->isSubmitted()) {
            return $attempt;
        }

        return $test->attempts()->create([
            'user_id' => $user->id,
            'status' => 'in_progress',
            'flags' => [],
            'started_at' => now(),
        ]);
    }

    /** Автосохранение: ответы по кодам вопросов, отметки и текущий вопрос. */
    public function save(Attempt $attempt, array $answers, array $flags, int $current): void
    {
        $questions = $attempt->test->questions->keyBy('code');

        DB::transaction(function () use ($attempt, $answers, $flags, $current, $questions) {
            foreach ($answers as $code => $value) {
                if (! $q = $questions->get($code)) {
                    continue;
                }
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
        $test = $attempt->test;
        $answers = $attempt->answers()->get()->keyBy('question_id');
        // С настоящей нейросетью развёрнутые ответы оцениваются в фоне: сдача их не ждёт
        $deferOpen = config('agents.driver') !== 'fake';
        $deferred = [];

        DB::transaction(function () use ($attempt, $test, $answers, $deferOpen, &$deferred) {
            foreach ($test->questions as $q) {
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
        $max = (float) $attempt->test->questions->sum('points');
        $score = (float) $answers->sum('score');
        $allFinal = $answers->count() >= $attempt->test->questions->count() && $answers->every('is_final');

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
