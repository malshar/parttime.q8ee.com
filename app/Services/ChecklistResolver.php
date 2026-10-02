<?php

namespace App\Services;

use App\Models\ChecklistItem;
use App\Models\Instructor;

class ChecklistResolver
{
    public function for(Instructor $instructor): ChecklistPlan
    {
        $items = ChecklistItem::orderBy('sort_order')->get();

        $applicable = $items->filter(fn ($i) => ! $i->isDepartment() && $i->appliesTo($instructor));

        return new ChecklistPlan(
            required: $applicable->reject(fn ($i) => $i->optional)->values(),
            department: $items->filter(fn ($i) => $i->isDepartment())->values(),
            notApplicable: $items->filter(fn ($i) => ! $i->isDepartment() && ! $i->appliesTo($instructor))->values(),
            optional: $applicable->filter(fn ($i) => $i->optional)->values(),
        );
    }
}
