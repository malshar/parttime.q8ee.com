<?php

namespace App\Services;

use App\Models\ChecklistItem;
use App\Models\Instructor;

class ChecklistResolver
{
    public function for(Instructor $instructor): ChecklistPlan
    {
        $items = ChecklistItem::orderBy('sort_order')->get();

        return new ChecklistPlan(
            required: $items->filter(fn ($i) => ! $i->isDepartment() && $i->appliesTo($instructor))->values(),
            department: $items->filter(fn ($i) => $i->isDepartment())->values(),
            notApplicable: $items->filter(fn ($i) => ! $i->isDepartment() && ! $i->appliesTo($instructor))->values(),
        );
    }
}
