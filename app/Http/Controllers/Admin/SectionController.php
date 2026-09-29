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
        $sections = $term
            ? $term->sections()->with(['meetings', 'assignment.application.instructor'])
                ->when($request->filled('course'), fn ($q) => $q->where('course_code', 'like', $request->course.'%'))
                ->when($request->boolean('unassigned'), fn ($q) => $q->doesntHave('assignment'))
                ->orderBy('course_code')->orderBy('section_number')->get()
            : collect();

        return view('admin.sections.index', ['term' => $term, 'sections' => $sections, 'terms' => Term::orderByDesc('teaching_starts_on')->get()]);
    }
}
