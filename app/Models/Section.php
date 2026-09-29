<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Section extends Model
{
    use HasFactory;

    protected $fillable = ['term_id', 'course_code', 'course_name_ar', 'section_number', 'reference_number',
        'seats_capacity', 'seats_registered', 'seats_remaining', 'scheduled_instructor', 'imported_at', 'missing_since_import'];

    protected function casts(): array
    {
        return ['imported_at' => 'datetime', 'missing_since_import' => 'boolean'];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(SectionMeeting::class)->orderBy('day_of_week')->orderBy('starts_at');
    }

    public function assignment(): HasOne
    {
        return $this->hasOne(Assignment::class);
    }

    /** @return array{theory:int, practical:int, field:int} */
    public function weeklyMinutesByType(): array
    {
        $out = ['theory' => 0, 'practical' => 0, 'field' => 0];
        foreach ($this->meetings as $m) {
            $out[$m->type] += $m->minutes;
        }

        return $out;
    }

    public function weeklyMinutes(): int
    {
        return array_sum($this->weeklyMinutesByType());
    }

    public static function hoursFromMinutes(int $minutes): string
    {
        return number_format($minutes / 60, 1, '.', '');
    }

    /** Groups meetings by activity + time: "محاضرة: الأحد/الثلاثاء 8:00-9:15؛ مختبر: الاثنين 9:30-11:10" */
    public function meetingSummary(): string
    {
        $groups = [];
        foreach ($this->meetings as $m) {
            $key = $m->activity_ar.'|'.$m->timeRange();
            $groups[$key]['activity'] = $m->activity_ar;
            $groups[$key]['time'] = $m->timeRange();
            $groups[$key]['days'][] = SectionMeeting::DAY_NAMES_AR[$m->day_of_week];
        }

        return implode('؛ ', array_map(fn ($g) => $g['activity'].': '.implode('/', $g['days']).' '.$g['time'], $groups));
    }

    public function label(): string
    {
        return $this->course_code.' / '.$this->section_number.' — '.$this->course_name_ar;
    }
}
