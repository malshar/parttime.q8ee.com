<?php

namespace App\Services\Attestations;

use App\Models\Application;
use App\Models\Attestation;
use App\Models\AttestationWeek;
use App\Models\AuditLog;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Admin-facing operations around AttestationGenerator, each audited (spec §5, §7). */
final class AttestationService
{
    public function __construct(private AttestationGenerator $generator) {}

    /** Approved applications with at least one assignment, by instructor name. */
    public function listed(Term $term): Collection
    {
        return $term->applications()->where('status', Application::STATUS_APPROVED)->has('assignments')->with('instructor')->get()
            ->sortBy(fn ($a) => $a->instructor->full_name)->values();
    }

    /**
     * Every attestation of the term for that month, whether or not its application is still listed (spec §5.1).
     *
     * @return Builder<Attestation>
     */
    public function monthAttestations(Term $term, int $year, int $month)
    {
        return Attestation::whereHas('application', fn ($q) => $q->where('term_id', $term->id))
            ->where(['year' => $year, 'month' => $month]);
    }

    /** @return array{year:int, month:int, index:int, label:string}|null */
    public function resolveMonth(Term $term, ?int $index): ?array
    {
        $months = $term->months();
        if ($months === []) {
            return null;
        }
        if ($index !== null) {
            foreach ($months as $m) {
                if ($m['index'] === $index) {
                    return $m;
                }
            }

            return null;
        }
        $today = Carbon::today();
        foreach ($months as $m) {
            if ($m['year'] === $today->year && $m['month'] === $today->month) {
                return $m;
            }
        }

        return $months[0];
    }

    public function generateMissing(Term $term, int $year, int $month, User $by): int
    {
        if (! $term->isOpen()) {
            throw new DomainException(__('app.attestations.term_closed'));
        }
        $n = 0;
        foreach ($this->listed($term) as $application) {
            if ($application->attestations()->where(['year' => $year, 'month' => $month])->exists()) {
                continue;
            }
            $attestation = $this->generator->generate($application, $year, $month, $by);
            AuditLog::record($by->id, 'generate_attestation', $attestation, null, 'new');
            $n++;
        }

        return $n;
    }

    public function regenerate(Attestation $attestation, User $by): Attestation
    {
        $this->assertEditable($attestation);
        $fresh = $this->generator->generate($attestation->application, (int) $attestation->year, (int) $attestation->month, $by);
        AuditLog::record($by->id, 'generate_attestation', $fresh, null, 'regenerated');

        return $fresh;
    }

    /**
     * @param  array<int, array<string, mixed>>  $weeks  week id => editable columns (minutes already converted)
     * @return list<string> changed column names, sorted
     */
    public function update(Attestation $attestation, array $weeks, User $by): array
    {
        $this->assertEditable($attestation);
        $changed = [];
        foreach ($attestation->weeks as $week) {
            if (! isset($weeks[$week->id])) {
                continue;
            }
            $data = array_intersect_key($weeks[$week->id], array_flip(AttestationWeek::EDITABLE));
            foreach ($data as $col => $value) {
                if ((string) $week->$col !== (string) $value) {
                    $changed[] = $col;
                }
            }
            $week->fill($data)->save();
        }
        $changed = array_values(array_unique($changed));
        sort($changed);
        AuditLog::record($by->id, 'update_attestation', $attestation, null, implode(',', $changed));

        return $changed;
    }

    public function unlock(Attestation $attestation, User $by): void
    {
        if (! $attestation->application->term->isOpen()) {
            throw new DomainException(__('app.attestations.term_closed'));
        }
        if (! $attestation->isExported()) {
            throw new DomainException(__('app.attestations.not_exported'));
        }
        $attestation->update(['status' => Attestation::STATUS_GENERATED]);
        AuditLog::record($by->id, 'unlock_attestation', $attestation);
    }

    /** Called by every download; $format is docx | pdf | combined_pdf. */
    public function markExported(Attestation $attestation, User $by, string $format): void
    {
        $attestation->update(['status' => Attestation::STATUS_EXPORTED, 'exported_at' => now()]);
        AuditLog::record($by->id, 'export_attestation', $attestation, null, $format);
    }

    private function assertEditable(Attestation $attestation): void
    {
        if (! $attestation->application->term->isOpen()) {
            throw new DomainException(__('app.attestations.term_closed'));
        }
        if ($attestation->isExported()) {
            throw new DomainException(__('app.attestations.locked'));
        }
    }
}
