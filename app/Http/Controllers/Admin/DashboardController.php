<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Term;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $term = Term::current();
        $base = Application::with(['instructor', 'term'])->when($term, fn ($q) => $q->where('term_id', $term->id));

        $department = (clone $base)->whereIn('status', [Application::STATUS_SUBMITTED, Application::STATUS_UNDER_REVIEW])->orderBy('submitted_at')->get();
        $committee = (clone $base)->where('status', Application::STATUS_COMPLETE)->orderBy('complete_at')->get();
        $alerts = collect(); // Task 12 fills: approved without assignments, flagged sections

        $counts = Application::whereHas('term', fn ($q) => $q->open())
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('admin.dashboard', compact('department', 'committee', 'alerts', 'counts', 'term'));
    }
}
