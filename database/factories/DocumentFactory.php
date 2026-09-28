<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'checklist_item_id' => fn () => ChecklistItem::where('code', 'civil_id')->value('id'),
            'path' => 'applications/0/'.$this->faker->uuid().'.pdf',
            'original_name' => 'file.pdf', 'mime' => 'application/pdf', 'size' => 1000,
            'status' => Document::STATUS_PENDING, 'version' => 1,
        ];
    }

    public function forItem(string $code): static
    {
        return $this->state(fn () => ['checklist_item_id' => ChecklistItem::where('code', $code)->value('id')]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['status' => Document::STATUS_ACCEPTED, 'reviewed_at' => now()]);
    }

    public function rejected(string $reason = 'غير واضح'): static
    {
        return $this->state(fn () => ['status' => Document::STATUS_REJECTED, 'rejection_reason' => $reason, 'reviewed_at' => now()]);
    }
}
