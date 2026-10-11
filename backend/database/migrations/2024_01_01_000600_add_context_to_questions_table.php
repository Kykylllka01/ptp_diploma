<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Исходные данные вопроса: схема отношения, функциональные зависимости, строки таблицы.
        // Показываются студенту над вопросом, чтобы вопрос не ссылался на таблицы лекции.
        Schema::table('questions', function (Blueprint $table) {
            $table->text('context')->nullable()->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
