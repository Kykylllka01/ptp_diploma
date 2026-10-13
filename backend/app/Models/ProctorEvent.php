<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Событие прокторинга во время попытки: студент ушёл со страницы теста (и, если вернулся, — когда). */
class ProctorEvent extends Model
{
    /** hidden — другая вкладка или свёрнутое окно, blur — другое окно или программа, close — закрыл или перезагрузил страницу теста. */
    public const KINDS = ['hidden', 'blur', 'close'];

    public const LABELS = [
        'hidden' => 'Другая вкладка или свёрнутое окно',
        'blur' => 'Другое окно или программа',
        'close' => 'Страница теста закрыта или перезагружена',
    ];

    public $timestamps = false;

    protected $fillable = ['attempt_id', 'kind', 'left_at', 'returned_at'];

    protected $casts = [
        'left_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }

    /** Сколько секунд студент провёл вне страницы; null — не вернулся. */
    public function seconds(): ?int
    {
        return $this->returned_at ? max(0, (int) $this->left_at->diffInSeconds($this->returned_at)) : null;
    }
}
