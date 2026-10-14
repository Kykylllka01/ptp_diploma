<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Администратор выдаёт преподавателям ссылки-приглашения, по ним они регистрируются сами.
 * Ссылка одноразовая или на несколько регистраций, со сроком действия; её можно отозвать.
 * У пользователя — по какой ссылке зарегистрирован и закрыт ли доступ администратором.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_invites', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('note', 120)->nullable();            // для кого: «Петрова, кафедра ИБ»
            $table->unsignedSmallInteger('max_uses')->default(1);
            $table->unsignedSmallInteger('uses')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('role');
            $table->foreignId('teacher_invite_id')->nullable()->after('disabled_at')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('teacher_invite_id');
            $table->dropColumn('disabled_at');
        });
        Schema::dropIfExists('teacher_invites');
    }
};
