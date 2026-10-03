<?php

namespace App\Mail;

use App\Models\CommitteeApproval;
use App\Models\Instructor;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RenewalRefused extends Mailable
{
    public function __construct(public Instructor $instructor, public CommitteeApproval $approval) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('app.mail.renewal_refused_subject', [], 'ar'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.renewal-refused', with: [
            'name' => $this->instructor->full_name,
            'year' => $this->approval->academic_year,
            'note' => $this->approval->note,
        ]);
    }
}
