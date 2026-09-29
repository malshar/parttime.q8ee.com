<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectionMeeting extends Model
{
    use HasFactory;

    public const TYPES = ['theory', 'practical', 'field'];

    public const DAY_NAMES_AR = [0 => 'الأحد', 1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس'];

    protected $fillable = ['day_of_week', 'type', 'starts_at', 'ends_at', 'minutes', 'activity_ar', 'building', 'room'];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** "8:00-9:15" style: seconds stripped, no leading zero on the hour. */
    public function timeRange(): string
    {
        return $this->formatTime($this->starts_at).'-'.$this->formatTime($this->ends_at);
    }

    /** "08:00:00" or "08:00" -> "8:00"; "13:05:00" -> "13:05". */
    private function formatTime(string $time): string
    {
        [$hour, $minute] = explode(':', substr($time, 0, 5));

        return ((int) $hour).':'.$minute;
    }
}
