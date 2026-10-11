<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('semester')->nullable();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('topics')->nullable();           // список тем курса
            $table->timestamps();
        });

        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('kind', 10);                   // PDF, DOCX, PPTX, TXT, URL, MP4, FILE
            $table->string('meta')->nullable();           // «42 стр.», «28 слайдов»
            $table->string('file_path')->nullable();      // путь на диске local: materials/...
            $table->string('url', 1000)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('has_text')->default(true);   // false — скан без текстового слоя, нужен OCR
            $table->longText('extracted_text')->nullable();
            // uploaded → processing → review (есть вопросы на проверке) → ready; error
            $table->string('status', 20)->default('uploaded');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
        Schema::dropIfExists('courses');
    }
};
