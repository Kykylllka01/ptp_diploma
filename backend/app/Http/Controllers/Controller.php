<?php

namespace App\Http\Controllers;

use App\Support\CurrentUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /** Запись другого курса для преподавателя не существует. */
    protected function ownCourse(Model $record): void
    {
        abort_unless((int) $record->course_id === (int) CurrentUser::course()->id, 404);
    }

    /**
     * Менять запись (вопрос, тест) может только её автор: банк общий, но правка коллеги
     * незаметно изменила бы его тест. Записи без автора — общие, как раньше.
     */
    protected function onlyAuthor(Model $record, string $what): void
    {
        $this->ownCourse($record);
        if (! $record->isOwnedBy(CurrentUser::teacher())) {
            abort(403, $what.' может только автор: '.($record->author?->name ?? 'другой преподаватель'));
        }
    }
}
