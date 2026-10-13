<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Жёсткий прокторинг: каждый уход со страницы теста во время попытки
 * (другая вкладка, свёрнутое окно, другое окно, закрытая или перезагруженная страница).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proctor_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                        // hidden, blur, close
            $table->timestamp('left_at');                      // по часам сервера
            $table->timestamp('returned_at')->nullable();      // пусто — студент не вернулся на страницу
            $table->index('attempt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proctor_events');
    }
};
