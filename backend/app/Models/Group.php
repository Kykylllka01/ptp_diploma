<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Учебная группа курса. Студенты попадают в неё по ссылке-приглашению /join/{invite_code}
 * (Модеуса нет, поэтому список групп ведёт преподаватель). Таблица student_groups: GROUPS зарезервировано в MySQL.
 */
class Group extends Model
{
    protected $table = 'student_groups';

    protected $fillable = ['course_id', 'name', 'invite_code', 'invite_open'];

    protected $casts = ['invite_open' => 'boolean'];

    /** Без похожих символов (0/o, 1/l/i): код можно продиктовать. 31^12 ≈ 2^59 вариантов. */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    protected static function booted(): void
    {
        static::creating(function (Group $g) {
            $g->invite_code ??= self::newCode();
        });
    }

    public static function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 12; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (self::where('invite_code', $code)->exists());

        return $code;
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(User::class)->where('role', User::ROLE_STUDENT);
    }

    public function tests(): BelongsToMany
    {
        return $this->belongsToMany(Test::class, 'group_test');
    }

    public function inviteUrl(): string
    {
        return route('join', $this->invite_code);
    }
}
