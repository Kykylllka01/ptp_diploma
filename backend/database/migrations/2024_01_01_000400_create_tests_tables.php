<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();             // m1, lab2
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('status', 10)->default('draft');   // draft, running, training, closed
            $table->string('mode', 10)->default('rules');     // rules, manual, adaptive
            $table->string('group_name')->nullable();
            $table->unsignedSmallInteger('minutes')->default(45);
            $table->unsignedTinyInteger('attempts_allowed')->default(1);
            $table->boolean('graded')->default(true);
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->text('about')->nullable();
            $table->json('settings')->nullable();             // матрица «тип × сложность», темы
            $table->timestamps();
        });

        Schema::create('question_test', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unique(['test_id', 'question_id']);
        });

        Schema::create('attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 15)->default('in_progress'); // in_progress, submitted, confirmed
            $table->json('flags')->nullable();                 // коды вопросов «вернуться позже»
            $table->unsignedSmallInteger('current_index')->default(0);
            $table->decimal('score', 6, 2)->nullable();
            $table->decimal('max_score', 6, 2)->nullable();
            $table->unsignedTinyInteger('percent')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->json('value')->nullable();                 // [индексы вариантов] или текст
            $table->decimal('score', 5, 2)->nullable();
            $table->decimal('max_score', 5, 2)->nullable();
            $table->boolean('is_final')->default(false);       // false — предварительно, ждёт преподавателя
            $table->json('criteria')->nullable();              // [[балл, макс, комментарий], ...]
            $table->timestamps();
            $table->unique(['attempt_id', 'question_id']);
        });

        // Проверка развёрнутых ответов: оценка агента + подтверждение преподавателем
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('answer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->text('answer_html');                       // ответ с подсветкой фрагментов (<mark>)
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->json('agent_scores');                      // [[балл, ok|part|no, пояснение], ...]
            $table->json('scores')->nullable();                // баллы преподавателя по критериям
            $table->unsignedTinyInteger('final_score')->nullable();
            $table->text('comment')->nullable();
            $table->string('status', 10)->default('pending');  // pending, confirmed
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('answers');
        Schema::dropIfExists('attempts');
        Schema::dropIfExists('question_test');
        Schema::dropIfExists('tests');
    }
};
