<?php

namespace App\Console\Commands;

use App\Models\SuperAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateSuperAdmin extends Command
{
    protected $signature = 'super:create {email} {password} {--name=Admin}';

    protected $description = 'Create a super admin account';

    public function handle(): int
    {
        $admin = SuperAdmin::updateOrCreate(
            ['email' => $this->argument('email')],
            ['name' => $this->option('name'), 'password' => Hash::make($this->argument('password'))]
        );

        $this->info('Super admin ready: '.$admin->email);

        return self::SUCCESS;
    }
}
