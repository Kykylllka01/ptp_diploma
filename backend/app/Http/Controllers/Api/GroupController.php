<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use App\Services\FrontendData;
use App\Support\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Группы курса (экран «Группы»): создать, переименовать, открыть/закрыть приём по ссылке,
 * выпустить новую ссылку, удалить пустую группу, перевести студента в другую группу.
 */
class GroupController extends Controller
{
    public function __construct(private FrontendData $data)
    {
    }

    public function store(Request $request)
    {
        $course = CurrentUser::course();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('student_groups', 'name')->where('course_id', $course->id)],
        ], ['name.unique' => 'Группа с таким названием уже есть']);

        return $this->data->group(Group::create(['course_id' => $course->id, 'name' => trim($data['name'])]));
    }

    public function update(Request $request, Group $group)
    {
        $this->ownGroup($group);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100',
                Rule::unique('student_groups', 'name')->where('course_id', $group->course_id)->ignore($group->id)],
            'invite_open' => 'sometimes|boolean',
        ], ['name.unique' => 'Группа с таким названием уже есть']);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        $group->update($data);

        return $this->data->group($group);
    }

    /** Новая ссылка: старая перестаёт работать (если её переслали не туда). */
    public function invite(Group $group)
    {
        $this->ownGroup($group);
        $group->update(['invite_code' => Group::newCode()]);

        return $this->data->group($group);
    }

    /** Удалить можно только пустую группу без тестов — иначе потерялись бы участники тестов и результаты. */
    public function destroy(Group $group)
    {
        $this->ownGroup($group);
        if ($group->students()->exists()) {
            return response()->json(['message' => 'В группе есть студенты — сначала переведите их в другую группу'], 409);
        }
        if ($group->tests()->exists()) {
            return response()->json(['message' => 'Группе назначены тесты — удалить её нельзя. Закройте приём по ссылке'], 409);
        }
        $group->delete();

        return response()->noContent();
    }

    /** Перевести студента в другую группу или убрать из группы (group_id = null). */
    public function moveStudent(Request $request, User $user)
    {
        abort_unless($user->role === User::ROLE_STUDENT, 404);
        $data = $request->validate([
            'group_id' => ['present', 'nullable', 'integer', Rule::exists('student_groups', 'id')->where('course_id', CurrentUser::course()->id)],
        ]);
        $user->update(['group_id' => $data['group_id']]);

        return ['id' => $user->id, 'group' => $data['group_id']];
    }

    private function ownGroup(Group $group): void
    {
        abort_unless($group->course_id === CurrentUser::course()->id, 404);
    }
}
