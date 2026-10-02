<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = ['application_id', 'checklist_item_id', 'path', 'original_name', 'mime', 'size',
        'status', 'rejection_reason', 'reviewed_by', 'reviewed_at', 'version', 'part', 'notified_at'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'notified_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Every file of this document's version (part 1 is the head that carries the review state). */
    public function parts(): HasMany
    {
        return $this->hasMany(Document::class, 'application_id', 'application_id')
            ->where('checklist_item_id', $this->checklist_item_id)->where('version', $this->version)->orderBy('part');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
