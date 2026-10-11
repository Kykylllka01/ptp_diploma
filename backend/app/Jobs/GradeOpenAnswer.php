<?php

namespace App\Jobs;

use App\Agents\Llm\AgentLlm;
use App\Models\Agent;
use App\Models\Answer;
use App\Models\Review;
use App\Services\AttemptService;
use App\Services\GradingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Предварительная оценка развёрнутого ответа Оценщиком в фоне: три прогона модели
 * занимают десятки секунд, поэтому сдача теста их не ждёт. После оценки ответ
 * попадает в очередь проверки преподавателя.
 */
class GradeOpenAnswer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    /**
     * Одна попытка: сетевые сбои повторяет клиент модели, а упавший прогон Оценщик заменяет следующим.
     * Отложенный повтор очереди не сработал бы — обработчик запускается с --stop-when-empty и выходит раньше.
     */
    public int $tries = 1;

    public function __construct(public int $answerId)
    {
    }

    public function handle(GradingService $grading, AttemptService $attempts): void
    {
        $answer = Answer::with('attempt', 'question')->find($this->answerId);
        if (! $answer || $answer->is_final) {
            return;
        }
        AgentLlm::context(['answer_id' => $answer->id]);
        try {
            $r = $grading->grade($answer->question, (string) ($answer->value ?? ''));
        } finally {
            AgentLlm::context([]);
        }

        $answer->update(['score' => $r['score'], 'criteria' => $r['criteria'] ?? null]);
        if (isset($r['agent'])) {
            $attempts->createReview($answer->attempt, $answer, $answer->question, $r['agent']);
            // Настройка теста «автоматическая оценка»: при уверенности от 90% балл агента становится итоговым
            $review = Review::where('answer_id', $answer->id)->first();
            if ($review && $answer->attempt->test->setting('auto_grade') && $review->confidence >= 90) {
                $review->update([
                    'scores' => array_column($review->agent_scores, 0),
                    'final_score' => array_sum(array_column($review->agent_scores, 0)),
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                ]);
                $attempts->applyReview($review);
            }
            $pending = Review::where('status', 'pending')->count();
            Agent::report('grader', 'done', 'Оценил ответ на '.$answer->question->code.': '.$r['score'].' из '.$r['max'].', уверенность '.$r['agent']['confidence'].'%'.($pending ? ' · ждут подтверждения: '.$pending : ''));
        }
        $attempts->recalculate($answer->attempt);
    }

    /**
     * Оценщик так и не ответил (все попытки исчерпаны или задача убита): ответ уходит преподавателю
     * без подсказки, а не висит у студента «оценивается…».
     */
    public function failed(?Throwable $e): void
    {
        $answer = Answer::with('attempt', 'question')->find($this->answerId);
        if (! $answer || $answer->is_final || $answer->review) {
            return;
        }
        $attempts = app(AttemptService::class);
        $criteria = array_map(fn ($c) => [0, $c[1], $c[0].': ждёт проверки преподавателем'], $answer->question->criteria ?: [['Ответ', $answer->max_score]]);
        $answer->update(['criteria' => $criteria]);
        $attempts->createReview($answer->attempt, $answer, $answer->question, [
            'highlighted' => e((string) $answer->value),
            'confidence' => 0,
            'scores' => array_map(fn ($c) => [0, 'no', 'оценщик недоступен'], $criteria),
            'comment' => 'Оценщик не смог проверить ответ'.($e ? ' ('.mb_substr($e->getMessage(), 0, 120).')' : '').', нужна проверка преподавателя.',
        ]);
        Agent::report('grader', 'warn', 'Не смог оценить ответ на '.$answer->question->code.' — передан преподавателю');
        $attempts->recalculate($answer->attempt);
    }
}
