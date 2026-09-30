<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChecklistRenewalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'application_id' => Application::factory()->submitted(),
            'checklist_item_id' => fn () => ChecklistItem::where('code', 'degree')->value('id'),
            'reason' => 'يرجى إرفاق نسخة أوضح', 'requested_at' => now(),
        ];
    }
}
