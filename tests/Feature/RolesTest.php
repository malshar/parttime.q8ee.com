<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth', 'role:admin'])->get('/_admin-only', fn () => 'ok');
    }

    public function test_admin_passes_role_middleware(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/_admin-only')->assertOk();
    }

    public function test_instructor_gets_403_on_admin_route(): void
    {
        $this->actingAs(User::factory()->instructor()->create())->get('/_admin-only')->assertForbidden();
    }

    public function test_audit_log_records_action_with_subject(): void
    {
        $user = User::factory()->admin()->create();
        $log = AuditLog::record($user->id, 'test_action', $user, '1.2.3.4');

        $this->assertDatabaseHas('audit_log', [
            'id' => $log->id, 'user_id' => $user->id, 'action' => 'test_action',
            'subject_type' => $user->getMorphClass(), 'subject_id' => $user->id, 'ip' => '1.2.3.4',
        ]);
    }

    public function test_create_admin_command_creates_admin_user(): void
    {
        $this->artisan('app:create-admin', ['email' => 'admin@example.com', 'name' => 'Admin'])
            ->expectsQuestion('Password', 'secret-password-123')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'role' => 'admin']);
    }

    public function test_create_admin_command_reads_password_from_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pw');
        file_put_contents($file, "file-password-123456\n");

        $this->artisan('app:create-admin', ['email' => 'file@example.com', 'name' => 'Admin', '--password-file' => $file])
            ->assertExitCode(0);

        unlink($file);
        $this->assertDatabaseHas('users', ['email' => 'file@example.com', 'role' => 'admin']);
        $this->assertTrue(Hash::check('file-password-123456', User::where('email', 'file@example.com')->first()->password));
    }

    public function test_create_admin_command_rejects_short_password_from_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pw');
        file_put_contents($file, "short\n");

        $this->artisan('app:create-admin', ['email' => 'short@example.com', 'name' => 'Admin', '--password-file' => $file])
            ->assertExitCode(1);

        unlink($file);
        $this->assertDatabaseMissing('users', ['email' => 'short@example.com']);
    }
}
