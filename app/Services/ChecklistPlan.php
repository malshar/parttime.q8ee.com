<?php

namespace App\Services;

use Illuminate\Support\Collection;

final class ChecklistPlan
{
    public function __construct(
        public readonly Collection $required,
        public readonly Collection $department,
        public readonly Collection $notApplicable,
        public readonly Collection $optional,
        public readonly Collection $stage1,
        public readonly Collection $stage2,
    ) {}

    public function isRequired(string $code): bool
    {
        return $this->required->contains('code', $code);
    }

    /** Required or optional: the applicant may upload it. */
    public function isUploadable(string $code): bool
    {
        return $this->isRequired($code) || $this->optional->contains('code', $code);
    }
}
