<?php

namespace Database\Factories;

use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => AccessControl::ROLE_SECTION,
            'permissions' => AccessControl::defaultPermissions(AccessControl::ROLE_SECTION),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A user of the given level with that level's default permissions.
     */
    public function role(string $role): static
    {
        return $this->state(fn () => ['role' => $role, 'permissions' => AccessControl::defaultPermissions($role)]);
    }

    public function superAdmin(): static
    {
        return $this->role(AccessControl::ROLE_SUPERADMIN);
    }

    /**
     * @param  list<string>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn () => ['permissions' => $permissions]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
