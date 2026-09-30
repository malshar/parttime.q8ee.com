<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        $existing = Attestation::whereIn('application_id', $this->service->listed($term)->pluck('id'))
            ->where(['year' => $month['year'], 'month' => $month['month']])->get()->keyBy('application_id');
        $rows = $this->service->listed($term)->map(fn ($a) => ['application' => $a, 'attestation' => $existing[$a->id] ?? null]);

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
}
