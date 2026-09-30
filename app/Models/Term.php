<?php

namespace App\Models;

use App\Support\ArabicDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Term extends Model
{
    use HasFactory;

    public const TYPES = ['first', 'second', 'summer'];

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = ['academic_year', 'type', 'teaching_starts_on', 'teaching_ends_on', 'status'];

    protected function casts(): array
    {
        return ['teaching_starts_on' => 'date', 'teaching_ends_on' => 'date'];
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(TermHoliday::class)->orderBy('date');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_OPEN);
    }

    public static function current(): ?self
    {
        return self::open()->orderByDesc('teaching_starts_on')->first();
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function label(): string
    {
        return __('app.terms.types.'.$this->type).' '.$this->academic_year;
    }

    /** Calendar months of the teaching window, indexed from 1 (spec §3 "Months of a term"). */
    public function months(): array
    {
        $out = [];
        $cursor = $this->teaching_starts_on->copy()->startOfMonth();
        $end = $this->teaching_ends_on->copy()->startOfMonth();
        for ($i = 1; $cursor->lte($end); $i++, $cursor->addMonthNoOverflow()) {
            $out[] = ['year' => $cursor->year, 'month' => $cursor->month, 'index' => $i, 'label' => ArabicDate::monthTitle($i, $cursor->month)];
        }

        return $out;
    }

    public function monthIndex(int $year, int $month): ?int
    {
        foreach ($this->months() as $m) {
            if ($m['year'] === $year && $m['month'] === $month) {
                return $m['index'];
            }
        }

        return null;
    }
}
