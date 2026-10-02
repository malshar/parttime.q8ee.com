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
use App\Models\ChecklistExemption;
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

    public const STATE_EXEMPTION_REQUESTED = 'exemption_requested';

    public const STATE_EXEMPTED = 'exempted';

    /** Row states that satisfy a required item. */
    public const SATISFIED_STATES = [Document::STATUS_ACCEPTED, self::STATE_ON_FILE, self::STATE_EXEMPTED];

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
     * Spec 5b §4. Stage-1 rows first, then stage-2, then optional rows:
     * ['item', 'document' (head, parts loaded), 'state', 'source', 'renewal', 'exemption', 'stage', 'optional']
     * state ∈ missing|pending|accepted|rejected|on_file|exemption_requested|exempted.
     *
     * @return array<string, array{item: ChecklistItem, document: ?Document, state: string, source: ?Document, renewal: ?ChecklistRenewal, exemption: ?ChecklistExemption, stage: int, optional: bool}>
     */
    public function checklist(Application $application): array
    {
        $plan = $this->resolver->for($application->instructor);
        $docs = $application->latestDocuments();
        $renewals = $application->renewals()->get()->keyBy('checklist_item_id');
        $exemptions = $application->exemptions()->get()->keyBy('checklist_item_id');
        $onFile = null;
        $out = [];
        foreach ($plan->required->concat($plan->optional) as $item) {
            $doc = $docs->get($item->code);
            $exemption = $exemptions->get($item->id);
            $row = ['item' => $item, 'document' => $doc, 'state' => 'missing', 'source' => null,
                'renewal' => $renewals->get($item->id), 'exemption' => $exemption,
                'stage' => (int) $item->stage, 'optional' => (bool) $item->optional];
            if ($doc) {
                $row['state'] = $doc->status;
            } elseif ($exemption?->status === ChecklistExemption::STATUS_ACCEPTED) {
                $row['state'] = self::STATE_EXEMPTED;
            } elseif ($exemption?->status === ChecklistExemption::STATUS_PENDING) {
                $row['state'] = self::STATE_EXEMPTION_REQUESTED;
            } elseif ($row['renewal'] === null && ! $item->renews_each_term
                // Rule 4 is skipped for final applications so their record does not flip once the card expires.
                && ! ($item->code === 'civil_id' && ! $application->isFinal() && $application->instructor->civilIdExpired())) {
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

    /** @return array<string, array<string, mixed>> stage-1 required rows only */
    private function stageOneRows(Application $application): array
    {
        return array_filter($this->checklist($application), fn ($row) => $row['stage'] === ChecklistItem::STAGE_COMMITTEE && ! $row['optional']);
    }

    /** @return array<string, array<string, mixed>> stage-2 required rows only */
    private function stageTwoRows(Application $application): array
    {
        return array_filter($this->checklist($application), fn ($row) => $row['stage'] === ChecklistItem::STAGE_AFTER_APPROVAL && ! $row['optional']);
    }

    /**
     * Latest accepted document per item code from the instructor's other applications in earlier terms
     * (spec §4.2 rule 5), minus any whose certified profile fields changed after it was accepted
     * (rule 4b). Keyed by item code; `checklistItem` and `application.term` are loaded.
     *
     * @return Collection<string, Document>
     */
    public function onFileDocuments(Application $application): Collection
    {
        $termStart = $application->term->teaching_starts_on;

        $sources = Document::query()
            ->where('status', Document::STATUS_ACCEPTED)
            ->where('part', 1)
            ->whereHas('application', fn ($q) => $q->where('instructor_id', $application->instructor_id)
                ->whereKeyNot($application->id)
                ->whereHas('term', fn ($t) => $t->where('teaching_starts_on', '<', $termStart)))
            ->with(['checklistItem', 'application.term'])
            ->orderByDesc('reviewed_at')->orderByDesc('id')
            ->get()
            ->unique('checklist_item_id')
            ->keyBy(fn (Document $d) => $d->checklistItem->code);

        foreach ($sources as $source) {
            $source->setRelation('parts', $source->parts()->get());
        }

        // Rule 4b is skipped for final applications so their record does not flip after a later edit.
        if ($application->isFinal()) {
            return $sources;
        }

        $edits = $this->profileEditsSince($application->instructor_id, $sources->min('reviewed_at'));

        return $sources->reject(function (Document $source, string $code) use ($edits) {
            $fields = ChecklistItem::PROFILE_FIELDS[$code] ?? [];

            return $fields !== [] && $edits->contains(fn (AuditLog $log) => $log->created_at > $source->reviewed_at
                && array_intersect($fields, explode(',', (string) $log->details)) !== []);
        });
    }

    /**
     * Profile-edit audit rows (instructor or admin) about this instructor, newer than $since. The
     * subject is the instructor or one of the instructor's applications.
     *
     * @return Collection<int, AuditLog>
     */
    private function profileEditsSince(int $instructorId, mixed $since): Collection
    {
        if ($since === null) {
            return collect();
        }

        return AuditLog::query()
            ->whereIn('action', ['edit_profile', 'admin_edit_profile'])
            ->where('created_at', '>', $since)
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->where('subject_type', (new Instructor)->getMorphClass())->where('subject_id', $instructorId))
                ->orWhere(fn ($s) => $s->where('subject_type', (new Application)->getMorphClass())
                    ->whereIn('subject_id', Application::query()->select('id')->where('instructor_id', $instructorId))))
            ->get(['id', 'action', 'details', 'created_at']);
    }

    public function plan(Application $application): ChecklistPlan
    {
        return $this->resolver->for($application->instructor);
    }

    /** Committee gate (spec 5b §5): every stage-1 item accepted, on file or exempted. */
    public function allRequiredAccepted(Application $application): bool
    {
        return $this->rowsAllAccepted($this->stageOneRows($application));
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function rowsAllAccepted(array $rows): bool
    {
        foreach ($rows as $row) {
            if (! in_array($row['state'], self::SATISFIED_STATES, true)) {
                return false;
            }
        }

        return true;
    }

    /** Submission gate (spec 5b §5): every stage-1 item uploaded, on file, exempted or exemption-requested. */
    public function allRequiredUploaded(Application $application): bool
    {
        foreach ($this->stageOneRows($application) as $row) {
            if ($row['state'] === 'missing' || $row['state'] === Document::STATUS_REJECTED) {
                return false;
            }
        }

        return true;
    }

    public function hasUndecidedExemptions(Application $application): bool
    {
        return $this->rowsHaveUndecidedExemptions($this->stageOneRows($application));
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function rowsHaveUndecidedExemptions(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['state'] === self::STATE_EXEMPTION_REQUESTED) {
                return true;
            }
        }

        return false;
    }

    /** Spec 5b §5: approved, every stage-2 item satisfied, both salary fields present. */
    public function stageTwoComplete(Application $application): bool
    {
        return $application->status === Application::STATUS_APPROVED && $this->stageTwoMissing($application) === [];
    }

    /** Labels of what still blocks stage 2 (documents by label, then the salary line). Empty when nothing is missing or the application is not approved. */
    public function stageTwoMissing(Application $application): array
    {
        if ($application->status !== Application::STATUS_APPROVED) {
            return [];
        }
        $missing = [];
        foreach ($this->stageTwoRows($application) as $row) {
            if (! in_array($row['state'], self::SATISFIED_STATES, true)) {
                $missing[] = $row['item']->label_ar;
            }
        }
        $i = $application->instructor;
        if ($i->basic_salary === null || $i->total_salary === null) {
            $missing[] = __('app.profile.salary_missing');
        }

        return $missing;
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
        $application = $document->application;
        $stageTwoReview = $application->status === Application::STATUS_APPROVED
            && ($document->checklistItem->isStageTwo() || $document->checklistItem->optional);
        if ($application->isFinal() && ! $stageTwoReview) {
            throw new \DomainException(__('app.review.already_final'));
        }
        if (! $document->application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if ($document->part !== 1) {
            $document = $document->parts()->first();
        }
        if (! $document->application->latestDocuments()->get($document->checklistItem->code)?->is($document)) {
            throw new \DomainException(__('app.review.superseded_version'));
        }

        Document::where([
            'application_id' => $document->application_id,
            'checklist_item_id' => $document->checklist_item_id,
            'version' => $document->version,
        ])->update([
            'status' => $status,
            'rejection_reason' => $status === Document::STATUS_REJECTED ? $reason : null,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);
        $document->refresh();
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
        return $row['state'] === Document::STATUS_REJECTED
            || ($row['renewal'] !== null && $row['document'] === null)
            || ($row['document'] === null && $row['exemption']?->status === ChecklistExemption::STATUS_REJECTED);
    }

    private function noticeSent(array $row): bool
    {
        if ($row['state'] === Document::STATUS_REJECTED) {
            return $row['document']->notified_at !== null;
        }
        if ($row['renewal'] !== null) {
            return $row['renewal']->notified_at !== null;
        }

        return $row['exemption']->notified_at !== null;
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
            $target = $row['state'] === Document::STATUS_REJECTED ? $row['document'] : ($row['renewal'] ?? $row['exemption']);
            $target->update(['notified_at' => now()]);
        }
        AuditLog::record($admin->id, 'notify_rejections', $application);
        $this->safeSend($application->instructor->user->email, new DocumentsRejected($application, $rows));

        return count($rows);
    }

    /** Spec 5b §6: the applicant asks to be exempted from an exemptable stage-1 item instead of uploading it. */
    public function requestExemption(Application $application, ChecklistItem $item, string $reason): void
    {
        $row = $this->checklist($application)[$item->code] ?? null;
        if ($row === null || $row['document'] !== null || in_array($row['state'], [self::STATE_EXEMPTION_REQUESTED, self::STATE_EXEMPTED], true)) {
            throw new \DomainException(__('app.exemptions.cannot_request'));
        }

        DB::transaction(function () use ($application, $item, $reason) {
            ChecklistExemption::updateOrCreate(
                ['application_id' => $application->id, 'checklist_item_id' => $item->id],
                ['reason' => $reason, 'requested_at' => now(), 'status' => ChecklistExemption::STATUS_PENDING,
                    'decided_by' => null, 'decided_at' => null, 'decision_note' => null, 'notified_at' => null],
            );
            AuditLog::record($application->instructor->user_id, 'request_exemption', $application, null, $item->code);
        });
    }

    /** Spec 5b §6: the admin accepts or rejects a pending exemption; a rejection sends the file back to the applicant. */
    public function decideExemption(ChecklistExemption $exemption, User $admin, string $status, ?string $note): void
    {
        $application = $exemption->application;
        if (! $application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if (! $exemption->isPending() || ! in_array($application->status, Application::REVIEWABLE_STATUSES, true)) {
            throw new \DomainException(__('app.exemptions.cannot_decide'));
        }

        DB::transaction(function () use ($exemption, $application, $admin, $status, $note) {
            $exemption->update(['status' => $status, 'decided_by' => $admin->id, 'decided_at' => now(),
                'decision_note' => $status === ChecklistExemption::STATUS_REJECTED ? $note : null, 'notified_at' => null]);
            AuditLog::record($admin->id, 'exemption_'.$status, $application, null, $exemption->item->code);
            if ($status === ChecklistExemption::STATUS_REJECTED) {
                $application->update(['status' => Application::STATUS_INCOMPLETE, 'complete_at' => null, 'reviewed_at' => $application->reviewed_at ?? now()]);
            }
        });
    }

    /** Spec §4.4: an admin demands a fresh copy of an on-file item. */
    public function requestFreshCopy(Application $application, ChecklistItem $item, User $admin, string $reason): void
    {
        if (! $application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        $approvedStageTwo = $application->status === Application::STATUS_APPROVED && $item->isStageTwo();
        if (! in_array($application->status, Application::UNFINISHED_STATUSES, true) && ! $approvedStageTwo) {
            throw new \DomainException(__('app.review.fresh_copy_wrong_status'));
        }
        $row = $this->checklist($application)[$item->code] ?? null;
        if (($row['state'] ?? null) !== self::STATE_ON_FILE) {
            throw new \DomainException(__('app.review.fresh_copy_wrong_state'));
        }

        DB::transaction(function () use ($application, $item, $admin, $reason, $approvedStageTwo) {
            ChecklistRenewal::updateOrCreate(
                ['application_id' => $application->id, 'checklist_item_id' => $item->id],
                ['reason' => $reason, 'requested_by' => $admin->id, 'requested_at' => now(), 'notified_at' => null],
            );
            if (! $approvedStageTwo) {
                $application->update(['status' => Application::STATUS_INCOMPLETE, 'complete_at' => null, 'reviewed_at' => $application->reviewed_at ?? now()]);
            }
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
        $rows = $this->stageOneRows($application);
        if (! $this->rowsAllAccepted($rows)) {
            throw new \DomainException($this->blockMessageForRows($rows));
        }
        $application->update(['status' => Application::STATUS_COMPLETE, 'complete_at' => now()]);
        AuditLog::record($admin->id, 'mark_complete', $application);
    }

    /** Spec 5b §5/§6: the message to show when the file cannot be marked complete yet. */
    public function completeBlockMessage(Application $application): string
    {
        return $this->blockMessageForRows($this->stageOneRows($application));
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function blockMessageForRows(array $rows): string
    {
        return $this->rowsHaveUndecidedExemptions($rows) && $this->rowsOnlyExemptionsBlock($rows)
            ? __('app.review.complete_blocked_exemptions') : __('app.review.complete_blocked');
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function rowsOnlyExemptionsBlock(array $rows): bool
    {
        foreach ($rows as $row) {
            if (! in_array($row['state'], [...self::SATISFIED_STATES, self::STATE_EXEMPTION_REQUESTED], true)) {
                return false;
            }
        }

        return true;
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
     * The term row is locked and both checks run under the lock, so a second concurrent close is
     * refused and the blocker check sees the statuses current at the UPDATE. submit() does not take
     * this lock (a submission committing in the same instant is not serialised against the close).
     */
    public function closeTerm(Term $term, User $admin): int
    {
        return DB::transaction(function () use ($term, $admin) {
            $locked = Term::whereKey($term->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new \DomainException(__('app.applications.term_closed'));
            }
            $blocking = $locked->applications()->whereIn('status', Application::UNFINISHED_STATUSES)->with('instructor')->get();
            if ($blocking->isNotEmpty()) {
                throw new TermCloseBlockedException($blocking);
            }

            $n = $locked->applications()->where('status', Application::STATUS_DRAFT)
                ->update(['status' => Application::STATUS_WITHDRAWN, 'decided_at' => now()]);
            $locked->update(['status' => Term::STATUS_CLOSED]);
            $term->setRawAttributes($locked->getAttributes(), true);
            AuditLog::record($admin->id, 'close_term', $locked, null, 'drafts_withdrawn='.$n);

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
