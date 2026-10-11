<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();             // Q-121
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('generation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 10);                       // single, multiple, tf, match, sql, open
            $table->string('difficulty', 10)->default('medium'); // easy, medium, hard
            $table->string('topic')->nullable();
            $table->string('source')->nullable();             // «Лекция 4, с. 24»
            $table->unsignedTinyInteger('points')->default(1);
            $table->string('status', 10)->default('pending'); // pending, approved, rejected
            $table->string('flag_text')->nullable();          // главное замечание критика для списка
            $table->string('flag_level', 10)->nullable();     // red, amber
            $table->text('text');
            $table->json('options')->nullable();              // [{text, correct?, flag?}]
            $table->text('explanation')->nullable();
            $table->text('reference')->nullable();            // эталон для sql / open
            $table->json('criteria')->nullable();             // [[текст критерия, баллы], ...] для open
            $table->string('sql_schema')->nullable();         // «groups(id, name), students(...)»
            $table->json('tags')->nullable();
            $table->json('critic')->nullable();               // {score, checks: [{ok, text}], fix?}
            $table->boolean('fix_applied')->default(false);
            $table->text('quote')->nullable();                // фрагмент источника с <mark>
            $table->json('history')->nullable();              // [[время, событие], ...]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
