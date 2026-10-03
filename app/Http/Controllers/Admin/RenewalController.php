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
use Illuminate\Database\QueryException;
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
            return back()->withErrors(['renewals' => $e->getMessage()]);
        } catch (QueryException $e) {
            return back()->withErrors(['renewals' => __('app.renewals.already_recorded')]);
        }

        return back()->with('status', __('app.renewals.recorded', $counts));
    }

    public function list(Request $request, RenewalListDocument $doc): BinaryFileResponse
    {
        $this->authorize('viewAny', Application::class);
        $year = $this->resolveYear($request);
        $candidates = $this->renewals->candidates($year);
        AuditLog::record($request->user()->id, 'export_renewal_list', null, null, $year);

        return response()->download($doc->build($year, $candidates, $request->user()), "renewal-list-{$year}.docx", [
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

    private function resolveYear(Request $request): string
    {
        return $request->filled('year') ? $request->string('year')->value()
            : CommitteeApproval::nextYear(Term::current()?->academic_year ?? (now()->year.'-'.(now()->year + 1)));
    }
}
