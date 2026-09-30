<?php

namespace App\Models;

use App\Support\ArabicDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One (خ-3) form: an application's month, with its week rows (spec §3). */
class Attestation extends Model
{
    use HasFactory;

    public const STATUS_GENERATED = 'generated';

    public const STATUS_EXPORTED = 'exported';

    protected $fillable = ['application_id', 'year', 'month', 'status', 'generated_at', 'generated_by', 'exported_at', 'admin_note'];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime', 'exported_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(AttestationWeek::class)->orderBy('week_number');
    }

    public function isExported(): bool
    {
        return $this->status === self::STATUS_EXPORTED;
    }

    public function monthIndex(): ?int
    {
        return $this->application->term->monthIndex((int) $this->year, (int) $this->month);
    }

    public function monthTitle(): string
    {
        return ArabicDate::monthTitle($this->monthIndex() ?? 1, (int) $this->month);
    }

    /** @return array{student_count:int, theory_minutes:int, practical_minutes:int, field_minutes:int, total_minutes:int} */
    public function totals(): array
    {
        $t = ['student_count' => 0, 'theory_minutes' => 0, 'practical_minutes' => 0, 'field_minutes' => 0];
        foreach ($this->weeks as $w) {
            foreach (array_keys($t) as $k) {
                $t[$k] += (int) $w->$k;
            }
        }
        $t['total_minutes'] = $t['theory_minutes'] + $t['practical_minutes'] + $t['field_minutes'];

        return $t;
    }
}
