<?php

namespace App\Mail;

use App\Models\Application;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ApplicationReopened extends Mailable
{
    public function __construct(public Application $application) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('app.mail.reopened_subject', [], 'ar'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.application-reopened', with: [
            'name' => $this->application->instructor->full_name,
            'term' => $this->application->term->label(),
            'url' => route('instructor.applications.show', $this->application),
        ]);
    }
}
