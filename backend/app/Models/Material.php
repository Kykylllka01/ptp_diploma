<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    public const KINDS = ['PDF', 'DOCX', 'PPTX', 'TXT', 'MP4', 'URL'];

    protected $fillable = [
        'course_id', 'title', 'kind', 'meta', 'file_path', 'url', 'size_bytes',
        'has_text', 'extracted_text', 'status',
    ];

    protected $casts = ['has_text' => 'boolean'];

    protected $hidden = ['extracted_text'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public static function kindFromName(string $name): string
    {
        $ext = strtoupper(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($ext, self::KINDS, true) ? $ext : 'FILE';
    }
}
