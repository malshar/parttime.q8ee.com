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
        $attention = Application::with(['instructor', 'term'])
            ->whereIn('status', [Application::STATUS_SUBMITTED, Application::STATUS_UNDER_REVIEW])
            ->orderBy('submitted_at')->get();

        $counts = Application::whereHas('term', fn ($q) => $q->open())
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('admin.dashboard', ['attention' => $attention, 'counts' => $counts, 'term' => Term::current()]);
    }
}
