<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Автор записи (материала, вопроса, теста) — преподаватель, который её создал.
 * Курс общий, поэтому видят и используют записи все преподаватели, а удаляет только автор.
 */
trait HasAuthor
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Свою запись (и запись без автора, например удалённого преподавателя) можно удалять. */
    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === null || $this->user_id === $user->id;
    }
}
