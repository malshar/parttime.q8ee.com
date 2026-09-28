<?php

namespace App\Models;

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
    public const STATUS_ARCHIVED = 'archived';

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
}
