<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UsersSuperAdmin extends Command
{
    protected $signature = 'users:super-admin
        {email : User email address}
        {--revoke : Revoke super admin privileges instead of granting them}';

    protected $description = 'Grant or revoke super admin access for a user by email';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user found for '{$email}'.");

            return self::FAILURE;
        }

        $shouldRevoke = (bool) $this->option('revoke');
        $user->forceFill([
            'is_super_admin' => ! $shouldRevoke,
        ])->save();

        if ($shouldRevoke) {
            $this->info("Revoked super admin privileges from {$user->email}.");
        } else {
            $this->info("Granted super admin privileges to {$user->email}.");
        }

        return self::SUCCESS;
    }
}
