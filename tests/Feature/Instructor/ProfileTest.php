<?php

namespace Tests\Feature\Instructor;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Unit\CivilIdRuleTest;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public static function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'محمد أحمد علي الفهد',
            'civil_id' => CivilIdRuleTest::withCheckDigit('29001011234'),
            'civil_id_expires_on' => '2028-01-01',
            'nationality' => 'كويتي',
            'mobile' => '99001122',
            'employer' => 'وزارة الكهرباء والماء',
            'employer_sector' => 'government',
            'job_title' => 'مهندس كهربائي',
            'highest_degree' => 'master',
            'degree_title' => 'ماجستير هندسة كهربائية',
            'degree_country' => 'KW',
            'degree_obtained_on' => '2018-06-01',
            'bank_name' => 'بنك الكويت الوطني',
            'bank_branch' => 'الرميثية',
            'iban' => 'KW81CBKU0000000000001234560101',
            'basic_salary' => '1200',
            'total_salary' => '1650',
        ], $overrides);
    }

    public function test_instructor_creates_profile_with_encrypted_and_hashed_fields(): void
    {
        $user = User::factory()->instructor()->create();

        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload())
            ->assertRedirect(route('instructor.home'));

        $instructor = $user->fresh()->instructor;
        $this->assertSame(self::payload()['civil_id'], $instructor->civil_id);
        $this->assertSame('KW81CBKU0000000000001234560101', $instructor->iban);
        $raw = DB::table('instructors')->where('id', $instructor->id)->first();
        $this->assertNotSame(self::payload()['civil_id'], $raw->civil_id);
        $this->assertNotSame('KW81CBKU0000000000001234560101', $raw->iban);
        $this->assertSame(Instructor::hashCivilId(self::payload()['civil_id']), $raw->civil_id_hash);
        $this->assertSame('290*****'.substr(self::payload()['civil_id'], -3), $instructor->maskedCivilId());
    }

    public function test_civil_id_must_be_unique_across_instructors(): void
    {
        Instructor::factory()->for(User::factory()->instructor())->create(['civil_id' => self::payload()['civil_id']]);
        $user = User::factory()->instructor()->create();

        $this->actingAs($user)->from(route('instructor.profile.edit'))
            ->put(route('instructor.profile.update'), self::payload())
            ->assertSessionHasErrors('civil_id');
    }

    public function test_experience_years_required_for_bachelor(): void
    {
        $user = User::factory()->instructor()->create();

        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload(['highest_degree' => 'bachelor']))
            ->assertSessionHasErrors('experience_years');

        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload(['highest_degree' => 'bachelor', 'experience_years' => 12]))
            ->assertSessionHasNoErrors();
    }

    public function test_unverified_user_cannot_edit_profile(): void
    {
        $user = User::factory()->instructor()->unverified()->create();

        $this->actingAs($user)->get(route('instructor.profile.edit'))->assertRedirect(route('verification.notice'));
    }

    public function test_sensitive_fields_are_not_flashed_on_validation_failure(): void
    {
        $user = User::factory()->instructor()->create();

        $this->actingAs($user)
            ->put(route('instructor.profile.update'), self::payload(['mobile' => 'not-a-number']))
            ->assertSessionHasErrors('mobile');

        $oldInput = session('_old_input', []);
        $this->assertArrayHasKey('mobile', $oldInput);
        $this->assertArrayNotHasKey('civil_id', $oldInput);
        $this->assertArrayNotHasKey('iban', $oldInput);
        $this->assertArrayNotHasKey('basic_salary', $oldInput);
        $this->assertArrayNotHasKey('total_salary', $oldInput);
    }

    public function test_profile_locked_while_application_under_review(): void
    {
        $user = User::factory()->instructor()->create();
        $instructor = Instructor::factory()->for($user)->create(['full_name' => 'الاسم الأصلي']);
        Application::factory()->submitted()->for(Term::factory()->open())->for($instructor)->create();

        $this->actingAs($user)->get(route('instructor.profile.edit'))->assertOk()
            ->assertSee(__('app.profile.locked'))
            ->assertDontSee('>'.__('app.common.save').'</button>', false);

        $this->actingAs($user)->from(route('instructor.profile.edit'))
            ->put(route('instructor.profile.update'), self::payload(['full_name' => 'اسم جديد']))
            ->assertRedirect(route('instructor.profile.edit'))
            ->assertSessionHasErrors(['profile' => __('app.profile.locked')]);

        $this->assertSame('الاسم الأصلي', $instructor->fresh()->full_name);
    }

    public function test_profile_locked_while_application_complete(): void
    {
        $user = User::factory()->instructor()->create();
        $instructor = Instructor::factory()->for($user)->create(['full_name' => 'الاسم الأصلي']);
        Application::factory()->for(Term::factory()->open())->for($instructor)->create(['status' => Application::STATUS_COMPLETE]);

        $this->actingAs($user)->get(route('instructor.profile.edit'))->assertOk()
            ->assertSee(__('app.profile.locked'));

        $this->actingAs($user)->from(route('instructor.profile.edit'))
            ->put(route('instructor.profile.update'), self::payload(['full_name' => 'اسم جديد']))
            ->assertRedirect(route('instructor.profile.edit'))
            ->assertSessionHasErrors(['profile' => __('app.profile.locked')]);

        $this->assertSame('الاسم الأصلي', $instructor->fresh()->full_name);
    }

    public function test_profile_editable_while_application_incomplete(): void
    {
        $user = User::factory()->instructor()->create();
        $instructor = Instructor::factory()->for($user)->create(['full_name' => 'الاسم الأصلي']);
        Application::factory()->for(Term::factory()->open())->for($instructor)->create(['status' => Application::STATUS_INCOMPLETE]);

        $this->actingAs($user)->get(route('instructor.profile.edit'))->assertOk()
            ->assertDontSee(__('app.profile.locked'))
            ->assertSee('>'.__('app.common.save').'</button>', false);

        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload(['full_name' => 'اسم جديد']))
            ->assertRedirect(route('instructor.home'))
            ->assertSessionHasNoErrors();

        $this->assertSame('اسم جديد', $instructor->fresh()->full_name);
    }

    public function test_profile_update_audits_changed_field_names_only(): void
    {
        $user = User::factory()->instructor()->create();
        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload())->assertSessionHasNoErrors();
        $before = AuditLog::where('action', 'edit_profile')->count();

        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload([
            'mobile' => '99887766', 'iban' => 'KW16NBOK0000000000001234560101', 'bank_name' => 'بنك الخليج',
        ]))->assertSessionHasNoErrors();

        $rows = AuditLog::where('action', 'edit_profile')->orderBy('id')->get()->slice($before);
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $instructor = $user->fresh()->instructor;
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame($instructor->getMorphClass(), $row->subject_type);
        $this->assertSame($instructor->id, $row->subject_id);
        $this->assertSame('bank_name,iban,mobile', $row->details);
        $this->assertStringNotContainsString('99887766', $row->details);
        $this->assertStringNotContainsString('KW16', $row->details);
    }

    public function test_profile_update_without_changes_writes_no_audit_row(): void
    {
        $user = User::factory()->instructor()->create();
        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload())->assertSessionHasNoErrors();
        $before = AuditLog::where('action', 'edit_profile')->count();

        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload())->assertSessionHasNoErrors();

        $this->assertSame($before, AuditLog::where('action', 'edit_profile')->count());
    }
}
