<?php

namespace Tests\Unit\Attestations;

use App\Models\Attestation;
use App\Models\User;
use App\Policies\AttestationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttestationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_allowed_instructor_denied_on_every_ability(): void
    {
        $policy = new AttestationPolicy;
        $admin = User::factory()->admin()->create();
        $instructor = User::factory()->instructor()->create();
        $attestation = Attestation::factory()->create();

        foreach (['viewAny', 'generate', 'exportAny'] as $ability) {
            $this->assertTrue($policy->$ability($admin), $ability);
            $this->assertFalse($policy->$ability($instructor), $ability);
        }
        foreach (['view', 'update', 'regenerate', 'export', 'unlock'] as $ability) {
            $this->assertTrue($policy->$ability($admin, $attestation), $ability);
            $this->assertFalse($policy->$ability($instructor, $attestation), $ability);
        }
    }
}
