<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ChecklistExemption;
use App\Models\ChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChecklistExemptionFactory extends Factory
{
    protected $model = ChecklistExemption::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'checklist_item_id' => fn () => ChecklistItem::where('code', 'transcript_bachelor')->value('id'),
            'reason' => 'الجامعة لا تصدر كشف درجات للخريجين القدامى',
            'requested_at' => now(), 'status' => ChecklistExemption::STATUS_PENDING,
        ];
    }

    public function forItem(string $code): static
    {
        return $this->state(fn () => ['checklist_item_id' => ChecklistItem::where('code', $code)->value('id')]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['status' => ChecklistExemption::STATUS_ACCEPTED, 'decided_at' => now()]);
    }

    public function rejected(string $note = 'يمكن طلب الكشف من الجامعة'): static
    {
        return $this->state(fn () => ['status' => ChecklistExemption::STATUS_REJECTED, 'decided_at' => now(), 'decision_note' => $note]);
    }
}
