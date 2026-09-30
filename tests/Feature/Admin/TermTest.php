<?php

namespace Tests\Feature\Admin;

use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_admin_creates_term_with_holidays(): void
    {
        $this->actingAs($this->admin())->post(route('admin.terms.store'), [
            'academic_year' => '2026-2027', 'type' => 'first',
            'teaching_starts_on' => '2026-09-13', 'teaching_ends_on' => '2026-12-24',
            'holidays' => "2026-09-24|اليوم الوطني\n2026-11-15|عطلة تجريبية",
        ])->assertRedirect(route('admin.terms.index'));

        $term = Term::firstOrFail();
        $this->assertSame('open', $term->status);
        $this->assertCount(2, $term->holidays);
        $this->assertSame('اليوم الوطني', $term->holidays->first(fn ($h) => $h->date->toDateString() === '2026-09-24')->name);
    }

    public function test_only_one_open_term_at_a_time(): void
    {
        Term::factory()->open()->create(['academic_year' => '2025-2026', 'type' => 'summer']);

        $this->actingAs($this->admin())->from(route('admin.terms.create'))->post(route('admin.terms.store'), [
            'academic_year' => '2026-2027', 'type' => 'first',
            'teaching_starts_on' => '2026-09-13', 'teaching_ends_on' => '2026-12-24',
        ])->assertRedirect(route('admin.terms.create'))->assertSessionHasErrors('academic_year');
    }

    public function test_duplicate_year_and_type_rejected(): void
    {
        Term::factory()->create(['academic_year' => '2026-2027', 'type' => 'first', 'status' => 'closed']);

        $this->actingAs($this->admin())->post(route('admin.terms.store'), [
            'academic_year' => '2026-2027', 'type' => 'first',
            'teaching_starts_on' => '2026-09-13', 'teaching_ends_on' => '2026-12-24',
        ])->assertSessionHasErrors('type');
    }

    public function test_close_term_and_current_helper(): void
    {
        $term = Term::factory()->open()->create();
        $this->assertTrue($term->is(Term::current()));

        $this->actingAs($this->admin())->post(route('admin.terms.close', $term))->assertRedirect();

        $this->assertSame('closed', $term->fresh()->status);
        $this->assertNull(Term::current());
    }

    public function test_instructor_cannot_manage_terms(): void
    {
        $this->actingAs(User::factory()->instructor()->create())->get(route('admin.terms.index'))->assertForbidden();
    }

    public function test_closed_term_cannot_be_edited(): void
    {
        $term = Term::factory()->create(['status' => Term::STATUS_CLOSED, 'academic_year' => '2024-2025', 'type' => 'second']);

        $this->actingAs($this->admin())->get(route('admin.terms.edit', $term))->assertForbidden();

        $this->actingAs($this->admin())->put(route('admin.terms.update', $term), [
            'academic_year' => '2099-2100', 'type' => 'summer',
            'teaching_starts_on' => '2026-09-13', 'teaching_ends_on' => '2026-12-24',
        ])->assertForbidden();

        $term->refresh();
        $this->assertSame('2024-2025', $term->academic_year);
        $this->assertSame('second', $term->type);
    }

    public function test_close_only_allowed_on_open_term(): void
    {
        $term = Term::factory()->create(['status' => Term::STATUS_CLOSED]);

        $this->actingAs($this->admin())->post(route('admin.terms.close', $term))->assertForbidden();

        $this->assertSame('closed', $term->fresh()->status);
    }

    public function test_duplicate_holiday_dates_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('admin.terms.store'), [
            'academic_year' => '2026-2027', 'type' => 'first',
            'teaching_starts_on' => '2026-09-13', 'teaching_ends_on' => '2026-12-24',
            'holidays' => "2026-09-24|اليوم الوطني\n2026-09-24|عطلة أخرى",
        ])->assertSessionHasErrors('holidays');

        $this->assertSame(0, Term::count());
    }
}
