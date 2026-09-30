<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Attestation;
use App\Models\Term;
use App\Services\Attestations\AttestationService;
use Carbon\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private AttestationService $attestations) {}

    public function index(): View
    {
        $term = Term::current();
        $base = Application::with(['instructor', 'term'])->when($term, fn ($q) => $q->where('term_id', $term->id));

        $department = (clone $base)->whereIn('status', [Application::STATUS_SUBMITTED, Application::STATUS_UNDER_REVIEW])->orderBy('submitted_at')->get();
        $committee = (clone $base)->where('status', Application::STATUS_COMPLETE)->orderBy('complete_at')->get();

        $alerts = collect();
        if ($term) {
            foreach ($term->applications()->where('status', Application::STATUS_APPROVED)->doesntHave('assignments')->with('instructor')->get() as $a) {
                $alerts->push(['text' => __('app.review.alert_unassigned', ['name' => $a->instructor->full_name]), 'url' => route('admin.assignments.index', ['term' => $term->id])]);
            }
            foreach ($term->sections()->where('missing_since_import', true)->get() as $s) {
                $alerts->push(['text' => __('app.review.alert_missing_section', ['section' => $s->course_code.' / '.$s->section_number]), 'url' => route('admin.sections.index', ['term' => $term->id])]);
            }

            $listedIds = $this->attestations->listed($term)->pluck('id');
            if ($listedIds->isNotEmpty()) {
                $today = Carbon::today();
                foreach ($term->months() as $m) {
                    $start = Carbon::create($m['year'], $m['month'], 1);
                    $url = route('admin.attestations.index', ['term' => $term->id, 'month' => $m['index']]);
                    $existing = Attestation::whereIn('application_id', $listedIds)->where(['year' => $m['year'], 'month' => $m['month']]);
                    if ($start->lte($today)) {
                        $missing = $listedIds->count() - (clone $existing)->count();
                        if ($missing > 0) {
                            $alerts->push(['text' => __('app.attestations.alert_missing', ['n' => $missing, 'month' => $m['label']]), 'url' => $url]);
                        }
                    }
                    if ($start->copy()->endOfMonth()->lt($today)) {
                        $pending = (clone $existing)->where('status', Attestation::STATUS_GENERATED)->count();
                        if ($pending > 0) {
                            $alerts->push(['text' => __('app.attestations.alert_unexported', ['n' => $pending, 'month' => $m['label']]), 'url' => $url]);
                        }
                    }
                }
            }
        }

        $counts = Application::whereHas('term', fn ($q) => $q->open())
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('admin.dashboard', compact('department', 'committee', 'alerts', 'counts', 'term'));
    }
}
