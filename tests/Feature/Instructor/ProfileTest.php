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

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->instructor()->create();
        Instructor::factory()->for($this->user)->create();
    }

    public static function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'محمد أحمد علي الفهد',
            'civil_id' => CivilIdRuleTest::withCheckDigit('29001011234'),
            'civil_id_expires_on' => '2028-01-01',
            'nationality' => 'KW',
            'mobile' => '99001122',
            'employer_choice' => 'وزارة الكهرباء والماء والطاقة المتجددة',
            'employer_other' => '',
            'employer_sector' => '',
            'job_title' => 'مهندس كهربائي',
            'highest_degree' => 'master',
            'degree_title' => 'ماجستير هندسة كهربائية',
            'degree_country' => 'KW',
            'degree_obtained_on' => '2018-06-01',
            'bank_choice' => 'NBOK',
            'bank_other' => '',
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
            'mobile' => '99887766', 'iban' => 'KW16NBOK0000000000001234560101', 'bank_choice' => 'GULB',
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

    public function test_agency_choice_sets_employer_and_government_sector(): void
    {
        $r = $this->actingAs($this->user)->put(route('instructor.profile.update'), self::payload([
            'employer_choice' => 'وزارة الصحة', 'employer_other' => '', 'nationality' => 'KW',
            'bank_choice' => 'NBOK', 'bank_other' => '',
        ]));
        $r->assertSessionHasNoErrors();
        $i = $this->user->instructor->fresh();
        $this->assertSame('وزارة الصحة', $i->employer);
        $this->assertSame('government', $i->employer_sector);
        $this->assertSame('بنك الكويت الوطني', $i->bank_name);
        $this->assertSame('KW', $i->nationality);
        $this->assertSame(__('app.countries.KW'), $i->nationalityLabel());
    }

    public function test_private_and_other_choices_require_a_name_and_keep_the_sector(): void
    {
        $this->actingAs($this->user)->put(route('instructor.profile.update'), self::payload([
            'employer_choice' => 'private', 'employer_other' => '', 'bank_choice' => 'other', 'bank_other' => '',
        ]))->assertSessionHasErrors(['employer_other', 'bank_other']);

        $this->actingAs($this->user)->put(route('instructor.profile.update'), self::payload([
            'employer_choice' => 'private', 'employer_other' => 'شركة الخليج للكابلات', 'bank_choice' => 'other', 'bank_other' => 'بنك آخر',
        ]))->assertSessionHasNoErrors();
        $i = $this->user->instructor->fresh();
        $this->assertSame('شركة الخليج للكابلات', $i->employer);
        $this->assertSame('private', $i->employer_sector);
        $this->assertSame('بنك آخر', $i->bank_name);

        $this->actingAs($this->user)->put(route('instructor.profile.update'), self::payload([
            'employer_choice' => 'other', 'employer_other' => 'جمعية تعاونية', 'employer_sector' => 'government',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('government', $this->user->instructor->fresh()->employer_sector);
    }

    public function test_legacy_values_preselect_other_and_survive_an_untouched_save(): void
    {
        $this->actingAs($this->user)->put(route('instructor.profile.update'), self::payload())->assertSessionHasNoErrors();
        $this->user->instructor->refresh()->update(['nationality' => 'كويتي', 'employer' => 'شركة قديمة', 'employer_sector' => 'private', 'bank_name' => 'بنك قديم']);

        $r = $this->actingAs($this->user)->get(route('instructor.profile.edit'))->assertOk();
        $r->assertSee('<option value="__keep" selected>كويتي</option>', false);
        $r->assertSee('<option value="private" selected', false);
        $r->assertSee('<option value="other" selected', false);
        $r->assertSee('value="شركة قديمة"', false);
        $r->assertSee('value="بنك قديم"', false);
        $r->assertDontSee('<option value="ZZ" selected', false);
        $this->assertSame('كويتي', $this->user->instructor->fresh()->nationalityLabel());

        // The bank select belongs with the branch/IBAN fields in the bank card, not the work card.
        $html = $r->getContent();
        $workHeading = strpos($html, __('app.profile.work'));
        $bankSelect = strpos($html, 'name="bank_choice"');
        $iban = strpos($html, 'name="iban"');
        $this->assertNotFalse($workHeading);
        $this->assertNotFalse($bankSelect);
        $this->assertNotFalse($iban);
        $this->assertTrue($workHeading < $bankSelect && $bankSelect < $iban);

        // Submit what the untouched form carries, with only the mobile changed.
        $before = AuditLog::where('action', 'edit_profile')->max('id');
        $this->actingAs($this->user)->put(route('instructor.profile.update'), self::payload([
            'mobile' => '99887766', 'nationality' => '__keep',
            'employer_choice' => 'private', 'employer_other' => 'شركة قديمة',
            'bank_choice' => 'other', 'bank_other' => 'بنك قديم',
        ]))->assertSessionHasNoErrors();

        $i = $this->user->instructor->fresh();
        $this->assertSame('كويتي', $i->nationality);
        $this->assertSame('شركة قديمة', $i->employer);
        $this->assertSame('private', $i->employer_sector);
        $this->assertSame('بنك قديم', $i->bank_name);
        $this->assertSame('99887766', $i->mobile);
        $rows = AuditLog::where('action', 'edit_profile')->where('id', '>', $before)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('mobile', $rows->first()->details);
    }

    public function test_new_profile_starts_with_an_empty_nationality_choice(): void
    {
        $user = User::factory()->instructor()->create();

        $r = $this->actingAs($user)->get(route('instructor.profile.edit'))->assertOk();
        $r->assertSee('<option value="" selected>'.__('app.common.choose').'</option>', false);
        $r->assertDontSee('<option value="ZZ" selected', false);
        $r->assertDontSee('value="__keep"', false);

        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload(['nationality' => '']))
            ->assertSessionHasErrors('nationality');
        $this->assertNull($user->fresh()->instructor);
    }

    public function test_keep_is_rejected_when_the_stored_nationality_is_already_a_code(): void
    {
        $this->user->instructor->update(['nationality' => 'KW']);

        $this->actingAs($this->user)->put(route('instructor.profile.update'), self::payload(['nationality' => '__keep']))
            ->assertSessionHasErrors('nationality');
        $this->assertSame('KW', $this->user->instructor->fresh()->nationality);

        // A new profile has nothing to keep either.
        $user = User::factory()->instructor()->create();
        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload(['nationality' => '__keep']))
            ->assertSessionHasErrors('nationality');
    }

    public function test_country_lists_cover_regional_nationalities_on_both_selects(): void
    {
        $r = $this->actingAs($this->user)->get(route('instructor.profile.edit'))->assertOk();
        $html = $r->getContent();
        foreach (['SY', 'IQ', 'XB', 'ZZ'] as $code) {
            $this->assertSame(2, substr_count($html, '<option value="'.$code.'"'), $code);
        }

        $this->actingAs($this->user)->put(route('instructor.profile.update'), self::payload(['nationality' => 'XB', 'degree_country' => 'SY']))
            ->assertSessionHasNoErrors();
        $this->assertSame(__('app.countries.XB'), $this->user->instructor->fresh()->nationalityLabel());
    }

    public function test_degree_country_must_be_in_the_list(): void
    {
        $this->actingAs($this->user)->put(route('instructor.profile.update'), self::payload(['degree_country' => 'ZQ']))
            ->assertSessionHasErrors('degree_country');

        $this->actingAs($this->user)->put(route('instructor.profile.update'), self::payload(['degree_country' => 'KW']))
            ->assertSessionHasNoErrors();
    }

    public function test_validation_errors_use_the_translated_field_names(): void
    {
        $this->actingAs($this->user)->put(route('instructor.profile.update'), self::payload([
            'employer_choice' => 'private', 'employer_other' => '',
        ]))->assertSessionHasErrors('employer_other');

        $message = session('errors')->first('employer_other');
        $this->assertStringContainsString(__('app.profile.employer_name'), $message);
        $this->assertStringNotContainsString('employer other', $message);
        $this->assertStringNotContainsString('employer_other', $message);
    }

    public function test_unknown_employer_and_bank_choices_fail_with_the_other_errors(): void
    {
        $this->actingAs($this->user)->put(route('instructor.profile.update'), self::payload([
            'employer_choice' => 'جهة مزورة', 'bank_choice' => 'ZZZZ', 'mobile' => '12',
        ]))->assertSessionHasErrors(['employer_choice', 'bank_choice', 'mobile']);
    }

    public function test_the_bank_script_is_pushed_after_the_iban_input(): void
    {
        $html = $this->actingAs($this->user)->get(route('instructor.profile.edit'))->assertOk()->getContent();
        $iban = strpos($html, '<input name="iban"');
        $script = strpos($html, 'querySelector(\'input[name="iban"]\')');
        $this->assertNotFalse($iban);
        $this->assertNotFalse($script);
        $this->assertLessThan($script, $iban);
    }

    public function test_layout_loads_bootstrap_js_with_sri_and_a_wrapping_nav(): void
    {
        $r = $this->actingAs($this->user)->get(route('instructor.profile.edit'))->assertOk();
        $r->assertSee('bootstrap.bundle.min.js"'."\n".'        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"', false);
        $r->assertSee('<div class="d-flex flex-wrap align-items-center gap-2 nav-actions">', false);
    }

    public function test_non_sensitive_values_are_retained_after_a_validation_error(): void
    {
        $r = $this->actingAs($this->user)->from(route('instructor.profile.edit'))->put(route('instructor.profile.update'), self::payload([
            'mobile' => '12', 'full_name' => 'اسم للاختبار', 'employer_choice' => 'وزارة العدل', 'bank_choice' => 'WRBA', 'nationality' => 'SA',
        ]))->assertRedirect(route('instructor.profile.edit'))->assertSessionHasErrors('mobile');

        $page = $this->actingAs($this->user)->get(route('instructor.profile.edit'));
        $page->assertSee('value="اسم للاختبار"', false);
        $page->assertSee('<option value="وزارة العدل" selected', false);
        $page->assertSee('<option value="WRBA" selected', false);
        $page->assertSee('<option value="SA" selected', false);
        $page->assertDontSee($this->user->instructor->iban);
        $page->assertSee(__('app.profile.sensitive_reenter'));
    }

    public function test_unknown_iban_bank_code_does_not_block_saving(): void
    {
        $this->actingAs($this->user)->put(route('instructor.profile.update'), self::payload([
            'iban' => 'KW40ZZZZ0000000000001234560101', 'bank_choice' => 'GULB',
        ]))->assertSessionDoesntHaveErrors(['bank_choice', 'iban']);
    }
}
