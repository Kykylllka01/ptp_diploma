<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Группы без Модеуса: преподаватель заводит группу один раз, студенты попадают в неё по ссылке-приглашению.
 * Было: строка users.group_name и tests.group_name (одна группа или все). Стало: таблица student_groups
 * (GROUPS — зарезервированное слово MySQL 8), users.group_id, связь group_test и явное tests.audience:
 * all — все студенты курса, groups — только отмеченные группы (пустой список групп ≠ «всем»).
 * Попытка запоминает группу студента на момент старта — перевод в другую группу не переносит старые результаты.
 */
return new class extends Migration
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function up(): void
    {
        Schema::create('student_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('invite_code', 32)->unique();
            $table->boolean('invite_open')->default(true);
            $table->timestamps();
            $table->unique(['course_id', 'name']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('role')->constrained('student_groups')->nullOnDelete();
        });
        Schema::table('tests', function (Blueprint $table) {
            $table->string('audience', 10)->default('all')->after('mode');
        });
        Schema::table('attempts', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('user_id')->constrained('student_groups')->nullOnDelete();
        });

        // Группу с назначенными тестами удалить нельзя — иначе тест молча остался бы без участников
        Schema::create('group_test', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained('student_groups')->restrictOnDelete();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->primary(['group_id', 'test_id']);
        });

        // Перенос: каждое встретившееся название становится группой курса
        $courseId = DB::table('courses')->orderBy('id')->value('id');
        $names = DB::table('users')->whereNotNull('group_name')->distinct()->pluck('group_name')
            ->merge(DB::table('tests')->whereNotNull('group_name')->distinct()->pluck('group_name'))
            ->map(fn ($n) => trim($n))->filter()->unique()->values();
        if ($courseId) {
            foreach ($names as $name) {
                $id = DB::table('student_groups')->insertGetId([
                    'course_id' => $courseId, 'name' => $name, 'invite_code' => $this->code(), 'invite_open' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('users')->where('group_name', $name)->update(['group_id' => $id]);
                foreach (DB::table('tests')->where('group_name', $name)->pluck('id') as $testId) {
                    DB::table('group_test')->insert(['group_id' => $id, 'test_id' => $testId]);
                    DB::table('tests')->where('id', $testId)->update(['audience' => 'groups']);
                }
            }
            // Прошлые попытки — в той группе, где студент сейчас
            foreach (DB::table('users')->whereNotNull('group_id')->get(['id', 'group_id']) as $u) {
                DB::table('attempts')->where('user_id', $u->id)->update(['group_id' => $u->group_id]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'group_name']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('group_name');
            $table->index(['role', 'group_id']);
        });
        Schema::table('tests', function (Blueprint $table) {
            $table->dropColumn('group_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('group_name')->nullable()->after('role');
        });
        Schema::table('tests', function (Blueprint $table) {
            $table->string('group_name')->nullable()->after('mode');
        });

        // Обратно в строки; у теста для нескольких групп остаётся первая
        foreach (DB::table('student_groups')->get() as $g) {
            DB::table('users')->where('group_id', $g->id)->update(['group_name' => $g->name]);
        }
        foreach (DB::table('group_test')->join('student_groups', 'student_groups.id', '=', 'group_test.group_id')
            ->orderBy('student_groups.id')->get(['group_test.test_id', 'student_groups.name']) as $row) {
            DB::table('tests')->where('id', $row->test_id)->whereNull('group_name')->update(['group_name' => $row->name]);
        }

        Schema::dropIfExists('group_test');
        Schema::table('attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
        });
        Schema::table('tests', function (Blueprint $table) {
            $table->dropColumn('audience');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'group_id']);
            $table->dropConstrainedForeignId('group_id');
            $table->index(['role', 'group_name']);
        });
        Schema::dropIfExists('student_groups');
    }

    private function code(): string
    {
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
};
