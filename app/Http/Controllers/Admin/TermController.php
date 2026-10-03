<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\TermCloseBlockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTermRequest;
use App\Models\AuditLog;
use App\Models\Term;
use App\Services\ApplicationWorkflow;
use App\Services\TermClosingReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TermController extends Controller
{
    public function __construct(private ApplicationWorkflow $workflow) {}

    public function index(): View
    {
        return view('admin.terms.index', ['terms' => Term::withCount('applications')->orderByDesc('teaching_starts_on')->get()]);
    }

    public function create(): View
    {
        return view('admin.terms.form', ['term' => new Term(['status' => Term::STATUS_OPEN])]);
    }

    public function store(StoreTermRequest $request): RedirectResponse
    {
        $term = DB::transaction(function () use ($request) {
            $term = Term::create($request->safe()->except('holidays') + ['status' => Term::STATUS_OPEN]);
            $this->syncHolidays($term, $request);

            return $term;
        });
        AuditLog::record($request->user()->id, 'create_term', $term);

        return redirect()->route('admin.terms.index')->with('status', __('app.common.saved'));
    }

    public function edit(Term $term): View
    {
        abort_unless($term->isOpen(), 403);

        return view('admin.terms.form', ['term' => $term]);
    }

    public function update(StoreTermRequest $request, Term $term): RedirectResponse
    {
        abort_unless($term->isOpen(), 403);

        DB::transaction(function () use ($request, $term) {
            $term->update($request->safe()->except('holidays'));
            $this->syncHolidays($term, $request);
        });
        AuditLog::record($request->user()->id, 'update_term', $term);

        return redirect()->route('admin.terms.index')->with('status', __('app.common.saved'));
    }

    public function close(Request $request, Term $term): RedirectResponse
    {
        abort_unless($term->isOpen(), 403);
        try {
            $n = $this->workflow->closeTerm($term, $request->user());
        } catch (TermCloseBlockedException $e) {
            return redirect()->route('admin.terms.index')
                ->withErrors(['close' => $e->getMessage()])
                ->with('close_blockers', $e->applications->map(fn ($a) => [
                    'name' => $a->instructor->full_name,
                    'status' => __('app.applications.statuses.'.$a->status),
                    'url' => route('admin.applications.show', $a),
                ])->all());
        } catch (\DomainException $e) {
            // Reachable when another admin closed the term between the page load and this request.
            return redirect()->route('admin.terms.index')->withErrors(['close' => $e->getMessage()]);
        }

        return redirect()->route('admin.terms.index')->with('status', __('app.terms.closed_with_drafts', ['n' => $n]));
    }

    public function closing(Term $term, TermClosingReport $report): View
    {
        return view('admin.terms.closing', [
            'term' => $term,
            'rows' => $report->rows($term),
            'counts' => $report->counts($term),
            'nextTerm' => $report->nextTerm($term),
        ]);
    }

    private function syncHolidays(Term $term, StoreTermRequest $request): void
    {
        $term->holidays()->delete();
        foreach ($request->parsedHolidays() as $h) {
            if ($h && empty($h['skip'])) {
                $term->holidays()->create($h);
            }
        }
    }
}
