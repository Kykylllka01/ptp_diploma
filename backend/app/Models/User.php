<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_TEACHER = 'teacher';
    public const ROLE_STUDENT = 'student';

    protected $fillable = [
        'name', 'full_name', 'initials', 'avatar_path', 'role', 'group_id', 'email', 'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function isTeacher(): bool
    {
        return $this->role === self::ROLE_TEACHER;
    }

    /** Адрес аватара или null; версия в адресе меняется при замене картинки (кэш браузера). */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? route('avatar', ['user' => $this->id, 'v' => pathinfo($this->avatar_path, PATHINFO_FILENAME)]) : null;
    }

    /** Учебная группа студента (у преподавателя — нет). */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }
}
