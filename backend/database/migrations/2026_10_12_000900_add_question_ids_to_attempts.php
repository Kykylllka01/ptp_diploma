<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индивидуальный вариант: у теста с настройкой individual к тесту прикреплён пул вопросов,
 * а у каждой попытки — свой набор из пула (id в порядке показа). Пусто — все вопросы теста.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            $table->json('question_ids')->nullable()->after('flags');
        });
    }

    public function down(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            $table->dropColumn('question_ids');
        });
    }
};
