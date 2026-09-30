<?php

namespace App\Services;

use App\Exceptions\TermCloseBlockedException;
use App\Exceptions\TermClosedException;
use App\Mail\ApplicationApproved;
use App\Mail\ApplicationRejected;
use App\Mail\ApplicationReopened;
use App\Mail\ApplicationSubmitted;
use App\Mail\DocumentsRejected;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\ChecklistItem;
use App\Models\ChecklistRenewal;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ApplicationWorkflow
{
    public const STATE_ON_FILE = 'on_file';

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
     * Spec §4.2. One row per required item:
     * ['item' => ChecklistItem, 'document' => ?Document, 'state' => string, 'source' => ?Document, 'renewal' => ?ChecklistRenewal]
     *
     * @return array<string, array{item: ChecklistItem, document: ?Document, state: string, source: ?Document, renewal: ?ChecklistRenewal}>
     */
    public function checklist(Application $application): array
    {
        $plan = $this->resolver->for($application->instructor);
        $docs = $application->latestDocuments();
        $renewals = $application->renewals()->get()->keyBy('checklist_item_id');
        $onFile = null;
        $out = [];
        foreach ($plan->required as $item) {
            $doc = $docs->get($item->code);
            $row = ['item' => $item, 'document' => $doc, 'state' => 'missing', 'source' => null, 'renewal' => $renewals->get($item->id)];
            if ($doc) {
                $row['state'] = $doc->status;
            } elseif ($row['renewal'] === null && ! $item->renews_each_term
                && ! ($item->code === 'civil_id' && $application->instructor->civilIdExpired())) {
                $onFile ??= $this->onFileDocuments($application);
                if ($source = $onFile->get($item->code)) {
                    $row['state'] = self::STATE_ON_FILE;
                    $row['source'] = $source;
                }
            }
            $out[$item->code] = $row;
        }

        return $out;
    }

    /**
     * Latest accepted document per item code from the instructor's other applications in earlier terms
     * (spec §4.2 rule 5). Keyed by item code; `checklistItem` and `application.term` are loaded.
     *
     * @return Collection<string, Document>
     */
    public function onFileDocuments(Application $application): Collection
    {
        $termStart = $application->term->teaching_starts_on;

        return Document::query()
            ->where('status', Document::STATUS_ACCEPTED)
            ->whereHas('application', fn ($q) => $q->where('instructor_id', $application->instructor_id)
                ->whereKeyNot($application->id)
                ->whereHas('term', fn ($t) => $t->where('teaching_starts_on', '<', $termStart)))
            ->with(['checklistItem', 'application.term'])
            ->orderByDesc('reviewed_at')->orderByDesc('id')
            ->get()
            ->unique('checklist_item_id')
            ->keyBy(fn (Document $d) => $d->checklistItem->code);
    }

    public function plan(Application $application): ChecklistPlan
    {
        return $this->resolver->for($application->instructor);
    }

    public function allRequiredAccepted(Application $application): bool
    {
        foreach ($this->checklist($application) as $row) {
            if (! in_array($row['state'], [Document::STATUS_ACCEPTED, self::STATE_ON_FILE], true)) {
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
            $application->update(['status' => Application::STATUS_INCOMPLETE, 'complete_at' => null]);
        }
    }

    /** Rows the applicant has not been told about yet: rejected documents and fresh-copy requests. */
    public function pendingRejectionNotices(Application $application): array
    {
        return array_values(array_filter($this->checklist($application), fn ($row) => $this->needsNotice($row) && $this->noticeSent($row) === false));
    }

    private function needsNotice(array $row): bool
    {
        return $row['state'] === Document::STATUS_REJECTED || ($row['renewal'] !== null && $row['document'] === null);
    }

    private function noticeSent(array $row): bool
    {
        return $row['state'] === Document::STATUS_REJECTED
            ? $row['document']->notified_at !== null
            : $row['renewal']->notified_at !== null;
    }

    public function notifyRejections(Application $application, User $admin): int
    {
        if (! $application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if ($this->pendingRejectionNotices($application) === []) {
            throw new \DomainException(__('app.review.nothing_to_notify'));
        }
        $rows = array_values(array_filter($this->checklist($application), fn ($row) => $this->needsNotice($row)));
        foreach ($rows as $row) {
            ($row['state'] === Document::STATUS_REJECTED ? $row['document'] : $row['renewal'])->update(['notified_at' => now()]);
        }
        AuditLog::record($admin->id, 'notify_rejections', $application);
        $this->safeSend($application->instructor->user->email, new DocumentsRejected($application, $rows));

        return count($rows);
    }

    /** Spec §4.4: an admin demands a fresh copy of an on-file item. */
    public function requestFreshCopy(Application $application, ChecklistItem $item, User $admin, string $reason): void
    {
        if (! $application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if (! in_array($application->status, Application::UNFINISHED_STATUSES, true)) {
            throw new \DomainException(__('app.review.fresh_copy_wrong_status'));
        }
        $row = $this->checklist($application)[$item->code] ?? null;
        if (($row['state'] ?? null) !== self::STATE_ON_FILE) {
            throw new \DomainException(__('app.review.fresh_copy_wrong_state'));
        }

        DB::transaction(function () use ($application, $item, $admin, $reason) {
            ChecklistRenewal::updateOrCreate(
                ['application_id' => $application->id, 'checklist_item_id' => $item->id],
                ['reason' => $reason, 'requested_by' => $admin->id, 'requested_at' => now(), 'notified_at' => null],
            );
            $application->update(['status' => Application::STATUS_INCOMPLETE, 'complete_at' => null, 'reviewed_at' => $application->reviewed_at ?? now()]);
            AuditLog::record($admin->id, 'request_fresh_copy', $application, null, $item->code);
        });
    }

    public function markComplete(Application $application, User $admin): void
    {
        if (! in_array($application->status, [Application::STATUS_UNDER_REVIEW, Application::STATUS_INCOMPLETE], true)) {
            throw new \DomainException(__('app.review.complete_wrong_status'));
        }
        if (! $application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if (! $this->allRequiredAccepted($application)) {
            throw new \DomainException(__('app.review.complete_blocked'));
        }
        $application->update(['status' => Application::STATUS_COMPLETE, 'complete_at' => now()]);
        AuditLog::record($admin->id, 'mark_complete', $application);
    }

    public function committeeDecision(Application $application, User $admin, string $outcome, string $metOn, string $reference, ?string $note): void
    {
        if ($application->isFinal()) {
            throw new \DomainException(__('app.review.already_final'));
        }
        if ($application->status !== Application::STATUS_COMPLETE) {
            throw new \DomainException(__('app.review.committee_wrong_status'));
        }
        if (! $application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }

        $approved = $outcome === 'approved';
        $application->update([
            'status' => $approved ? Application::STATUS_APPROVED : Application::STATUS_REJECTED,
            'decided_at' => now(),
            'committee_outcome' => $outcome,
            'committee_met_on' => $metOn,
            'committee_reference' => $reference,
            'committee_note' => $note,
            'rejection_reason' => $approved ? null : $note,
        ]);
        AuditLog::record($admin->id, 'committee_decision', $application);
        $this->safeSend(
            $application->instructor->user->email,
            $approved ? new ApplicationApproved($application) : new ApplicationRejected($application),
        );
    }

    public function reopen(Application $application, User $admin): void
    {
        if ($application->status !== Application::STATUS_WITHDRAWN) {
            throw new \DomainException(__('app.review.reopen_wrong_status'));
        }
        if (! $application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        $application->update(['status' => Application::STATUS_DRAFT, 'decided_at' => null]);
        AuditLog::record($admin->id, 'reopen_application', $application);
        $this->safeSend($application->instructor->user->email, new ApplicationReopened($application));
    }

    /**
     * Spec §3.1: refuse while any application is unfinished; otherwise withdraw never-submitted
     * drafts and close, in one transaction. Returns the number of drafts withdrawn.
     */
    public function closeTerm(Term $term, User $admin): int
    {
        if (! $term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        $blocking = $term->applications()->whereIn('status', Application::UNFINISHED_STATUSES)->with('instructor')->get();
        if ($blocking->isNotEmpty()) {
            throw new TermCloseBlockedException($blocking);
        }

        return DB::transaction(function () use ($term, $admin) {
            $n = $term->applications()->where('status', Application::STATUS_DRAFT)
                ->update(['status' => Application::STATUS_WITHDRAWN, 'decided_at' => now()]);
            $term->update(['status' => Term::STATUS_CLOSED]);
            AuditLog::record($admin->id, 'close_term', $term, null, 'drafts_withdrawn='.$n);

            return $n;
        });
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
