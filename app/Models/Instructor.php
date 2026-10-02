<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Instructor extends Model
{
    use HasFactory;

    public const SECTORS = ['government', 'private'];

    public const DEGREES = ['bachelor', 'master', 'phd'];

    protected $fillable = [
        'full_name', 'civil_id', 'civil_id_expires_on', 'nationality', 'mobile', 'work_phone', 'home_phone',
        'employer', 'employer_sector', 'job_title', 'highest_degree', 'degree_title', 'degree_country',
        'degree_obtained_on', 'experience_years', 'bank_name', 'bank_branch', 'iban', 'basic_salary', 'total_salary',
    ];

    protected $hidden = ['civil_id', 'civil_id_hash', 'iban', 'basic_salary', 'total_salary'];

    protected function casts(): array
    {
        return [
            'civil_id' => 'encrypted', 'iban' => 'encrypted', 'basic_salary' => 'encrypted', 'total_salary' => 'encrypted',
            'civil_id_expires_on' => 'date', 'degree_obtained_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Instructor $i) {
            if ($i->isDirty('civil_id')) {
                $i->civil_id_hash = self::hashCivilId($i->civil_id);
            }
        });
    }

    public static function hashCivilId(string $civilId): string
    {
        return hash_hmac('sha256', $civilId, config('app.key'));
    }

    public static function findByCivilId(string $civilId): ?self
    {
        return self::where('civil_id_hash', self::hashCivilId($civilId))->first();
    }

    /** The nationality for display: the country name for a 2-letter code, or the stored legacy text as-is. */
    public function nationalityLabel(): string
    {
        return strlen($this->nationality) === 2 ? __('app.countries.'.$this->nationality) : (string) $this->nationality;
    }

    public function maskedCivilId(): string
    {
        return mask_middle($this->civil_id);
    }

    public function maskedIban(): string
    {
        return mask_middle($this->iban);
    }

    public function isForeignDegree(): bool
    {
        return strtoupper($this->degree_country) !== 'KW';
    }

    public function isPrivateSector(): bool
    {
        return $this->employer_sector === 'private';
    }

    public function isBachelorOnly(): bool
    {
        return $this->highest_degree === 'bachelor';
    }

    public function isComplete(): bool
    {
        return $this->exists;
    }

    public function civilIdExpired(): bool
    {
        return $this->civil_id_expires_on->isPast();
    }

    /** Profile is frozen while an application on an open term is submitted, under review, complete or approved. */
    public function hasLockedApplication(): bool
    {
        return $this->applications()
            ->whereIn('status', [Application::STATUS_SUBMITTED, Application::STATUS_UNDER_REVIEW, Application::STATUS_COMPLETE, Application::STATUS_APPROVED])
            ->whereHas('term', fn ($q) => $q->open())
            ->exists();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
