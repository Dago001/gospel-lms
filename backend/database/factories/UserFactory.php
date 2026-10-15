<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Password-12345'),
            'status' => 'active',
            'remember_token' => \Illuminate\Support\Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended', 'suspended_at' => now()]);
    }

    public function withRole(string $slug): static
    {
        return $this->afterCreating(function (User $user) use ($slug) {
            $role = Role::where('slug', $slug)->firstOrFail();
            $user->roles()->attach($role->id);
        });
    }
}
