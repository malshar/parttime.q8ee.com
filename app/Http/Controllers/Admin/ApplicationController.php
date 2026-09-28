<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AuditLog;
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
            'history' => $application->documents()->with('checklistItem')->orderBy('checklist_item_id')->orderByDesc('version')->get(),
            'revealed' => in_array($application->id, session('revealed_applications', []), true),
            'canApprove' => $this->workflow->allRequiredAccepted($application) && $application->term->isOpen() && ! $application->isFinal(),
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

    public function approve(Request $request, Application $application): RedirectResponse
    {
        $this->authorize('review', $application);
        $data = $request->validate([
            'assignment_decision_number' => ['nullable', 'string', 'max:40'],
            'assignment_decision_date' => ['nullable', 'date'],
        ]);
        try {
            $this->workflow->approve($application, $request->user(), $data['assignment_decision_number'] ?? null, $data['assignment_decision_date'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }

        return back()->with('status', __('app.review.approved'));
    }

    public function reject(Request $request, Application $application): RedirectResponse
    {
        $this->authorize('review', $application);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        try {
            $this->workflow->reject($application, $request->user(), $data['reason']);
        } catch (\DomainException $e) {
            return back()->withErrors(['reject' => $e->getMessage()]);
        }

        return back()->with('status', __('app.review.rejected'));
    }

    public function checklist(Request $request, Application $application, ChecklistDocument $doc): BinaryFileResponse
    {
        $this->authorize('review', $application);
        AuditLog::record($request->user()->id, 'print_checklist', $application);

        return response()->download($doc->build($application, $request->user()), "checklist-{$application->id}.docx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }
}
