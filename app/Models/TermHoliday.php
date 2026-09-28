<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class TermHoliday extends Model
{
    protected $fillable = ['date', 'name'];

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * A Carbon instance that also compares and stringifies as plain Y-m-d
     * (e.g. loose-equality checks and DB storage), unlike the default
     * 'date' cast which stringifies with a time component.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null
                ? null
                : Carbon::parse($value)->startOfDay()->settings(['toStringFormat' => 'Y-m-d']),
            set: fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value,
        );
    }
}
