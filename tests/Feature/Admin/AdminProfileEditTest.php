<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Instructor\ProfileTest;
use Tests\TestCase;

class AdminProfileEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create();
        $this->application = Application::factory()->approved()->for(Term::factory()->open())->for($instructor)->create();
    }

    public function test_opening_edit_form_is_audited_as_reveal(): void
    {
        $this->actingAs($this->admin)->get(route('admin.applications.profile.edit', $this->application))->assertOk();

        $this->assertDatabaseHas('audit_log', [
            'user_id' => $this->admin->id,
            'action' => 'reveal_sensitive',
            'subject_type' => $this->application->getMorphClass(),
            'subject_id' => $this->application->id,
            'details' => 'profile_edit_form',
        ]);
    }

    public function test_admin_updates_profile_and_audit_lists_changed_fields(): void
    {
        $payload = ProfileTest::payload(['civil_id' => $this->application->instructor->civil_id, 'job_title' => 'مهندس أول', 'mobile' => '99887766']);

        $this->actingAs($this->admin)->put(route('admin.applications.profile.update', $this->application), $payload)
            ->assertRedirect(route('admin.applications.show', $this->application));

        $i = $this->application->instructor->fresh();
        $this->assertSame('مهندس أول', $i->job_title);
        $this->assertSame('99887766', $i->mobile);
        $this->assertDatabaseHas('audit_log', ['action' => 'admin_edit_profile', 'subject_id' => $i->id]);

        $row = AuditLog::where('action', 'admin_edit_profile')->where('subject_id', $i->id)->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('job_title', $row->details);
        $this->assertStringContainsString('mobile', $row->details);
        $this->assertStringNotContainsString('99887766', $row->details);
        $this->assertStringNotContainsString('iban', $row->details);
        $this->assertFalse(in_array('civil_id', explode(',', $row->details), true));
    }

    public function test_works_even_when_profile_is_locked_for_the_instructor(): void
    {
        $this->assertTrue($this->application->instructor->hasLockedApplication());
        $payload = ProfileTest::payload(['civil_id' => $this->application->instructor->civil_id, 'employer_choice' => 'وزارة الدفاع']);
        $this->actingAs($this->admin)->put(route('admin.applications.profile.update', $this->application), $payload)->assertSessionHasNoErrors();
        $this->assertSame('وزارة الدفاع', $this->application->instructor->fresh()->employer);
    }

    public function test_civil_id_uniqueness_is_checked_against_the_edited_instructor(): void
    {
        $other = Instructor::factory()->for(User::factory()->instructor())->create();
        $payload = ProfileTest::payload(['civil_id' => $other->civil_id]);
        $this->actingAs($this->admin)->put(route('admin.applications.profile.update', $this->application), $payload)->assertSessionHasErrors('civil_id');

        $own = ProfileTest::payload(['civil_id' => $this->application->instructor->civil_id]);
        $this->actingAs($this->admin)->put(route('admin.applications.profile.update', $this->application), $own)->assertSessionHasNoErrors();
    }

    public function test_instructor_cannot_use_admin_profile_routes(): void
    {
        $this->actingAs($this->application->instructor->user)->get(route('admin.applications.profile.edit', $this->application))->assertForbidden();
    }

    public function test_sensitive_fields_are_not_flashed_on_validation_failure(): void
    {
        $payload = ProfileTest::payload(['civil_id' => $this->application->instructor->civil_id, 'mobile' => 'bad']);
        $r = $this->actingAs($this->admin)->put(route('admin.applications.profile.update', $this->application), $payload);
        $r->assertSessionHasErrors('mobile');
        $r->assertSessionMissing('_old_input.civil_id');
        $r->assertSessionMissing('_old_input.iban');
    }

    public function test_admin_form_shows_the_employer_bank_nationality_selects_and_maps_an_agency_choice(): void
    {
        $r = $this->actingAs($this->admin)->get(route('admin.applications.profile.edit', $this->application))->assertOk();
        $r->assertSee('name="employer_choice"', false);
        $r->assertSee('name="bank_choice"', false);
        $r->assertSee('name="nationality"', false);

        $payload = ProfileTest::payload([
            'civil_id' => $this->application->instructor->civil_id,
            'employer_choice' => 'وزارة العدل', 'employer_other' => '',
            'bank_choice' => 'WRBA', 'bank_other' => '',
        ]);
        $this->actingAs($this->admin)->put(route('admin.applications.profile.update', $this->application), $payload)
            ->assertSessionHasNoErrors();

        $i = $this->application->instructor->fresh();
        $this->assertSame('وزارة العدل', $i->employer);
        $this->assertSame('government', $i->employer_sector);
        $this->assertSame('بنك وربة', $i->bank_name);
    }
}
