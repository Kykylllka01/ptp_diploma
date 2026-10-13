<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Question extends Model
{
    public const TYPES = [
        'single' => 'Один верный',
        'multiple' => 'Несколько верных',
        'tf' => 'Верно / неверно',
        'match' => 'Сопоставление',
        'sql' => 'SQL-запрос',
        'open' => 'Развёрнутый',
    ];

    public const DIFFICULTIES = ['easy' => 'Лёгкий', 'medium' => 'Средний', 'hard' => 'Сложный'];

    /** Типы, которые проверяются сразу, без агента и преподавателя. */
    public const CHOICE_TYPES = ['single', 'multiple', 'tf'];

    protected $fillable = [
        'code', 'course_id', 'material_id', 'generation_run_id', 'type', 'difficulty', 'topic', 'source',
        'points', 'status', 'flag_text', 'flag_level', 'text', 'context', 'options', 'explanation', 'reference',
        'criteria', 'sql_schema', 'tags', 'critic', 'fix_applied', 'quote', 'history',
    ];

    protected $casts = [
        'options' => 'array',
        'criteria' => 'array',
        'tags' => 'array',
        'critic' => 'array',
        'history' => 'array',
        'fix_applied' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function tests(): BelongsToMany
    {
        return $this->belongsToMany(Test::class)->withPivot('position');
    }

    public function isChoice(): bool
    {
        return in_array($this->type, self::CHOICE_TYPES, true);
    }

    /** Индексы верных вариантов. */
    public function correctIndexes(): array
    {
        $out = [];
        foreach ($this->options ?? [] as $i => $o) {
            if (! empty($o['correct'])) {
                $out[] = $i;
            }
        }

        return $out;
    }

    /**
     * Сопоставление: варианты хранятся строками «термин — определение» (верные пары)
     * и «Лишнее: определение». Студент видит термины и общий список определений
     * в перемешанном, но постоянном для вопроса порядке.
     *
     * @return array{terms: string[], definitions: string[], key: int[]}  key[i] — номер верного определения для термина i
     */
    public function matchParts(): array
    {
        $terms = [];
        $defs = [];
        foreach ($this->options ?? [] as $o) {
            $text = (string) ($o['text'] ?? '');
            if (preg_match('/^Лишнее:\s*(.+)$/su', $text, $m)) {
                $defs[] = trim($m[1]);
                continue;
            }
            [$term, $def] = array_pad(preg_split('/\s+[—–-]\s+/u', $text, 2), 2, '');
            $terms[] = trim($term);
            $defs[] = trim($def);
        }
        // Определения перемешиваем, но одинаково при каждом показе: порядок зависит от кода вопроса
        $order = array_keys($defs);
        mt_srand(crc32((string) $this->code));
        shuffle($order);
        mt_srand();
        $position = array_flip($order);
        $key = [];
        $pairIndex = 0;
        foreach ($this->options ?? [] as $o) {
            if (! preg_match('/^Лишнее:/u', (string) ($o['text'] ?? ''))) {
                $key[] = $position[$pairIndex];
            }
            $pairIndex++;
        }

        return ['terms' => $terms, 'definitions' => array_map(fn ($i) => $defs[$i], $order), 'key' => $key];
    }

    public function addHistory(string $event): void
    {
        $history = $this->history ?? [];
        $history[] = [now()->format('H:i'), $event];
        $this->history = $history;
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** Следующий свободный код вида Q-152. */
    /** Следующий код Q-NNN. Коды дополнены нулями, поэтому наибольший — самый длинный, а среди равных — последний по алфавиту. */
    public static function nextCode(): string
    {
        $last = static::query()->orderByRaw('LENGTH(code) DESC')->orderByDesc('code')->value('code');

        return sprintf('Q-%03d', (int) preg_replace('/\D/', '', (string) $last) + 1);
    }
}
