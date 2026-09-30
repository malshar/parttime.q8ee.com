<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttestationUpdateRequest;
use App\Models\Attestation;
use App\Models\Term;
use App\Services\Attestations\AttestationDocument;
use App\Services\Attestations\AttestationService;
use App\Services\Attestations\PdfConverter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttestationController extends Controller
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function __construct(private AttestationService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Attestation::class);
        $terms = Term::orderByDesc('teaching_starts_on')->get();
        $term = $request->filled('term') ? Term::findOrFail($request->term) : Term::current();
        if (! $term) {
            return view('admin.attestations.index', ['term' => null, 'terms' => $terms, 'months' => [], 'month' => null, 'rows' => collect()]);
        }
        $month = $this->service->resolveMonth($term, $request->filled('month') ? (int) $request->month : null);
        abort_if($month === null, 404);
        // Listed applications plus any application that already has an attestation for the month (spec §5.1).
        $existing = $this->service->monthAttestations($term, $month['year'], $month['month'])
            ->with('application.instructor')->get()->keyBy('application_id');
        $applications = $this->service->listed($term)->keyBy('id');
        foreach ($existing as $attestation) {
            $applications[$attestation->application_id] ??= $attestation->application;
        }
        $rows = $applications->sortBy(fn ($a) => $a->instructor->full_name)->values()
            ->map(fn ($a) => ['application' => $a, 'attestation' => $existing[$a->id] ?? null]);

        return view('admin.attestations.index', ['term' => $term, 'terms' => $terms, 'months' => $term->months(), 'month' => $month, 'rows' => $rows, 'existingCount' => $existing->count()]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $this->authorize('generate', Attestation::class);
        $data = $request->validate(['term' => ['required', 'integer', 'exists:terms,id'], 'month' => ['required', 'integer', 'min:1', 'max:12']]);
        $term = Term::findOrFail($data['term']);
        $month = $this->service->resolveMonth($term, (int) $data['month']);
        abort_if($month === null, 404);
        try {
            $n = $this->service->generateMissing($term, $month['year'], $month['month'], $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['attestation' => $e->getMessage()]);
        }

        return redirect()->route('admin.attestations.index', ['term' => $term->id, 'month' => $month['index']])
            ->with('status', __('app.attestations.generated_count', ['n' => $n]));
    }

    public function show(Attestation $attestation): View
    {
        $this->authorize('view', $attestation);
        $attestation->load(['weeks', 'application.instructor', 'application.term']);

        return view('admin.attestations.show', ['attestation' => $attestation, 'totals' => $attestation->totals(), 'term' => $attestation->application->term]);
    }

    public function update(AttestationUpdateRequest $request, Attestation $attestation): RedirectResponse
    {
        $this->authorize('update', $attestation);
        try {
            $this->service->update($attestation, $request->weeks(), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['attestation' => $e->getMessage()]);
        }

        return redirect()->route('admin.attestations.show', $attestation)->with('status', __('app.attestations.saved'));
    }

    public function regenerate(Request $request, Attestation $attestation): RedirectResponse
    {
        $this->authorize('regenerate', $attestation);
        try {
            $this->service->regenerate($attestation, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['attestation' => $e->getMessage()]);
        }

        return redirect()->route('admin.attestations.show', $attestation)->with('status', __('app.attestations.regenerated'));
    }

    public function unlock(Request $request, Attestation $attestation): RedirectResponse
    {
        $this->authorize('unlock', $attestation);
        try {
            $this->service->unlock($attestation, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['attestation' => $e->getMessage()]);
        }

        return redirect()->route('admin.attestations.show', $attestation)->with('status', __('app.attestations.unlocked'));
    }

    public function download(Request $request, Attestation $attestation, AttestationDocument $doc, PdfConverter $pdf): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('export', $attestation);
        $format = (string) $request->query('format', 'docx');
        abort_unless(in_array($format, ['docx', 'pdf'], true), 404);
        $name = "kh3-{$attestation->application_id}-{$attestation->year}-{$attestation->month}";
        $docx = $doc->docx($attestation);
        if ($format === 'docx') {
            try {
                $this->service->markExported($attestation, $request->user(), 'docx');

                return response()->download($docx, "$name.docx", ['Content-Type' => self::DOCX_MIME])->deleteFileAfterSend(true);
            } catch (\Throwable $e) {
                @unlink($docx);

                throw $e;
            }
        }
        if (($file = $this->toPdfOrNull($pdf, $docx)) === null) {
            return back()->withErrors(['export' => __('app.attestations.pdf_unavailable')]);
        }
        try {
            $this->service->markExported($attestation, $request->user(), 'pdf');
        } catch (\Throwable $e) {
            @unlink($file);

            throw $e;
        }

        return response()->download($file, "$name.pdf", ['Content-Type' => 'application/pdf'])->deleteFileAfterSend(true);
    }

    public function combined(Request $request, AttestationDocument $doc, PdfConverter $pdf): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('exportAny', Attestation::class);
        $data = $request->validate(['term' => ['required', 'integer', 'exists:terms,id'], 'month' => ['required', 'integer', 'min:1', 'max:12']]);
        $term = Term::findOrFail($data['term']);
        $month = $this->service->resolveMonth($term, (int) $data['month']);
        abort_if($month === null, 404);
        $attestations = $this->service->monthAttestations($term, $month['year'], $month['month'])
            ->with('weeks', 'application.instructor', 'application.term')->get();
        if ($attestations->isEmpty()) {
            return back()->withErrors(['export' => __('app.attestations.none_for_month')]);
        }
        $docx = $doc->combinedDocx($attestations);
        if (($file = $this->toPdfOrNull($pdf, $docx)) === null) {
            return back()->withErrors(['export' => __('app.attestations.pdf_unavailable')]);
        }
        try {
            DB::transaction(function () use ($attestations, $request): void {
                foreach ($attestations as $a) {
                    $this->service->markExported($a, $request->user(), 'combined_pdf');
                }
            });
        } catch (\Throwable $e) {
            @unlink($file);

            throw $e;
        }

        return response()->download($file, "kh3-{$term->id}-{$month['year']}-{$month['month']}.pdf", ['Content-Type' => 'application/pdf'])->deleteFileAfterSend(true);
    }

    /** Converts $docx to PDF, always removing the source .docx; returns null (and reports the failure) on any conversion error. */
    private function toPdfOrNull(PdfConverter $pdf, string $docx): ?string
    {
        try {
            return $pdf->convert($docx);
        } catch (\Throwable $e) {
            report($e);

            return null;
        } finally {
            @unlink($docx);
        }
    }
}
