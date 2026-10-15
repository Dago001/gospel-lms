<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'gospel:create-admin {email} {name} {--password= : Omit to be prompted securely}';

    protected $description = 'Create an administrator (MFA enrolment is forced at first sign-in)';

    public function handle(): int
    {
        $password = $this->option('password') ?: $this->secret('Password (min 10 chars, mixed case, number)');
        $v = Validator::make(['email' => $this->argument('email'), 'password' => $password],
            ['email' => ['email', 'unique:users,email'], 'password' => [Password::min(10)->mixedCase()->numbers()]]);
        if ($v->fails()) {
            $this->error(implode("\n", $v->errors()->all()));

            return self::FAILURE;
        }
        if (! Role::where('slug', 'admin')->exists()) {
            $this->call('db:seed', ['--class' => 'Database\Seeders\RolesAndPermissionsSeeder', '--force' => true]);
        }
        $role = Role::where('slug', 'admin')->firstOrFail();
        $user = new User(['name' => $this->argument('name'), 'email' => strtolower($this->argument('email')), 'password' => $password]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->roles()->attach($role->id);
        $user->profile()->create([]);
        $this->info("Administrator {$user->email} created. They must enrol in two-factor authentication at first sign-in.");

        return self::SUCCESS;
    }
}
