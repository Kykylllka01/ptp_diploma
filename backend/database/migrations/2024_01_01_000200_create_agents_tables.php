<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Запуск конвейера агентов: Аналитик → Составитель → Критик → Каталогизатор
        Schema::create('generation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('queued');  // queued, running, paused, done, failed
            $table->json('params')->nullable();               // material_ids, types, difficulty, count, ...
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('written')->default(0);
            $table->unsignedInteger('checked')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->unsignedInteger('disputed')->default(0);
            $table->unsignedTinyInteger('catalog_progress')->default(0);
            $table->json('agent_states')->nullable();         // {analyst: [state, label, text], ...}
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('agent', 20);                      // analyst, writer, critic, catalog, grader, examiner
            $table->string('message', 500);
            $table->timestamps();
        });

        // Сводка по агентам для экрана «Обзор»
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('role');
            $table->string('state', 10)->default('done');     // done, warn, work, wait
            $table->string('summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
        Schema::dropIfExists('agent_logs');
        Schema::dropIfExists('generation_runs');
    }
};
