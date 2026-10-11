<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Учёт расхода модели и индексы под запросы, которые реально выполняются:
 * - llm_calls: токены из кэша DeepSeek и стоимость вызова — для оптимизации и экспериментальной главы;
 * - reviews.answer_id уникален: у ответа одна проверка, повторная сдача не создаёт дубль;
 * - составные индексы для попыток, банка, тестов и студентов групп.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_calls', function (Blueprint $table) {
            $table->unsignedInteger('tokens_cached')->default(0)->after('tokens_in');
            $table->decimal('cost_usd', 10, 6)->default(0)->after('ms');
            $table->index(['agent', 'created_at']);
        });
        // Дубли проверки одного ответа (двойная сдача до этой миграции): оставляем последнюю
        $dupes = DB::table('reviews')->whereNotNull('answer_id')->groupBy('answer_id')->havingRaw('count(*) > 1')->pluck('answer_id');
        foreach ($dupes as $answerId) {
            $keep = DB::table('reviews')->where('answer_id', $answerId)->max('id');
            DB::table('reviews')->where('answer_id', $answerId)->where('id', '!=', $keep)->delete();
        }
        Schema::table('reviews', function (Blueprint $table) {
            $table->unique('answer_id');
            $table->index('status');
        });
        Schema::table('attempts', function (Blueprint $table) {
            $table->index(['test_id', 'user_id', 'status']);
            $table->index(['status', 'started_at']);
        });
        Schema::table('questions', function (Blueprint $table) {
            $table->index(['course_id', 'status']);
        });
        Schema::table('tests', function (Blueprint $table) {
            $table->index(['course_id', 'status']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'group_name']);
        });
    }

    /**
     * MySQL удаляет свой индекс под внешний ключ, когда появляется другой индекс, начинающийся с той же
     * колонки, и не даёт удалить последний такой индекс. Поэтому сначала возвращаем обычный индекс.
     */
    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex(['role', 'group_name']));
        Schema::table('tests', function (Blueprint $table) {
            $table->index('course_id');
            $table->dropIndex(['course_id', 'status']);
        });
        Schema::table('questions', function (Blueprint $table) {
            $table->index('course_id');
            $table->dropIndex(['course_id', 'status']);
        });
        Schema::table('attempts', function (Blueprint $table) {
            $table->index('test_id');
            $table->dropIndex(['status', 'started_at']);
            $table->dropIndex(['test_id', 'user_id', 'status']);
        });
        Schema::table('reviews', function (Blueprint $table) {
            $table->index('answer_id');
            $table->dropIndex(['status']);
            $table->dropUnique(['answer_id']);
        });
        Schema::table('llm_calls', function (Blueprint $table) {
            $table->dropIndex(['agent', 'created_at']);
            $table->dropColumn(['tokens_cached', 'cost_usd']);
        });
    }
};
