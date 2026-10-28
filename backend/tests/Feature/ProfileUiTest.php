<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Material;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesCourseData;
use Tests\TestCase;

/** Аватары в личном кабинете и карточка материала на «Обзоре». */
class ProfileUiTest extends TestCase
{
    use MakesCourseData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedAccountsAndCourse();
    }

    public function test_avatar_is_reencoded_to_square_webp_and_served_to_signed_in_users(): void
    {
        Storage::fake();
        $student = $this->student();
        $this->actingAs($student)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 600, 400)])
            ->assertRedirect()->assertSessionHasNoErrors();

        $path = $student->fresh()->avatar_path;
        $this->assertStringEndsWith('.webp', $path);
        [$w, $h] = getimagesizefromstring(Storage::get($path));
        $this->assertSame([256, 256], [$w, $h]);

        $url = $student->fresh()->avatarUrl();
        $this->actingAs($this->teacher())->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertSame($url, $this->actingAs($student)->get('/student/tests')->viewData('appData')['student']['avatar']);

        // Замена удаляет старый файл; удаление — убирает аватар
        $this->actingAs($student)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('new.jpg', 300, 300)]);
        Storage::assertMissing($path);
        $this->delete('/profile/avatar')->assertRedirect();
        $this->assertNull($student->fresh()->avatar_path);

        auth()->logout();
        $this->get($url)->assertRedirect('/login');
    }

    public function test_not_an_image_is_rejected(): void
    {
        Storage::fake();
        $this->actingAs($this->teacher())
            ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->create('virus.php', 10, 'image/png')])
            ->assertSessionHasErrors('avatar');
        $this->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('tiny.png', 10, 10)])->assertSessionHasErrors('avatar');
        $this->assertNull($this->teacher()->avatar_path);
    }

    public function test_material_card_shows_text_questions_and_critic_stats(): void
    {
        $m = Material::create(['course_id' => Course::firstOrFail()->id, 'title' => 'Нормализация', 'kind' => 'DOCX', 'status' => 'ready',
            'has_text' => true, 'extracted_text' => str_repeat('Нормальная форма. ', 500)]);
        $this->singleQuestion('Q-1', 0, ['material_id' => $m->id, 'status' => 'pending', 'critic' => ['score' => 9, 'checks' => []]]);
        $this->singleQuestion('Q-2', 0, ['material_id' => $m->id, 'status' => 'pending', 'critic' => ['score' => 5, 'checks' => []], 'flag_text' => 'Спорно', 'flag_level' => 'amber']);

        $d = $this->actingAs($this->teacher())->getJson('/api/materials/'.$m->id)->assertOk()->json();
        $this->assertSame(9000, $d['chars']);
        $this->assertSame(2, $d['fragments']);
        $this->assertArrayNotHasKey('excerpt', $d);   // вместо куска текста — сам файл
        $this->assertNull($d['fileUrl']);               // у этого материала файла нет
        $this->assertSame(['total' => 2, 'pending' => 2, 'approved' => 0, 'rejected' => 0, 'clean' => 1, 'flagged' => 1, 'byType' => ['single' => 2], 'removable' => 2], $d['questions']);

        $this->actingAs($this->student())->getJson('/api/materials/'.$m->id)->assertForbidden();
    }

    public function test_material_file_opens_inline_or_downloads_with_safe_headers(): void
    {
        Storage::fake('local');
        $course = Course::firstOrFail()->id;
        Storage::put('materials/a.txt', '<script>alert(1)</script>');
        Storage::put('materials/b.docx', 'PK');
        $txt = Material::create(['course_id' => $course, 'title' => 'Лекция: 1/2', 'kind' => 'TXT', 'status' => 'ready', 'file_path' => 'materials/a.txt']);
        $docx = Material::create(['course_id' => $course, 'title' => 'Нормализация', 'kind' => 'DOCX', 'status' => 'ready', 'file_path' => 'materials/b.docx']);
        $link = Material::create(['course_id' => $course, 'title' => 'Сайт', 'kind' => 'URL', 'status' => 'ready', 'url' => 'https://example.org/lecture']);

        $this->actingAs($this->teacher());
        $this->assertStringEndsWith('/teacher/materials/'.$txt->id.'/file', $this->getJson('/api/materials/'.$txt->id)->json('fileUrl'));

        // Текст — во вкладке, но как простой текст в песочнице: HTML внутри не исполнится
        $r = $this->get('/teacher/materials/'.$txt->id.'/file')->assertOk();
        $this->assertStringStartsWith('text/plain', $r->headers->get('Content-Type'));
        $this->assertSame('sandbox', $r->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('inline', $r->headers->get('Content-Disposition'));

        $this->assertStringContainsString('attachment', $this->get('/teacher/materials/'.$docx->id.'/file')->assertOk()->headers->get('Content-Disposition'));
        $this->get('/teacher/materials/'.$link->id.'/file')->assertRedirect('https://example.org/lecture');

        Storage::delete('materials/b.docx');
        $this->get('/teacher/materials/'.$docx->id.'/file')->assertNotFound();
        $this->actingAs($this->student())->get('/teacher/materials/'.$txt->id.'/file')->assertRedirect();
    }
}
