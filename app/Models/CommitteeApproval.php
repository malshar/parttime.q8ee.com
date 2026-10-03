<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The committee's decision for one instructor and one academic year (spec M6 §3.1). Never updated. */
class CommitteeApproval extends Model
{
    use HasFactory;

    public const KIND_INITIAL = 'initial';

    public const KIND_RENEWAL = 'renewal';

    public const OUTCOME_APPROVED = 'approved';

    public const OUTCOME_NOT_RENEWED = 'not_renewed';

    protected $fillable = ['instructor_id', 'academic_year', 'kind', 'outcome', 'committee_met_on', 'committee_reference', 'note', 'decided_by'];

    protected function casts(): array
    {
        return ['committee_met_on' => 'date'];
    }

    public function isApproved(): bool
    {
        return $this->outcome === self::OUTCOME_APPROVED;
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'approval_id');
    }

    /** "2026-2027" → "2025-2026". */
    public static function previousYear(string $academicYear): string
    {
        $start = (int) substr($academicYear, 0, 4);

        return ($start - 1).'-'.$start;
    }

    /** "2026-2027" → "2027-2028". */
    public static function nextYear(string $academicYear): string
    {
        $start = (int) substr($academicYear, 0, 4);

        return ($start + 1).'-'.($start + 2);
    }
}
