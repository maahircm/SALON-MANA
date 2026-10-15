<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'salon:create-admin {--name=} {--email=}';

    protected $description = 'Create a Super Administrator account (password is prompted; never stored in config or seeders)';

    public function handle(): int
    {
        $this->callSilent('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $name = $this->option('name') ?: $this->ask('Full name');
        $email = strtolower($this->option('email') ?: $this->ask('Email'));
        $password = getenv('SALON_ADMIN_PASSWORD') ?: $this->secret('Password (min '.config('salon.password_min_length').' chars, mixed case + number)');
        $confirm = getenv('SALON_ADMIN_PASSWORD') ?: $this->secret('Confirm password');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirm],
            ['name' => 'required|max:120', 'email' => 'required|email|unique:users,email', 'password' => ['required', 'confirmed', Password::defaults()]],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['is_active' => true, 'email_verified_at' => now(), 'password_changed_at' => now()])->save();
        $user->roles()->attach(Role::where('slug', Role::SUPER_ADMIN)->firstOrFail());
        $this->info("Super Administrator {$email} created.");

        return self::SUCCESS;
    }
}
