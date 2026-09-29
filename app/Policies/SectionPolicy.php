<?php

namespace App\Policies;

use App\Models\Section;
use App\Models\User;

/** Sections, their import and their assignments are admin-only (spec §6), on top of the role:admin middleware. */
class SectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function import(User $user): bool
    {
        return $user->isAdmin();
    }

    public function assign(User $user, Section $section): bool
    {
        return $user->isAdmin();
    }

    public function unassign(User $user, Section $section): bool
    {
        return $user->isAdmin();
    }
}
