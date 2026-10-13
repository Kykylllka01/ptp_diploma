<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TeacherController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    // Регистрация только по ссылке-приглашению группы (код выдаёт преподаватель на экране «Группы»)
    Route::get('register', [JoinController::class, 'enterCode'])->name('register')->middleware('throttle:300,1');
    // Вся группа регистрируется с одного вузовского Wi-Fi (один IP) — лимит по почте и с запасом на IP (RouteServiceProvider)
    Route::post('join/{code}', [JoinController::class, 'register'])->middleware('throttle:join');
});

// Страница приглашения открывается и гостю (форма регистрации), и вошедшему (подсказка, что делать)
Route::get('join/{code}', [JoinController::class, 'show'])->name('join')->middleware('throttle:600,1');
Route::post('join/{code}/accept', [JoinController::class, 'accept'])->name('join.accept')->middleware(['auth', 'role:student', 'throttle:10,1']);

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
        Route::get('groups', 'groups')->name('groups');
        Route::get('gradebook', 'gradebook')->name('gradebook');
        Route::get('attempt', 'attempt')->name('attempt');
        Route::get('materials/{material}/file', 'materialFile')->name('material.file');
    });

    Route::prefix('student')->name('student.')->middleware('role:student')->controller(StudentController::class)->group(function () {
        Route::get('tests', 'tests')->name('tests');
        Route::get('test', 'test')->name('test');
        Route::get('result', 'result')->name('result');
        Route::get('results', 'results')->name('results');
    });

    // Личный кабинет — для обеих ролей
    Route::get('profile', [\App\Http\Controllers\ProfileController::class, 'show'])->name('profile');
    Route::post('profile/password', [\App\Http\Controllers\ProfileController::class, 'password'])->name('profile.password');
    Route::post('profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
    Route::post('profile/avatar', [AvatarController::class, 'store'])->name('profile.avatar')->middleware('throttle:20,1');
    Route::delete('profile/avatar', [AvatarController::class, 'destroy'])->name('profile.avatar.delete');
    Route::get('avatars/{user}', [AvatarController::class, 'show'])->name('avatar');
});
