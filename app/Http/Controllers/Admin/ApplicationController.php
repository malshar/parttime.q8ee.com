<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CommitteeDecisionRequest;
use App\Http\Requests\DecideExemptionRequest;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\ChecklistExemption;
use App\Models\ChecklistItem;
use App\Models\Term;
use App\Services\ApplicationWorkflow;
use App\Services\ChecklistDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ApplicationController extends Controller
{
    public function __construct(private ApplicationWorkflow $workflow) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Application::class);
        $term = $request->filled('term') ? Term::findOrFail($request->term) : Term::current();
        $apps = Application::with(['instructor', 'term'])
            ->when($term, fn ($q) => $q->where('term_id', $term->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('submitted_at')->paginate(50)->withQueryString();

        return view('admin.applications.index', ['applications' => $apps, 'term' => $term, 'terms' => Term::orderByDesc('teaching_starts_on')->get()]);
    }

    public function show(Request $request, Application $application): View
    {
        $this->authorize('review', $application);
        $this->workflow->markUnderReview($application);
        $application->refresh();

        return view('admin.applications.show', [
            'application' => $application,
            'instructor' => $application->instructor,
            'checklist' => $this->workflow->checklist($application),
            'plan' => $this->workflow->plan($application),
            'sections' => $application->sections()->with('meetings')->get(),
            'history' => $application->documents()->with('checklistItem')->where('part', 1)->orderBy('checklist_item_id')->orderByDesc('version')->get(),
            'revealed' => in_array($application->id, session('revealed_applications', []), true),
            'canComplete' => in_array($application->status, [Application::STATUS_UNDER_REVIEW, Application::STATUS_INCOMPLETE], true)
                && $application->term->isOpen() && $this->workflow->allRequiredAccepted($application),
            'pendingNotices' => $this->workflow->pendingRejectionNotices($application),
        ]);
    }

    public function reveal(Request $request, Application $application): RedirectResponse
    {
        $this->authorize('review', $application);
        AuditLog::record($request->user()->id, 'reveal_sensitive', $application);
        $ids = session('revealed_applications', []);
        $ids[] = $application->id;
        session(['revealed_applications' => array_values(array_unique($ids))]);

        return back();
    }

    public function committee(CommitteeDecisionRequest $request, Application $application): RedirectResponse
    {
        $this->authorize('review', $application);
        try {
            $this->workflow->committeeDecision($application, $request->user(), $request->outcome, $request->committee_met_on, $request->committee_reference, $request->committee_note ?: null);
        } catch (\DomainException $e) {
            return back()->withErrors(['committee' => $e->getMessage()]);
        }

        return back()->with('status', __('app.review.committee_saved'));
    }

    public function notifyRejections(Request $request, Application $application): RedirectResponse
    {
        $this->authorize('review', $application);
        try {
            $n = $this->workflow->notifyRejections($application, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['notify' => $e->getMessage()]);
        }

        return back()->with('status', __('app.review.notified', ['count' => $n]));
    }

    public function requestFreshCopy(Request $request, Application $application, ChecklistItem $item): RedirectResponse
    {
        $this->authorize('review', $application);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->workflow->requestFreshCopy($application, $item, $request->user(), $data['reason']);
        } catch (\DomainException $e) {
            return back()->withErrors(['renewal' => $e->getMessage()]);
        }

        return back()->with('status', __('app.review.fresh_copy_requested'));
    }

    public function complete(Request $request, Application $application): RedirectResponse
    {
        $this->authorize('review', $application);
        try {
            $this->workflow->markComplete($application, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['complete' => $e->getMessage()]);
        }

        return back()->with('status', __('app.review.completed'));
    }

    public function reopen(Request $request, Application $application): RedirectResponse
    {
        $this->authorize('review', $application);
        try {
            $this->workflow->reopen($application, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['reopen' => $e->getMessage()]);
        }

        return back()->with('status', __('app.review.reopened'));
    }

    public function decision(Request $request, Application $application): RedirectResponse
    {
        $this->authorize('review', $application);
        $data = $request->validate([
            'assignment_decision_number' => ['required', 'string', 'max:40'],
            'assignment_decision_date' => ['required', 'date'],
        ]);
        if ($application->status !== Application::STATUS_APPROVED) {
            return back()->withErrors(['decision' => __('app.review.decision_not_approved')]);
        }
        $application->update($data);
        AuditLog::record($request->user()->id, 'set_decision', $application);

        return back()->with('status', __('app.review.decision_saved'));
    }

    public function decideExemption(DecideExemptionRequest $request, ChecklistExemption $exemption): RedirectResponse
    {
        $this->authorize('review', $exemption->application);
        try {
            $this->workflow->decideExemption($exemption, $request->user(), $request->status, $request->decision_note);
        } catch (\DomainException $e) {
            return back()->withErrors(['exemption' => $e->getMessage()]);
        }

        return back()->with('status', __('app.exemptions.decided'));
    }

    public function checklist(Request $request, Application $application, ChecklistDocument $doc): BinaryFileResponse
    {
        $this->authorize('review', $application);
        AuditLog::record($request->user()->id, 'print_checklist', $application);

        return response()->download($doc->build($application, $request->user()), "checklist-{$application->id}.docx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }
}
