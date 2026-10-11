<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\AttemptService;
use App\Services\FrontendData;
use App\Support\CurrentUser;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /** Подтверждение оценки развёрнутого ответа преподавателем. */
    public function update(Request $request, Review $review, AttemptService $attempts, FrontendData $data)
    {
        $max = array_sum(array_column($review->question->criteria ?? [], 1)) ?: $review->question->points;
        $valid = $request->validate([
            'scores' => 'required|array',
            'scores.*' => 'integer|min:0',
            'comment' => 'nullable|string|max:5000',
        ]);
        $final = min($max, array_sum($valid['scores']));

        $review->update([
            'scores' => $valid['scores'],
            'final_score' => $final,
            'comment' => $valid['comment'] ?? null,
            'status' => 'confirmed',
            'confirmed_by' => CurrentUser::teacher()->id,
            'confirmed_at' => now(),
        ]);
        $attempts->applyReview($review);

        return $data->review($review->fresh(['student', 'question']));
    }
}
