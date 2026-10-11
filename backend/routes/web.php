<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TeacherController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::get('/', [AuthController::class, 'home'])->name('home');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::prefix('teacher')->name('teacher.')->middleware('role:teacher')->controller(TeacherController::class)->group(function () {
        Route::get('dashboard', 'dashboard')->name('dashboard');
        Route::get('upload', 'upload')->name('upload');
        Route::get('pipeline', 'pipeline')->name('pipeline');
        Route::get('bank', 'bank')->name('bank');
        Route::get('question', 'question')->name('question');
        Route::get('test-builder', 'testBuilder')->name('test-builder');
        Route::get('tests', 'tests')->name('tests');
        Route::get('preview', 'preview')->name('preview');
        Route::get('analytics', 'analytics')->name('analytics');
        Route::get('review', 'review')->name('review');
    });

    Route::prefix('student')->name('student.')->middleware('role:student')->controller(StudentController::class)->group(function () {
        Route::get('tests', 'tests')->name('tests');
        Route::get('test', 'test')->name('test');
        Route::get('result', 'result')->name('result');
    });

    // Личный кабинет — для обеих ролей
    Route::get('profile', [\App\Http\Controllers\ProfileController::class, 'show'])->name('profile');
    Route::post('profile/password', [\App\Http\Controllers\ProfileController::class, 'password'])->name('profile.password');
    Route::post('profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
});
