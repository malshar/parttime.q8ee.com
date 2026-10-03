<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Attestation;
use App\Models\Term;
use Illuminate\Support\Collection;

/** Spec M6 §7: what the department processes at the end of a term. */
class TermClosingReport
{
    public function __construct(private ApplicationWorkflow $workflow) {}

    public function nextTerm(Term $term): ?Term
    {
        return Term::where('teaching_starts_on', '>', $term->teaching_starts_on)->orderBy('teaching_starts_on')->first();
    }

    public function rows(Term $term): Collection
    {
        $next = $this->nextTerm($term);
        $months = $term->months();
        $applications = $term->applications()->where('status', Application::STATUS_APPROVED)->with('instructor')->get()
            ->sortBy(fn ($a) => $a->instructor->full_name)->values();
        $attestations = Attestation::whereIn('application_id', $applications->pluck('id'))->get()->groupBy('application_id');
        $continuations = $next ? Application::where('term_id', $next->id)->whereIn('instructor_id', $applications->pluck('instructor_id'))->get()->keyBy('instructor_id') : collect();

        return $applications->map(function (Application $a) use ($months, $attestations, $continuations, $next) {
            $own = $attestations->get($a->id, collect());
            $cont = $continuations->get($a->instructor_id);

            return [
                'application' => $a,
                'months' => array_map(fn ($m) => ['label' => $m['label'], 'status' => $own->first(fn ($t) => (int) $t->year === $m['year'] && (int) $t->month === $m['month'])?->status ?? 'missing'], $months),
                'next' => $cont,
                'nextTerm' => $next,
                'nextMissing' => $cont ? $this->workflow->requiredMissing($cont) : [],
            ];
        });
    }

    public function counts(Term $term): array
    {
        $rows = $this->rows($term);

        return [
            'approved' => $rows->count(),
            'months_unexported' => $rows->sum(fn ($r) => count(array_filter($r['months'], fn ($m) => $m['status'] !== Attestation::STATUS_EXPORTED))),
            'continuations_missing' => $rows->filter(fn ($r) => $r['nextTerm'] && $r['next'] === null)->count(),
        ];
    }
}
