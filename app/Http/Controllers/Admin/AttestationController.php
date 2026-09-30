<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttestationUpdateRequest;
use App\Models\Attestation;
use App\Models\Term;
use App\Services\Attestations\AttestationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttestationController extends Controller
{
    public function __construct(private AttestationService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Attestation::class);
        $terms = Term::orderByDesc('teaching_starts_on')->get();
        $term = $request->filled('term') ? Term::findOrFail($request->term) : Term::current();
        if (! $term) {
            return view('admin.attestations.index', ['term' => null, 'terms' => $terms, 'months' => [], 'month' => null, 'rows' => collect()]);
        }
        $month = $this->service->resolveMonth($term, $request->filled('month') ? (int) $request->month : null);
        abort_if($month === null, 404);
        $listed = $this->service->listed($term);
        $existing = Attestation::whereIn('application_id', $listed->pluck('id'))
            ->where(['year' => $month['year'], 'month' => $month['month']])->get()->keyBy('application_id');
        $rows = $listed->map(fn ($a) => ['application' => $a, 'attestation' => $existing[$a->id] ?? null]);

        return view('admin.attestations.index', ['term' => $term, 'terms' => $terms, 'months' => $term->months(), 'month' => $month, 'rows' => $rows, 'existingCount' => $existing->count()]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $this->authorize('generate', Attestation::class);
        $data = $request->validate(['term' => ['required', 'integer', 'exists:terms,id'], 'month' => ['required', 'integer', 'min:1', 'max:12']]);
        $term = Term::findOrFail($data['term']);
        $month = $this->service->resolveMonth($term, (int) $data['month']);
        abort_if($month === null, 404);
        try {
            $n = $this->service->generateMissing($term, $month['year'], $month['month'], $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['attestation' => $e->getMessage()]);
        }

        return redirect()->route('admin.attestations.index', ['term' => $term->id, 'month' => $month['index']])
            ->with('status', __('app.attestations.generated_count', ['n' => $n]));
    }

    public function show(Attestation $attestation): View
    {
        $this->authorize('view', $attestation);
        $attestation->load(['weeks', 'application.instructor', 'application.term']);

        return view('admin.attestations.show', ['attestation' => $attestation, 'totals' => $attestation->totals(), 'term' => $attestation->application->term]);
    }

    public function update(AttestationUpdateRequest $request, Attestation $attestation): RedirectResponse
    {
        $this->authorize('update', $attestation);
        try {
            $this->service->update($attestation, $request->weeks(), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['attestation' => $e->getMessage()]);
        }

        return redirect()->route('admin.attestations.show', $attestation)->with('status', __('app.attestations.saved'));
    }

    public function regenerate(Request $request, Attestation $attestation): RedirectResponse
    {
        $this->authorize('regenerate', $attestation);
        try {
            $this->service->regenerate($attestation, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['attestation' => $e->getMessage()]);
        }

        return redirect()->route('admin.attestations.show', $attestation)->with('status', __('app.attestations.regenerated'));
    }

    public function unlock(Request $request, Attestation $attestation): RedirectResponse
    {
        $this->authorize('unlock', $attestation);
        try {
            $this->service->unlock($attestation, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['attestation' => $e->getMessage()]);
        }

        return redirect()->route('admin.attestations.show', $attestation)->with('status', __('app.attestations.unlocked'));
    }
}
