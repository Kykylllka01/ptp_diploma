<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Генерация идёт частями по нескольку минут, каждая часть — отдельная задача очереди.
 * Между частями прогресс запуска (разборы материалов, план, где остановились) хранится здесь.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->longText('state')->nullable()->after('agent_states');
        });
    }

    public function down(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->dropColumn('state');
        });
    }
};
