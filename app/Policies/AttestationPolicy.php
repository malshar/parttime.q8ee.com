<?php

namespace App\Policies;

use App\Models\Attestation;
use App\Models\User;

/** Attestations are admin-only (spec §7), on top of the role:admin middleware; state rules live in AttestationService. */
class AttestationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function generate(User $user): bool
    {
        return $user->isAdmin();
    }

    public function exportAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Attestation $attestation): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Attestation $attestation): bool
    {
        return $user->isAdmin();
    }

    public function regenerate(User $user, Attestation $attestation): bool
    {
        return $user->isAdmin();
    }

    public function export(User $user, Attestation $attestation): bool
    {
        return $user->isAdmin();
    }

    public function unlock(User $user, Attestation $attestation): bool
    {
        return $user->isAdmin();
    }
}
