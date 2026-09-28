<?php

namespace App\Services;

use App\Exceptions\TermClosedException;
use App\Mail\ApplicationApproved;
use App\Mail\ApplicationRejected;
use App\Mail\ApplicationSubmitted;
use App\Mail\DocumentsRejected;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\ChecklistItem;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ApplicationWorkflow
{
    public function __construct(private ChecklistResolver $resolver) {}

    public function start(Instructor $instructor, Term $term): Application
    {
        if (! $term->isOpen()) {
            throw new TermClosedException;
        }

        return Application::firstOrCreate(
            ['term_id' => $term->id, 'instructor_id' => $instructor->id],
            ['status' => Application::STATUS_DRAFT],
        );
    }

    /**
     * @return array<string, array{item: ChecklistItem, document: ?Document, state: string}>
     */
    public function checklist(Application $application): array
    {
        $plan = $this->resolver->for($application->instructor);
        $docs = $application->latestDocuments();
        $out = [];
        foreach ($plan->required as $item) {
            $doc = $docs->get($item->code);
            $out[$item->code] = ['item' => $item, 'document' => $doc, 'state' => $doc?->status ?? 'missing'];
        }

        return $out;
    }

    public function plan(Application $application): ChecklistPlan
    {
        return $this->resolver->for($application->instructor);
    }

    public function allRequiredAccepted(Application $application): bool
    {
        foreach ($this->checklist($application) as $row) {
            if ($row['state'] !== 'accepted') {
                return false;
            }
        }

        return true;
    }

    public function allRequiredUploaded(Application $application): bool
    {
        foreach ($this->checklist($application) as $row) {
            if ($row['state'] === 'missing' || $row['state'] === 'rejected') {
                return false;
            }
        }

        return true;
    }

    public function submit(Application $application): void
    {
        if (! $application->isEditable()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if (! $this->allRequiredUploaded($application)) {
            throw new \DomainException(__('app.applications.submit_blocked'));
        }
        $application->update(['status' => Application::STATUS_SUBMITTED, 'submitted_at' => now()]);
        $this->notifyAdmin($application);
    }

    public function afterUpload(Application $application): void
    {
        if ($application->status === Application::STATUS_INCOMPLETE && $this->allRequiredUploaded($application)) {
            $application->update(['status' => Application::STATUS_SUBMITTED, 'submitted_at' => now()]);
            $this->notifyAdmin($application);
        }
    }

    public function withdraw(Application $application): void
    {
        if ($application->isFinal()) {
            throw new \DomainException('final');
        }
        $application->update(['status' => Application::STATUS_WITHDRAWN, 'decided_at' => now()]);
    }

    public function markUnderReview(Application $application): void
    {
        if ($application->status === Application::STATUS_SUBMITTED) {
            $application->update(['status' => Application::STATUS_UNDER_REVIEW, 'reviewed_at' => now()]);
        }
    }

    public function reviewDocument(Document $document, User $admin, string $status, ?string $reason): void
    {
        if ($document->application->isFinal()) {
            throw new \DomainException(__('app.review.already_final'));
        }
        if (! $document->application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if (! $document->application->latestDocuments()->get($document->checklistItem->code)?->is($document)) {
            throw new \DomainException(__('app.review.superseded_version'));
        }

        $document->update([
            'status' => $status,
            'rejection_reason' => $status === Document::STATUS_REJECTED ? $reason : null,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);
        AuditLog::record($admin->id, 'review_document_'.$status, $document);

        $application = $document->application->fresh();
        if ($status === Document::STATUS_REJECTED && ! $application->isFinal()) {
            $application->update(['status' => Application::STATUS_INCOMPLETE]);
            $rejected = $this->checklist($application);
            $rejected = array_filter($rejected, fn ($row) => $row['state'] === 'rejected');
            $this->safeSend($application->instructor->user->email, new DocumentsRejected($application, array_values($rejected)));
        }
    }

    public function approve(Application $application, User $admin, ?string $decisionNumber, ?string $decisionDate): void
    {
        if ($application->isFinal()) {
            throw new \DomainException(__('app.review.already_final'));
        }
        if (! $application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if (! $this->allRequiredAccepted($application)) {
            throw new \DomainException(__('app.review.approve_blocked'));
        }
        $application->update([
            'status' => Application::STATUS_APPROVED, 'decided_at' => now(),
            'assignment_decision_number' => $decisionNumber, 'assignment_decision_date' => $decisionDate,
        ]);
        AuditLog::record($admin->id, 'approve_application', $application);
        $this->safeSend($application->instructor->user->email, new ApplicationApproved($application));
    }

    public function reject(Application $application, User $admin, string $reason): void
    {
        if ($application->isFinal()) {
            throw new \DomainException(__('app.review.already_final'));
        }
        if (! $application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        $application->update(['status' => Application::STATUS_REJECTED, 'decided_at' => now(), 'rejection_reason' => $reason]);
        AuditLog::record($admin->id, 'reject_application', $application);
        $this->safeSend($application->instructor->user->email, new ApplicationRejected($application));
    }

    private function notifyAdmin(Application $application): void
    {
        if ($to = config('mail.admin_notify')) {
            $this->safeSend($to, new ApplicationSubmitted($application));

            return;
        }

        Log::warning('ADMIN_NOTIFY_EMAIL is not set; admin was not notified of application submission', ['application_id' => $application->id]);
    }

    /**
     * Mail is sent synchronously after the state change has been saved; a mail
     * failure (SMTP down, bad credentials) must not turn a completed action into a 500.
     */
    private function safeSend(string $to, Mailable $mail): void
    {
        try {
            Mail::to($to)->send($mail);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
