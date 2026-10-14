<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

#[Signature('user:create {username : Login name (lowercased)} {--name= : Display name, defaults to the username} {--role=superadmin : superadmin, admin, gm or section} {--password= : Asked for when left out}')]
#[Description('Create a login account with the default permissions of its level, e.g. the first super admin')]
class CreateUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $username = Str::lower(trim((string) $this->argument('username')));
        $password = (string) ($this->option('password') ?? $this->secret('Password'));
        $role = (string) $this->option('role');

        $validator = Validator::make(compact('username', 'password', 'role'), [
            'username' => ['required', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')],
            'password' => ['required', 'min:6'],
            'role' => ['required', Rule::in(array_keys(AccessControl::ROLES))],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => (string) ($this->option('name') ?: $username),
            'username' => $username,
            'password' => $password,
            'role' => $role,
            'permissions' => AccessControl::defaultPermissions($role),
            'is_active' => true,
        ]);

        $this->info("User {$user->name} ({$user->username}) dibuat sebagai {$user->roleLabel()}.");

        return self::SUCCESS;
    }
}
