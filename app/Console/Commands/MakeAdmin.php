<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    protected $signature = 'user:admin {email : The account to change} {--revoke : Remove admin rights instead}';

    protected $description = 'Let a user upload and delete shared documents (or stop them with --revoke)';

    public function handle(): int
    {
        $user = User::where('email', (string) $this->argument('email'))->first();

        if (!$user) {
            $this->error('No user has that email address.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => !$this->option('revoke')])->save();

        $this->info($user->is_admin ? "{$user->email} is now an admin." : "{$user->email} is no longer an admin.");

        return self::SUCCESS;
    }
}
