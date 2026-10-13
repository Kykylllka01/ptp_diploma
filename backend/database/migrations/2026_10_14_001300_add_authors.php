<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Несколько преподавателей на одном курсе: у материала, вопроса и теста есть автор.
 * Банк общий (вопросы можно брать в свои тесты), но по умолчанию каждый видит своё, а удалить может только автор.
 * Записи, созданные до этой миграции, достаются первому преподавателю; вопрос — автору запуска агентов.
 */
return new class extends Migration
{
    private const TABLES = ['materials', 'questions', 'tests'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('course_id')->constrained()->nullOnDelete();
            });
        }

        $first = DB::table('users')->where('role', 'teacher')->orderBy('id')->value('id');
        if ($first) {
            DB::table('materials')->whereNull('user_id')->update(['user_id' => $first]);
            DB::table('tests')->whereNull('user_id')->update(['user_id' => $first]);
            foreach (DB::table('generation_runs')->whereNotNull('user_id')->get(['id', 'user_id']) as $run) {
                DB::table('questions')->where('generation_run_id', $run->id)->whereNull('user_id')->update(['user_id' => $run->user_id]);
            }
            DB::table('questions')->whereNull('user_id')->update(['user_id' => $first]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};
