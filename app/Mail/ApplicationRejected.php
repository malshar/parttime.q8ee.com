<?php

namespace App\Mail;

use App\Models\Application;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ApplicationRejected extends Mailable
{
    public function __construct(public Application $application) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('app.mail.rejected_subject', [], 'ar'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.application-rejected', with: [
            'name' => $this->application->instructor->full_name,
            'term' => $this->application->term->label(),
            'reason' => $this->application->rejection_reason,
        ]);
    }
}
