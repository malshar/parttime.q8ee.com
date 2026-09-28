<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email} {name}';

    protected $description = 'Create (or promote) an admin user';

    public function handle(): int
    {
        $password = $this->secret('Password');
        if (strlen((string) $password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => strtolower($this->argument('email'))],
            ['name' => $this->argument('name'), 'password' => $password, 'role' => User::ROLE_ADMIN, 'email_verified_at' => now()],
        );

        $this->info("Admin ready: {$user->email}");

        return self::SUCCESS;
    }
}
