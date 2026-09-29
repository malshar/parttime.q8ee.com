<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_log';

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'details', 'ip'];

    public static function record(?int $userId, string $action, ?Model $subject = null, ?string $ip = null, ?string $details = null): self
    {
        return self::create([
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'details' => $details,
            'ip' => $ip ?? request()?->ip(),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
