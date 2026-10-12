<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Аватар в личном кабинете. Картинка не сохраняется как прислали: GD перерисовывает её в квадрат 256×256 WebP —
 * так отбрасываются EXIF (геометка с телефона), «полиглоты» с вложенным кодом и гигантские файлы.
 */
class AvatarController extends Controller
{
    private const SIZE = 256;

    public function store(Request $request)
    {
        $request->validate([
            'avatar' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120|dimensions:min_width=64,min_height=64,max_width=6000,max_height=6000',
        ], [
            'avatar.required' => 'Выберите картинку',
            'avatar.mimes' => 'Подойдёт картинка JPG, PNG или WebP',
            'avatar.max' => 'Картинка больше 5 МБ — выберите поменьше',
            'avatar.dimensions' => 'Картинка должна быть от 64 до 6000 точек по каждой стороне',
        ]);

        $src = @imagecreatefromstring((string) file_get_contents($request->file('avatar')->getRealPath()));
        if (! $src) {
            return back()->withErrors(['avatar' => 'Не удалось прочитать картинку — попробуйте другую']);
        }
        // Центральный квадрат, уменьшенный до 256×256
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::SIZE, self::SIZE, $side, $side);
        ob_start();
        imagewebp($dst, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        $user = CurrentUser::user();
        // Новое имя файла при каждой замене — браузер не покажет старую картинку из кэша
        $path = 'avatars/'.$user->id.'-'.Str::lower(Str::random(10)).'.webp';
        Storage::put($path, $bytes);
        $old = $user->avatar_path;
        $user->update(['avatar_path' => $path]);
        if ($old) {
            Storage::delete($old);
        }

        return back()->with('status', 'Аватар обновлён');
    }

    public function destroy()
    {
        $user = CurrentUser::user();
        if ($user->avatar_path) {
            Storage::delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return back()->with('status', 'Аватар удалён');
    }

    /** Картинку видят только вошедшие пользователи; имя файла меняется при замене, поэтому кэшируется надолго. */
    public function show(User $user)
    {
        abort_unless($user->avatar_path && Storage::exists($user->avatar_path), 404);

        return response(Storage::get($user->avatar_path), 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
