<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\Document;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_items_carry_stage_exemptable_and_official_flags(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $this->seed(ChecklistItemSeeder::class); // idempotent
        $this->assertDatabaseCount('checklist_items', 14);

        $expected = [
            'schedule' => [0, false, true], 'assignment_letter' => [0, false, true], 'attestation' => [0, false, true],
            'civil_id' => [1, false, true], 'degree' => [1, false, true],
            'transcript_bachelor' => [1, true, false], 'transcript_master' => [1, true, false],
            'equivalency' => [1, false, true], 'social_insurance' => [2, false, true], 'experience' => [1, false, true],
            'salary_cert' => [2, false, true], 'iban' => [2, false, true], 'employer_approval' => [2, false, true], 'undertaking' => [2, false, true],
        ];
        foreach ($expected as $code => [$stage, $exemptable, $official]) {
            $item = ChecklistItem::where('code', $code)->firstOrFail();
            $this->assertSame($stage, (int) $item->stage, $code);
            $this->assertSame($exemptable, (bool) $item->exemptable, $code);
            $this->assertSame($official, (bool) $item->official, $code);
            $this->assertFalse((bool) $item->optional, "$code must not be optional any more");
        }
        $this->assertSame(['civil_id', 'degree', 'transcript_bachelor', 'transcript_master', 'equivalency', 'experience'],
            ChecklistItem::where('stage', 1)->orderBy('sort_order')->pluck('code')->all());
        $this->assertSame(['social_insurance', 'salary_cert', 'iban', 'employer_approval', 'undertaking'],
            ChecklistItem::where('stage', 2)->orderBy('sort_order')->pluck('code')->all());
    }

    public function test_documents_unique_key_includes_part(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $app = Application::factory()->create();
        Document::factory()->for($app)->forItem('degree')->create(['version' => 1, 'part' => 1]);
        Document::factory()->for($app)->forItem('degree')->create(['version' => 1, 'part' => 2]);
        $this->assertDatabaseCount('documents', 2);
    }
}
