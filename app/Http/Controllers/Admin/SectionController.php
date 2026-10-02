<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SectionController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Section::class);
        $term = $request->filled('term') ? Term::findOrFail($request->term) : Term::current();
        $filters = $request->only(['reference', 'course', 'name', 'instructor']);
        $sections = $term
            ? $term->sections()->with(['meetings', 'assignment.application.instructor'])
                ->filter($filters)
                ->when($request->boolean('unassigned'), fn ($q) => $q->doesntHave('assignment'))
                ->orderedByReference()->get()
            : collect();

        return view('admin.sections.index', ['term' => $term, 'sections' => $sections, 'filters' => $filters, 'terms' => Term::orderByDesc('teaching_starts_on')->get()]);
    }
}
