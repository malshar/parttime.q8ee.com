<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An admin's "request a new copy" for an item that would otherwise be on file (spec §4.4). */
class ChecklistRenewal extends Model
{
    use HasFactory;

    protected $fillable = ['application_id', 'checklist_item_id', 'reason', 'requested_by', 'requested_at', 'notified_at'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'notified_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
