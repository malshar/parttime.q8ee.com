<?php

namespace App\Http\Controllers\Instructor;

use App\Exceptions\TermClosedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExemptionRequest;
use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\Term;
use App\Services\ApplicationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    public function __construct(private ApplicationWorkflow $workflow) {}

    public function home(Request $request): View|RedirectResponse
    {
        $instructor = $request->user()->instructor;
        if (! $instructor) {
            return redirect()->route('instructor.profile.edit')->with('status', __('app.profile.incomplete'));
        }
        $term = Term::current();
        $current = $term ? $instructor->applications()->where('term_id', $term->id)->first() : null;
        $past = $instructor->applications()->with('term')->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->orderByDesc('created_at')->get();
        $assigned = $current?->sections()->with('meetings')->get() ?? collect();
        $nextIsContinuation = $term && ! $current && $instructor->hasApprovalFor($term->academic_year);

        return view('instructor.home', compact('instructor', 'term', 'current', 'past', 'assigned', 'nextIsContinuation'));
    }

    public function start(Request $request): RedirectResponse
    {
        $instructor = $request->user()->instructor;
        if (! $instructor) {
            return redirect()->route('instructor.profile.edit')->with('status', __('app.profile.incomplete'));
        }
        $term = Term::current();
        if (! $term) {
            return redirect()->route('instructor.home')->withErrors(['term' => __('app.terms.none_open')]);
        }
        try {
            $application = $this->workflow->start($instructor, $term);
        } catch (TermClosedException) {
            return redirect()->route('instructor.home')->withErrors(['term' => __('app.applications.term_closed')]);
        }

        return redirect()->route('instructor.applications.show', $application);
    }

    public function show(Application $application): View
    {
        $this->authorize('view', $application);

        $checklist = $this->workflow->checklist($application);

        return view('instructor.application', [
            'application' => $application,
            'checklist' => $checklist,
            'stage1' => array_filter($checklist, fn ($r) => $r['stage'] === 1 && ! $r['optional']),
            'stage2' => array_filter($checklist, fn ($r) => $r['stage'] === 2 || $r['optional']),
            'plan' => $this->workflow->plan($application),
            'canSubmit' => $application->isEditable() && $this->workflow->allRequiredUploaded($application),
            'stageTwoComplete' => $this->workflow->stageTwoComplete($application),
            'stageTwoMissing' => $this->workflow->stageTwoMissing($application),
            'showSalaryForm' => $application->acceptsStageTwoUploads() && ($application->instructor->basic_salary === null || $application->instructor->total_salary === null),
            'isContinuation' => $application->isContinuation(),
            'requiredMissing' => $this->workflow->requiredMissing($application),
        ]);
    }

    public function submit(Application $application): RedirectResponse
    {
        $this->authorize('update', $application);
        try {
            $this->workflow->submit($application);
        } catch (\DomainException $e) {
            return back()->withErrors(['submit' => $e->getMessage()]);
        }

        return redirect()->route('instructor.applications.show', $application)->with('status', __('app.applications.submitted'));
    }

    public function withdraw(Application $application): RedirectResponse
    {
        $this->authorize('view', $application);
        abort_if($application->isFinal(), 403);
        $this->workflow->withdraw($application);

        return redirect()->route('instructor.home')->with('status', __('app.applications.withdrawn'));
    }

    public function requestExemption(ExemptionRequest $request, Application $application, ChecklistItem $item): RedirectResponse
    {
        $this->authorize('requestExemption', [$application, $item]);
        try {
            $this->workflow->requestExemption($application, $item, $request->reason);
        } catch (\DomainException $e) {
            return back()->withErrors(['exemption' => $e->getMessage()]);
        }

        return redirect()->route('instructor.applications.show', $application)->with('status', __('app.exemptions.requested'));
    }
}
