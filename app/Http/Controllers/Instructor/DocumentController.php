<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadDocumentRequest;
use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\Document;
use App\Services\ApplicationWorkflow;
use App\Services\DocumentStore;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private DocumentStore $store, private ApplicationWorkflow $workflow) {}

    public function store(UploadDocumentRequest $request, Application $application, ChecklistItem $item): RedirectResponse
    {
        $this->authorize('create', [Document::class, $application, $item]);

        $this->store->store($application, $item, $request->file('files'));
        $this->workflow->afterUpload($application); // Task 10 defines it; until then add a no-op method.

        return redirect()->route('instructor.applications.show', $application)->with('status', __('app.documents.uploaded'));
    }

    public function download(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);

        return $this->store->download($document);
    }
}
