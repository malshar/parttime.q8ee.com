<?php

namespace App\Services;

use Illuminate\Support\Collection;

final class ChecklistPlan
{
    public function __construct(
        public readonly Collection $required,
        public readonly Collection $department,
        public readonly Collection $notApplicable,
    ) {}

    public function isRequired(string $code): bool
    {
        return $this->required->contains('code', $code);
    }
}
