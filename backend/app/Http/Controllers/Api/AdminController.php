<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\CreateTeacher;
use App\Http\Controllers\Controller;
use App\Models\TeacherInvite;
use App\Models\User;
use App\Services\FrontendData;
use App\Support\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Действия администратора: выдать и отозвать ссылку-приглашение, новый пароль преподавателю, закрыть доступ. */
class AdminController extends Controller
{
    public function __construct(private FrontendData $data)
    {
    }

    public function storeInvite(Request $request)
    {
        $data = $request->validate([
            'note' => 'nullable|string|max:120',
            'max_uses' => ['required', 'integer', Rule::in([1, 5, 10, 30])],
            'days' => ['required', 'integer', Rule::in([1, 3, 7, 30])],
        ]);
        $invite = TeacherInvite::issue(CurrentUser::user(), trim((string) ($data['note'] ?? '')), $data['max_uses'], $data['days']);

        return $this->data->invite($invite->loadMissing('teachers:id,name,teacher_invite_id'));
    }

    public function revokeInvite(TeacherInvite $invite)
    {
        if ($invite->revoked_at === null) {
            $invite->update(['revoked_at' => now()]);
        }

        return $this->data->invite($invite->load('teachers:id,name,teacher_invite_id'));
    }

    /** Новый временный пароль: старый перестаёт работать, открытые сессии преподавателя заканчиваются. */
    public function teacherPassword(User $user)
    {
        abort_unless($user->isTeacher(), 404);
        $password = CreateTeacher::temporaryPassword();
        $user->forceFill(['password' => $password, 'remember_token' => null])->save();

        return ['id' => $user->id, 'password' => $password];
    }

    /** Закрыть или вернуть доступ. Материалы, вопросы и тесты преподавателя остаются на курсе. */
    public function teacherAccess(Request $request, User $user)
    {
        abort_unless($user->isTeacher(), 404);
        $data = $request->validate(['disabled' => 'required|boolean']);
        $user->forceFill(['disabled_at' => $data['disabled'] ? now() : null, 'remember_token' => null])->save();

        return $this->data->adminTeacher($user->load('teacherInvite:id,note'));
    }
}
