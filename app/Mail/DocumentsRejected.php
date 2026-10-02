<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\ChecklistExemption;
use App\Models\ChecklistItem;
use App\Models\ChecklistRenewal;
use App\Models\Document;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DocumentsRejected extends Mailable
{
    /**
     * @param  array<int, array{item: ChecklistItem, document: ?Document, state: string, source: ?Document, renewal: ?ChecklistRenewal, exemption: ?ChecklistExemption}>  $rows
     */
    public function __construct(public Application $application, public array $rows) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('app.mail.docs_rejected_subject', [], 'ar'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.documents-rejected', with: [
            'name' => $this->application->instructor->full_name,
            'term' => $this->application->term->label(),
            'rows' => $this->rows,
            'url' => route('instructor.applications.show', $this->application),
        ]);
    }
}
