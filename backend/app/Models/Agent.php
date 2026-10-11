<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Сводная карточка агента на экране «Обзор». */
class Agent extends Model
{
    protected $fillable = ['code', 'name', 'role', 'state', 'summary'];

    public static function report(string $code, string $state, string $summary): void
    {
        static::where('code', $code)->update(['state' => $state, 'summary' => $summary]);
    }
}
