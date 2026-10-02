<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;

class Application extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_COMPLETE = 'complete';

    /** Statuses in which the admin may still act on documents. */
    public const REVIEWABLE_STATUSES = [self::STATUS_UNDER_REVIEW, self::STATUS_INCOMPLETE, self::STATUS_COMPLETE];

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    /** Every status in pipeline order (filters, dashboard counts). */
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW, self::STATUS_INCOMPLETE,
        self::STATUS_COMPLETE, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_WITHDRAWN];

    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_INCOMPLETE];

    public const FINAL_STATUSES = [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_WITHDRAWN];

    /** Not final and not a draft: the term cannot close while any application is here. */
    public const UNFINISHED_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW, self::STATUS_INCOMPLETE, self::STATUS_COMPLETE];

    protected $fillable = ['term_id', 'instructor_id', 'status', 'submitted_at', 'reviewed_at', 'complete_at', 'decided_at',
        'assignment_decision_number', 'assignment_decision_date', 'weekly_minutes',
        'admin_note', 'rejection_reason', 'committee_outcome', 'committee_met_on', 'committee_reference', 'committee_note'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'complete_at' => 'datetime', 'decided_at' => 'datetime',
            'assignment_decision_date' => 'date', 'committee_met_on' => 'date'];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function sections(): HasManyThrough
    {
        return $this->hasManyThrough(Section::class, Assignment::class, 'application_id', 'id', 'id', 'section_id');
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(Attestation::class);
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(ChecklistRenewal::class);
    }

    public function exemptions(): HasMany
    {
        return $this->hasMany(ChecklistExemption::class);
    }

    public function weeklyHoursLabel(): string
    {
        return Section::hoursFromMinutes((int) $this->weekly_minutes);
    }

    /** Latest version per checklist item, keyed by item code. */
    public function latestDocuments(): Collection
    {
        return $this->documents()->with('checklistItem')->orderByDesc('version')->get()
            ->unique('checklist_item_id')->keyBy(fn (Document $d) => $d->checklistItem->code);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true) && $this->term->isOpen();
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    /** Spec 5b §5: stage-2 documents may be uploaded and reviewed while approved on an open term. */
    public function acceptsStageTwoUploads(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->term->isOpen();
    }
}
