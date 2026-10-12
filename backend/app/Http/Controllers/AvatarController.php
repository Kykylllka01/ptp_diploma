<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'avatar' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120|dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000',
        ], [
            'avatar.required' => 'Выберите картинку',
            'avatar.mimes' => 'Подойдёт картинка JPG, PNG или WebP',
            'avatar.max' => 'Картинка больше 5 МБ — выберите поменьше',
            'avatar.dimensions' => 'Картинка должна быть от 64 до 4000 точек по каждой стороне',
        ]);

        // 4000×4000 в памяти GD — около 64 МБ: PNG «бомба» в 5 МБ не уронит запрос
        $file = $request->file('avatar')->getRealPath();
        $src = @imagecreatefromstring((string) file_get_contents($file));
        if (! $src) {
            return back()->withErrors(['avatar' => 'Не удалось прочитать картинку — попробуйте другую']);
        }
        $src = $this->upright($src, $file);
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
        $ok = imagewebp($dst, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        $user = CurrentUser::user();
        // Новое имя файла при каждой замене — браузер не покажет старую картинку из кэша
        $path = 'avatars/'.$user->id.'-'.Str::lower(Str::random(10)).'.webp';
        // Диск local не бросает исключений: без проверки профиль сослался бы на пустой файл
        if (! $ok || ! $bytes || ! Storage::put($path, $bytes)) {
            return back()->withErrors(['avatar' => 'Не удалось сохранить картинку — попробуйте ещё раз']);
        }
        // Две загрузки подряд: старый файл удаляет та, что последней записала путь
        $old = DB::transaction(function () use ($user, $path) {
            $old = User::whereKey($user->id)->lockForUpdate()->value('avatar_path');
            $user->update(['avatar_path' => $path]);

            return $old;
        });
        if ($old) {
            Storage::delete($old);
        }

        return back()->with('status', 'Аватар обновлён');
    }

    /** Фото с телефона хранит поворот в EXIF, а GD его не применяет — без этого портрет ляжет на бок. */
    private function upright(\GdImage $img, string $file): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($file);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if (! $angle) {
            return $img;
        }
        $rotated = imagerotate($img, $angle, 0);
        if (! $rotated) {
            return $img;
        }
        imagedestroy($img);

        return $rotated;
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
