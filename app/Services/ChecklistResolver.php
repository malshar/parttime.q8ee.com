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
        $required = $applicable->reject(fn ($i) => $i->optional);

        return new ChecklistPlan(
            required: $required->sortBy([['stage', 'asc'], ['sort_order', 'asc']])->values(),
            department: $items->filter(fn ($i) => $i->isDepartment())->values(),
            notApplicable: $items->filter(fn ($i) => ! $i->isDepartment() && ! $i->appliesTo($instructor))->values(),
            optional: $applicable->filter(fn ($i) => $i->optional)->values(),
            stage1: $required->where('stage', ChecklistItem::STAGE_COMMITTEE)->values(),
            stage2: $required->where('stage', ChecklistItem::STAGE_AFTER_APPROVAL)->values(),
        );
    }
}
