<?php

use App\Http\Controllers\Api\AttemptController;
use App\Http\Controllers\Api\GenerationRunController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\TestController;
use Illuminate\Support\Facades\Route;

/*
| API для экранов. Экраны и API живут на одном домене, поэтому API работает
| на той же сессии, что и страницы (middleware web: сессия + CSRF-токен
| из <meta name="csrf-token">), и закрыт по ролям.
*/

Route::middleware(['web', 'auth'])->group(function () {
    // Преподаватель
    Route::middleware('role:teacher')->group(function () {
        Route::get('questions', [QuestionController::class, 'index']);
        Route::post('questions/bulk', [QuestionController::class, 'bulk']);
        Route::get('questions/{question}', [QuestionController::class, 'show']);
        Route::patch('questions/{question}', [QuestionController::class, 'update']);
        Route::post('questions/{question}/regenerate', [QuestionController::class, 'regenerate']);

        Route::get('materials', [MaterialController::class, 'index']);
        Route::get('materials/{material}', [MaterialController::class, 'show']);
        Route::post('materials', [MaterialController::class, 'store']);
        Route::post('materials/{material}/ocr', [MaterialController::class, 'ocr']);
        Route::delete('materials/{material}', [MaterialController::class, 'destroy']);

        Route::post('generation-runs', [GenerationRunController::class, 'store']);
        Route::get('generation-runs/{run}', [GenerationRunController::class, 'show']);

        Route::get('bank-stats', [TestController::class, 'bankStats']);
        Route::post('tests', [TestController::class, 'store']);
        Route::post('tests/{test}/questions', [TestController::class, 'attachQuestions']);
        Route::patch('tests/{test}', [TestController::class, 'update']);
        Route::delete('tests/{test}', [TestController::class, 'destroy']);
        Route::post('questions', [QuestionController::class, 'store']);
        Route::get('questions-export', [QuestionController::class, 'export']);
        Route::post('questions/{question}/preview-check', [QuestionController::class, 'previewCheck']);
        Route::post('generation-runs/{run}/cancel', [GenerationRunController::class, 'cancel']);

        Route::patch('reviews/{review}', [ReviewController::class, 'update']);

        Route::post('groups', [GroupController::class, 'store']);
        Route::patch('groups/{group}', [GroupController::class, 'update']);
        Route::post('groups/{group}/invite', [GroupController::class, 'invite']);
        Route::delete('groups/{group}', [GroupController::class, 'destroy']);
        Route::patch('students/{user}/group', [GroupController::class, 'moveStudent']);
    });

    // Студент
    Route::middleware('role:student')->group(function () {
        Route::put('attempts/{attempt}', [AttemptController::class, 'update']);
        Route::post('attempts/{attempt}/submit', [AttemptController::class, 'submit']);
        // Проверка SQL-ответа запускает учебную базу — частоту ограничиваем, как у sql/run
        Route::post('questions/{question}/check', [QuestionController::class, 'check'])->middleware('throttle:60,1');
        Route::post('answers/{answer}/appeal', [AttemptController::class, 'appeal']);
    });

    // Учебная база SQL нужна обоим
    Route::post('sql/run', [AttemptController::class, 'runSql'])->middleware(['role:teacher,student', 'throttle:30,1']);
});
