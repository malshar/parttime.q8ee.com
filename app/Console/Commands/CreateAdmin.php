<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email} {name} {--password-file= : Read the password from this file instead of prompting (first deploy)}';

    protected $description = 'Create (or promote) an admin user';

    public function handle(): int
    {
        $file = $this->option('password-file');
        if ($file !== null) {
            if (! is_readable($file)) {
                $this->error("Password file not readable: {$file}");

                return self::FAILURE;
            }
            $password = rtrim((string) file_get_contents($file), "\r\n");
        } else {
            $password = $this->secret('Password');
        }
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
