<?php

use App\Agents\Contracts\ExaminerAgent;
use App\Models\Test;
use Illuminate\Database\Migrations\Migration;

/**
 * У тестов «свой вариант каждому» запоминается, сколько вопросов на самом деле в варианте:
 * раньше показывалась сумма матрицы, хотя на часть клеток в банке вопросов не хватало.
 */
return new class extends Migration
{
    public function up(): void
    {
        $examiner = app(ExaminerAgent::class);
        Test::with('questions')->get()->filter(fn (Test $t) => $t->isIndividual() && ! isset($t->settings['variant']))
            ->each(function (Test $t) use ($examiner) {
                $variant = $examiner->assemble($t->questions, $t->settings['matrix'] ?? [], $t->settings['topics'] ?? [])->count();
                $t->update(['settings' => ['variant' => $variant] + $t->settings]);
            });
    }

    public function down(): void
    {
        // Число в settings ничему не мешает
    }
};
