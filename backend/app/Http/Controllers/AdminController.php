<?php

namespace App\Http\Controllers;

use App\Services\FrontendData;

/** Админ-панель: приглашения преподавателей и их аккаунты. Только для роли admin. */
class AdminController extends Controller
{
    public function index(FrontendData $data)
    {
        return view('admin.index', [
            'title' => 'Администрирование',
            'active' => 'admin',
            'script' => 'admin',
            'appData' => $data->admin(),
        ]);
    }
}
