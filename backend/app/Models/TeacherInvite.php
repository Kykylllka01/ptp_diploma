<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Ссылка-приглашение преподавателя: /teacher-invite/{token}. Выдаёт администратор;
 * работает, пока не исчерпана, не истекла и не отозвана.
 */
class TeacherInvite extends Model
{
    protected $fillable = ['token', 'note', 'max_uses', 'uses', 'expires_at', 'revoked_at', 'created_by'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public static function issue(?User $by, ?string $note, int $maxUses, int $days): self
    {
        return self::create([
            // 40 букв и цифр: подобрать перебором невозможно
            'token' => Str::random(40),
            'note' => $note ?: null,
            'max_uses' => $maxUses,
            'expires_at' => now()->addDays($days)->endOfDay(),
            'created_by' => $by?->id,
        ]);
    }

    /** active — действует; used — регистрации кончились; expired — срок вышел; revoked — отозвана. */
    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->uses >= $this->max_uses => 'used',
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }

    public function isUsable(): bool
    {
        return $this->status() === 'active';
    }

    public function url(): string
    {
        return route('teacher-invite', $this->token);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Преподаватели, зарегистрированные по этой ссылке. */
    public function teachers(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
