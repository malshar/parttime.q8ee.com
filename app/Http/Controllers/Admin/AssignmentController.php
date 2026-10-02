<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Section;
use App\Models\Term;
use App\Services\Sections\AssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AssignmentController extends Controller
{
    public function __construct(private AssignmentService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Section::class);
        $term = $request->filled('term') ? Term::findOrFail($request->term) : Term::current();
        $filters = $request->only(['reference', 'course', 'name', 'instructor']);
        if (! $term) {
            return view('admin.assignments.index', ['term' => null, 'sections' => collect(), 'approved' => collect(), 'suggestions' => [], 'totals' => collect(), 'filters' => $filters, 'terms' => Term::orderByDesc('teaching_starts_on')->get()]);
        }
        $sections = $term->sections()->with(['meetings', 'assignment.application.instructor'])->filter($filters)->orderedByReference()->get();
        $approved = $term->applications()->where('status', Application::STATUS_APPROVED)->with('instructor')->withCount('assignments')->get()->sortBy(fn ($a) => $a->instructor->full_name);
        $totals = $approved->map(fn ($a) => ['name' => $a->instructor->full_name, 'hours' => $a->weeklyHoursLabel(), 'count' => $a->assignments_count]);

        return view('admin.assignments.index', [
            'term' => $term, 'sections' => $sections, 'approved' => $approved,
            'suggestions' => $this->service->suggestionsFor($term), 'totals' => $totals,
            'filters' => $filters, 'terms' => Term::orderByDesc('teaching_starts_on')->get(),
        ]);
    }

    public function store(Request $request, Section $section): RedirectResponse
    {
        $this->authorize('assign', $section);
        $data = $request->validate(['application_id' => ['required', 'integer', 'exists:applications,id']]);
        try {
            $this->service->assign($section, Application::findOrFail($data['application_id']), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['assign' => $e->getMessage()]);
        }

        return back()->with('status', __('app.assignments.assigned'));
    }

    public function destroy(Request $request, Section $section): RedirectResponse
    {
        $this->authorize('unassign', $section);
        try {
            $this->service->unassign($section, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['assign' => $e->getMessage()]);
        }

        return back()->with('status', __('app.assignments.unassigned'));
    }
}
