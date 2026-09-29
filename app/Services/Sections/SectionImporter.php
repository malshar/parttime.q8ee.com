<?php

namespace App\Services\Sections;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SectionImporter
{
    public function plan(Term $term, ParsedTimetable $timetable): ImportPlan
    {
        $plan = new ImportPlan;
        $existing = $term->sections()->with(['meetings', 'assignment'])->get()
            ->keyBy(fn (Section $s) => $s->course_code.'|'.$s->section_number);

        foreach ($timetable->sections as $key => $parsed) {
            $current = $existing->get($key);
            if (! $current) {
                $plan->insert[] = $key;
            } elseif ($this->fingerprint($current) === $this->fingerprintParsed($parsed)) {
                $plan->unchanged[] = $key;
            } else {
                $plan->update[] = $key;
            }
        }
        foreach ($existing as $key => $section) {
            if (isset($timetable->sections[$key])) {
                continue;
            }
            $section->assignment ? $plan->flag[] = $key : $plan->delete[] = $key;
        }

        return $plan;
    }

    public function apply(Term $term, ParsedTimetable $timetable, User $admin): ImportPlan
    {
        if (! $term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if ($timetable->hasErrors()) {
            throw new \DomainException(__('app.sections.import_has_errors'));
        }

        return DB::transaction(function () use ($term, $timetable, $admin) {
            $plan = $this->plan($term, $timetable);
            $touchedApplications = [];

            foreach ($timetable->sections as $key => $parsed) {
                if (in_array($key, $plan->unchanged, true)) {
                    $term->sections()->where('course_code', $parsed->courseCode)->where('section_number', $parsed->sectionNumber)
                        ->update(['imported_at' => now(), 'missing_since_import' => false]);
                    continue;
                }
                $section = $term->sections()->updateOrCreate(
                    ['course_code' => $parsed->courseCode, 'section_number' => $parsed->sectionNumber],
                    [
                        'course_name_ar' => $parsed->courseName, 'reference_number' => $parsed->referenceNumber,
                        'seats_capacity' => $parsed->seatsCapacity, 'seats_registered' => $parsed->seatsRegistered, 'seats_remaining' => $parsed->seatsRemaining,
                        'scheduled_instructor' => $parsed->scheduledInstructor, 'imported_at' => now(), 'missing_since_import' => false,
                    ],
                );
                $section->meetings()->delete();
                foreach ($parsed->meetings as $m) {
                    $section->meetings()->create([
                        'day_of_week' => $m->dayOfWeek, 'type' => $m->type, 'starts_at' => $m->startsAt, 'ends_at' => $m->endsAt,
                        'minutes' => $m->minutes, 'activity_ar' => $m->activityAr, 'building' => $m->building, 'room' => $m->room,
                    ]);
                }
                if ($section->assignment) {
                    $touchedApplications[$section->assignment->application_id] = true;
                }
            }

            foreach ($plan->delete as $key) {
                [$code, $num] = explode('|', $key, 2);
                $term->sections()->where('course_code', $code)->where('section_number', $num)->delete();
            }
            foreach ($plan->flag as $key) {
                [$code, $num] = explode('|', $key, 2);
                $term->sections()->where('course_code', $code)->where('section_number', $num)->update(['missing_since_import' => true]);
            }
            foreach (array_keys($touchedApplications) as $appId) {
                $this->recompute(Application::findOrFail($appId));
            }

            $counts = $plan->counts();
            $details = "inserted {$counts['insert']}, updated {$counts['update']}, unchanged {$counts['unchanged']}, deleted {$counts['delete']}, flagged {$counts['flag']}";
            AuditLog::record($admin->id, 'import_sections:'.$plan->auditSuffix(), $term, null, $details);

            return $plan;
        });
    }

    /** Replaced by AssignmentService::recomputeHours() in Task 11. */
    private function recompute(Application $application): void
    {
        $minutes = 0;
        foreach ($application->sections()->with('meetings')->get() as $s) {
            $minutes += $s->weeklyMinutes();
        }
        $application->update(['weekly_minutes' => $minutes, 'weekly_hours_decimal' => round($minutes / 60, 1)]);
    }

    private function fingerprint(Section $s): string
    {
        $meetings = $s->meetings->map(fn ($m) => "{$m->day_of_week}|{$m->type}|".substr($m->starts_at, 0, 5).'|'.substr($m->ends_at, 0, 5)."|{$m->activity_ar}|{$m->building}|{$m->room}")->sort()->values()->all();

        return md5(json_encode([$s->course_name_ar, $s->reference_number, $s->scheduled_instructor, $s->seats_capacity, $s->seats_registered, $s->seats_remaining, $meetings]));
    }

    private function fingerprintParsed(ParsedSection $p): string
    {
        $meetings = array_map(fn ($m) => "{$m->dayOfWeek}|{$m->type}|{$m->startsAt}|{$m->endsAt}|{$m->activityAr}|{$m->building}|{$m->room}", $p->meetings);
        sort($meetings);

        return md5(json_encode([$p->courseName, $p->referenceNumber, $p->scheduledInstructor, $p->seatsCapacity, $p->seatsRegistered, $p->seatsRemaining, $meetings]));
    }
}
