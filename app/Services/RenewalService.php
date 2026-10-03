<?php

namespace App\Services;

use App\Mail\RenewalApproved;
use App\Mail\RenewalRefused;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\CommitteeApproval;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** Spec M6 §5: the yearly renewal batch. */
class RenewalService
{
    public function __construct(private ApplicationWorkflow $workflow) {}

    /** Instructors approved for the previous year with no row for $year, by name, with `lastTerm` set. */
    public function candidates(string $year): Collection
    {
        $previous = CommitteeApproval::previousYear($year);

        return Instructor::query()
            ->whereHas('approvals', fn ($q) => $q->where('academic_year', $previous)->where('outcome', CommitteeApproval::OUTCOME_APPROVED))
            ->whereDoesntHave('approvals', fn ($q) => $q->where('academic_year', $year))
            ->with(['user', 'applications.term'])
            ->get()
            ->each(fn (Instructor $i) => $i->lastTerm = $i->applications->where('status', Application::STATUS_APPROVED)
                ->sortByDesc(fn ($a) => $a->term->teaching_starts_on)->first()?->term)
            ->sortBy('full_name')->values();
    }

    /**
     * @param  array<int, array{outcome: string, note?: ?string}>  $rows  instructor id => decision
     * @return array{renewed: int, refused: int}
     */
    public function record(string $year, array $rows, string $metOn, string $reference, User $admin): array
    {
        if ($rows === []) {
            throw new \DomainException(__('app.renewals.nothing_selected'));
        }
        $firstTerm = Term::where('academic_year', $year)->where('type', 'first')->first();
        if (! $firstTerm) {
            throw new \DomainException(__('app.renewals.no_first_term', ['year' => $year]));
        }
        if (! $firstTerm->isOpen()) {
            throw new \DomainException(__('app.renewals.first_term_closed', ['year' => $year]));
        }
        $candidates = $this->candidates($year)->keyBy('id');
        foreach (array_keys($rows) as $id) {
            if (! $candidates->has($id)) {
                // Already has a row for this year: a resubmit (back button, double click after
                // the first commit), not an instructor outside the renewal pool.
                if (Instructor::find($id)?->approvalFor($year) !== null) {
                    throw new \DomainException(__('app.renewals.already_recorded'));
                }
                throw new \DomainException(__('app.renewals.not_a_candidate'));
            }
        }

        $mails = [];
        $counts = ['renewed' => 0, 'refused' => 0];
        DB::transaction(function () use ($year, $rows, $metOn, $reference, $admin, $firstTerm, $candidates, &$mails, &$counts) {
            foreach ($rows as $id => $decision) {
                $instructor = $candidates[$id];
                $renewed = $decision['outcome'] === 'renewed';
                $approval = CommitteeApproval::create([
                    'instructor_id' => $instructor->id, 'academic_year' => $year, 'kind' => CommitteeApproval::KIND_RENEWAL,
                    'outcome' => $renewed ? CommitteeApproval::OUTCOME_APPROVED : CommitteeApproval::OUTCOME_NOT_RENEWED,
                    'committee_met_on' => $metOn, 'committee_reference' => $reference,
                    'note' => $decision['note'] ?? null, 'decided_by' => $admin->id,
                ]);
                if ($renewed) {
                    $application = Application::where('term_id', $firstTerm->id)->where('instructor_id', $instructor->id)->first();
                    if ($application === null) {
                        $application = Application::create([
                            'term_id' => $firstTerm->id, 'instructor_id' => $instructor->id,
                            'status' => Application::STATUS_DRAFT, 'kind' => Application::KIND_CONTINUATION, 'approval_id' => $approval->id,
                        ]);
                    }
                    // The instructor may already have clicked "start" on this or another term of
                    // the target year before the batch ran, leaving one or more initial
                    // applications behind; convert all of them to continuations of this approval
                    // (spec M6 §5.2, replacing the old "convert only the first-term draft" logic).
                    $this->workflow->convertToContinuations($instructor, $approval);
                    $application = $application->fresh();
                    $mails[] = [$instructor->user->email, new RenewalApproved($instructor, $approval, $application)];
                    $counts['renewed']++;
                } else {
                    $mails[] = [$instructor->user->email, new RenewalRefused($instructor, $approval)];
                    $counts['refused']++;
                }
                AuditLog::record($admin->id, $renewed ? 'renewal_approved' : 'renewal_refused', $instructor, null, $year);
            }
        });
        foreach ($mails as [$to, $mail]) {
            $this->safeSend($to, $mail);
        }

        return $counts;
    }

    public function delete(CommitteeApproval $approval, User $admin): void
    {
        if ($approval->kind !== CommitteeApproval::KIND_RENEWAL
            || $approval->applications()->where('status', '!=', Application::STATUS_DRAFT)->exists()) {
            throw new \DomainException(__('app.renewals.cannot_delete'));
        }
        DB::transaction(function () use ($approval, $admin) {
            foreach ($approval->applications()->where('status', Application::STATUS_DRAFT)->get() as $application) {
                $this->deleteApplicationFiles($application);
            }
            AuditLog::record($admin->id, 'delete_renewal', $approval->instructor, null, $approval->academic_year);
            $approval->delete();
        });
    }

    /** Deletes the application row (its documents cascade), then the now-orphaned files on disk. */
    private function deleteApplicationFiles(Application $application): void
    {
        $paths = $application->documents()->pluck('path')->all();
        $id = $application->id;
        $application->delete();
        if ($paths !== []) {
            Storage::disk('local')->delete($paths);
        }
        Storage::disk('local')->deleteDirectory("applications/{$id}");
    }

    private function safeSend(string $to, Mailable $mail): void
    {
        try {
            Mail::to($to)->send($mail);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
