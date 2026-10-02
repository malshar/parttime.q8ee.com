<?php

namespace App\Services\Attestations;

use App\Models\Application;
use App\Models\Attestation;
use App\Models\AttestationWeek;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Support\ArabicDate;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Spec §4: application + month → stored week rows. Pure computation in weeks(); generate() persists. */
final class AttestationGenerator
{
    public function generate(Application $application, int $year, int $month, ?User $by = null): Attestation
    {
        $term = $application->term;
        if ($application->status !== Application::STATUS_APPROVED) {
            throw new DomainException(__('app.attestations.not_approved'));
        }
        if (! $term->isOpen()) {
            throw new DomainException(__('app.attestations.term_closed'));
        }
        if ($term->monthIndex($year, $month) === null) {
            throw new DomainException(__('app.attestations.month_outside_term'));
        }
        $sections = $application->sections()->with('meetings')->get();
        if ($sections->isEmpty()) {
            throw new DomainException(__('app.attestations.no_assignments'));
        }
        $existing = Attestation::where(['application_id' => $application->id, 'year' => $year, 'month' => $month])->first();
        if ($existing?->isExported()) {
            throw new DomainException(__('app.attestations.locked'));
        }

        $rows = $this->weeks($term, $sections, $year, $month);

        return DB::transaction(function () use ($application, $year, $month, $by, $existing, $rows) {
            $attestation = $existing ?? new Attestation(['application_id' => $application->id, 'year' => $year, 'month' => $month]);
            $attestation->fill(['status' => Attestation::STATUS_GENERATED, 'generated_at' => now(), 'generated_by' => $by?->id, 'exported_at' => null])->save();
            $attestation->weeks()->delete();
            foreach ($rows as $row) {
                foreach (AttestationWeek::EDITABLE as $col) {
                    $row['generated_'.$col] = $row[$col];
                }
                $attestation->weeks()->create($row);
            }

            return $attestation->refresh()->load('weeks');
        });
    }

    /**
     * @param  Collection<int, Section>  $sections  with meetings loaded
     * @return list<array<string, mixed>>
     */
    public function weeks(Term $term, Collection $sections, int $year, int $month): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();
        $from = $term->teaching_starts_on->greaterThan($monthStart) ? $term->teaching_starts_on->copy()->startOfDay() : $monthStart;
        $to = $term->teaching_ends_on->lessThan($monthEnd) ? $term->teaching_ends_on->copy()->startOfDay() : $monthEnd;
        $holidays = $term->holidays->keyBy(fn ($h) => $h->date->toDateString());

        $rows = [];
        $n = 0;
        $unknownReported = false;
        for ($sunday = $from->copy()->subDays($from->dayOfWeek); $sunday->lte($to); $sunday->addWeek()) {
            $days = [];
            $working = [];
            $weekHolidays = [];
            for ($d = 0; $d < 5; $d++) {
                $day = $sunday->copy()->addDays($d);
                if ($day->lt($from) || $day->gt($to)) {
                    continue;
                }
                $days[] = $day;
                if (isset($holidays[$day->toDateString()])) {
                    $weekHolidays[] = $holidays[$day->toDateString()];
                } else {
                    $working[] = $day;
                }
            }
            if ($days === []) {
                continue;
            }
            $n++;
            $weekdays = array_map(fn (Carbon $d) => $d->dayOfWeek, $working);
            $weekSections = $sections->filter(fn ($s) => $s->meetings->contains(fn ($m) => in_array((int) $m->day_of_week, $weekdays, true)))->values();
            $minutes = ['theory' => 0, 'practical' => 0, 'field' => 0];
            foreach ($weekSections as $s) {
                foreach ($s->meetings as $m) {
                    if (in_array((int) $m->day_of_week, $weekdays, true)) {
                        $type = $m->type;
                        if (! isset($minutes[$type])) {   // unexpected import value: count it as theory, never 500
                            if (! $unknownReported) {
                                report(new \UnexpectedValueException("Unknown meeting type [{$type}] on section {$s->id}; counted as theory."));
                                $unknownReported = true;
                            }
                            $type = 'theory';
                        }
                        $minutes[$type] += (int) $m->minutes;
                    }
                }
            }
            $rows[] = [
                'week_number' => $n,
                'date_from' => $days[0]->toDateString(),
                'date_to' => end($days)->toDateString(),
                'working_days' => array_map(fn (Carbon $d) => $d->toDateString(), $working),
                'courses_text' => $weekSections->unique('course_code')->sortBy('course_code')->map(fn ($s) => $s->course_name_ar.' '.$s->course_code)->implode("\n"),
                'student_count' => (int) $weekSections->unique('id')->sum(fn ($s) => (int) $s->seats_registered),
                'theory_minutes' => $minutes['theory'],
                'practical_minutes' => $minutes['practical'],
                'field_minutes' => $minutes['field'],
                'note_ar' => $this->note($working, $weekHolidays, $this->holdsLastTeachingDay($term, $sunday, $monthStart, $monthEnd), $term),
            ];
        }

        return $rows;
    }

    /**
     * The block's whole Sunday..Saturday range, so a Friday or Saturday last day is still noted: in its own month,
     * or in the previous month when it falls on the 1st/2nd and that month holds the block's working days.
     */
    private function holdsLastTeachingDay(Term $term, Carbon $sunday, Carbon $monthStart, Carbon $monthEnd): bool
    {
        $end = $term->teaching_ends_on->copy()->startOfDay();
        if (! $end->between($sunday, $sunday->copy()->addDays(6))) {
            return false;
        }
        if ($end->between($monthStart, $monthEnd)) {
            return true;
        }

        // A Friday/Saturday end on the 1st/2nd of the next month: this block's Thursday is still in this month.
        return $end->gt($monthEnd) && $sunday->copy()->addDays(4)->between($monthStart, $monthEnd);
    }

    /** @param  list<Carbon>  $working  @param  list<\App\Models\TermHoliday>  $holidays */
    private function note(array $working, array $holidays, bool $lastTeachingDay, Term $term): string
    {
        $lines = [];
        if (count($working) === 5) {
            $lines[] = 'أسبوع كامل';
        } elseif ($working !== []) {
            $lines[] = implode('- ', array_map(fn (Carbon $d) => ArabicDate::dayName($d), $working)).' (فقط)';
        }
        foreach ($holidays as $h) {
            $lines[] = 'يوم '.ArabicDate::dayName($h->date).' '.ArabicDate::long($h->date).' '.$h->name;
        }
        if ($lastTeachingDay) {
            $lines[] = 'آخر يوم دراسي '.ArabicDate::long($term->teaching_ends_on);
        }

        return implode("\n", $lines);
    }
}
