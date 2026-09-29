<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Section;
use App\Services\Sections\AssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    public function __construct(private AssignmentService $service) {}

    public function store(Request $request, Section $section): RedirectResponse
    {
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
        try {
            $this->service->unassign($section, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['assign' => $e->getMessage()]);
        }

        return back()->with('status', __('app.assignments.unassigned'));
    }
}
