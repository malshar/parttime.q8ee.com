<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTermRequest;
use App\Models\AuditLog;
use App\Models\Term;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TermController extends Controller
{
    public function index(): View
    {
        return view('admin.terms.index', ['terms' => Term::orderByDesc('teaching_starts_on')->get()]);
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
        return view('admin.terms.form', ['term' => $term]);
    }

    public function update(StoreTermRequest $request, Term $term): RedirectResponse
    {
        DB::transaction(function () use ($request, $term) {
            $term->update($request->safe()->except('holidays'));
            $this->syncHolidays($term, $request);
        });
        AuditLog::record($request->user()->id, 'update_term', $term);

        return redirect()->route('admin.terms.index')->with('status', __('app.common.saved'));
    }

    public function close(Term $term): RedirectResponse
    {
        $term->update(['status' => Term::STATUS_CLOSED]);
        AuditLog::record(auth()->id(), 'close_term', $term);

        return back()->with('status', __('app.terms.closed'));
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
