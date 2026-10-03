<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordRenewalsRequest;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\CommitteeApproval;
use App\Models\Term;
use App\Services\RenewalListDocument;
use App\Services\RenewalService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RenewalController extends Controller
{
    public function __construct(private RenewalService $renewals) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Application::class);
        $year = $this->resolveYear($request);

        return view('admin.renewals.index', [
            'year' => $year,
            'candidates' => $this->renewals->candidates($year),
            'recorded' => CommitteeApproval::where('academic_year', $year)->with(['instructor', 'applications'])
                ->orderBy('created_at')->get(),
        ]);
    }

    public function store(RecordRenewalsRequest $request): RedirectResponse
    {
        $this->authorize('viewAny', Application::class);
        try {
            $counts = $this->renewals->record(
                $request->year, $request->rows, $request->committee_met_on, $request->committee_reference, $request->user(),
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['renewals' => $e->getMessage()])->withInput();
        } catch (UniqueConstraintViolationException $e) {
            return back()->withErrors(['renewals' => __('app.renewals.already_recorded')])->withInput();
        }

        return back()->with('status', __('app.renewals.recorded', $counts));
    }

    public function list(Request $request, RenewalListDocument $doc): BinaryFileResponse
    {
        $this->authorize('viewAny', Application::class);
        $year = $this->resolveYear($request);
        $candidates = $this->renewals->candidates($year);
        $path = $doc->build($year, $candidates, $request->user());
        AuditLog::record($request->user()->id, 'export_renewal_list', null, null, $year);

        return response()->download($path, "renewal-list-{$year}.docx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    public function destroy(Request $request, CommitteeApproval $approval): RedirectResponse
    {
        $this->authorize('viewAny', Application::class);
        try {
            $this->renewals->delete($approval, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['renewals' => $e->getMessage()]);
        }

        return back()->with('status', __('app.renewals.deleted'));
    }

    /**
     * The year from the query string, when it is well-formed, otherwise the default: the
     * newest `first` term's academic year, when that year still has renewal candidates;
     * otherwise the year after the current open term (or after the current calendar year), as
     * before. An invalid value (mistyped by hand) falls back rather than 404s, since this is a
     * plain filter field, not a resource lookup.
     */
    private function resolveYear(Request $request): string
    {
        $year = $request->string('year')->value();
        if ($year !== '' && preg_match('/^\d{4}-\d{4}$/', $year)) {
            return $year;
        }

        $newestFirst = Term::where('type', 'first')->orderByDesc('teaching_starts_on')->first();
        if ($newestFirst && $this->renewals->candidates($newestFirst->academic_year)->isNotEmpty()) {
            return $newestFirst->academic_year;
        }

        return CommitteeApproval::nextYear(Term::current()?->academic_year ?? (now()->year.'-'.(now()->year + 1)));
    }
}
