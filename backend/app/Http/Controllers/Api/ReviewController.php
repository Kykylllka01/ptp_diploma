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
        $criteria = $review->question->criteria ?: [['Ответ', $review->question->points]];
        $max = array_sum(array_column($criteria, 1));
        // По баллу на каждый критерий и не больше его максимума — иначе студент увидит «5 / 2»
        $rules = ['scores' => 'required|array|size:'.count($criteria), 'comment' => 'nullable|string|max:5000'];
        foreach ($criteria as $i => [, $cmax]) {
            $rules['scores.'.$i] = 'required|integer|min:0|max:'.(int) $cmax;
        }
        $valid = $request->validate($rules);
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
