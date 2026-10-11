<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Журнал обращений агентов к языковой модели: из него считаются расход, время и стоимость
        Schema::create('llm_calls', function (Blueprint $table) {
            $table->id();
            $table->string('agent', 20);                      // analyst, writer, critic, catalog, grader
            $table->string('model', 60);
            $table->string('prompt_version', 40);             // writer.v2
            $table->foreignId('generation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('answer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('tokens_reasoning')->default(0);
            $table->unsignedInteger('ms')->default(0);
            $table->string('status', 15);                     // ok, invalid_json, error
            $table->longText('request')->nullable();          // сообщение пользователя (данные для агента)
            $table->longText('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        // Очередь Laravel (QUEUE_CONNECTION=database): генерация и проверка ответов идут в фоне
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('llm_calls');
    }
};
