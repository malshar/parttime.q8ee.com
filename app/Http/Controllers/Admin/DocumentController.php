<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RejectDocumentRequest;
use App\Models\AuditLog;
use App\Models\Document;
use App\Services\ApplicationWorkflow;
use App\Services\DocumentStore;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private ApplicationWorkflow $workflow, private DocumentStore $store) {}

    public function review(RejectDocumentRequest $request, Document $document): RedirectResponse
    {
        $this->authorize('review', $document);
        $this->workflow->reviewDocument($document, $request->user(), $request->status, $request->reason);

        return back()->with('status', __('app.documents.reviewed'));
    }

    public function download(Document $document): StreamedResponse
    {
        $this->authorize('review', $document);
        AuditLog::record(auth()->id(), 'download_document', $document);

        return $this->store->download($document);
    }

    public function view(Document $document): StreamedResponse
    {
        $this->authorize('review', $document);
        AuditLog::record(auth()->id(), 'view_document', $document);

        return $this->store->download($document, inline: true);
    }
}
