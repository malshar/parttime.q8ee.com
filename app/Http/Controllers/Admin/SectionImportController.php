<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportSectionsRequest;
use App\Models\Term;
use App\Services\Sections\JadawilParser;
use App\Services\Sections\ParsedMeeting;
use App\Services\Sections\ParsedSection;
use App\Services\Sections\ParsedTimetable;
use App\Services\Sections\SectionImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SectionImportController extends Controller
{
    public function __construct(private JadawilParser $parser, private SectionImporter $importer) {}

    public function form(): View
    {
        return view('admin.sections.import', ['term' => Term::current()]);
    }

    public function preview(ImportSectionsRequest $request): View
    {
        $term = Term::current();
        $file = $request->file('file');
        $timetable = $this->parser->parse($file->get(), $file->getClientOriginalExtension());
        $plan = $this->importer->plan($term, $timetable);

        session(['sections_import' => ['term_id' => $term->id, 'timetable' => serialize($timetable)]]);

        return view('admin.sections.preview', ['term' => $term, 'timetable' => $timetable, 'plan' => $plan]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $payload = session('sections_import');
        abort_if(! $payload, 419);
        $term = Term::findOrFail($payload['term_id']);
        /** @var ParsedTimetable $timetable */
        $timetable = unserialize($payload['timetable'], ['allowed_classes' => [ParsedTimetable::class, ParsedSection::class, ParsedMeeting::class]]);
        try {
            $plan = $this->importer->apply($term, $timetable, $request->user());
        } catch (\DomainException $e) {
            return redirect()->route('admin.sections.import.form')->withErrors(['file' => $e->getMessage()]);
        }
        session()->forget('sections_import');
        $c = $plan->counts();

        return redirect()->route('admin.sections.index')->with('status', __('app.sections.imported', $c));
    }
}
