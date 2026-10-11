<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Апелляция студента: ответ возвращается в очередь проверки с его пояснением
        Schema::table('reviews', function (Blueprint $table) {
            $table->text('appeal')->nullable()->after('comment');
            $table->timestamp('appealed_at')->nullable()->after('appeal');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['appeal', 'appealed_at']);
        });
    }
};
