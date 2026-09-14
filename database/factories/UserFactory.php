<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Support\Enums\RoleName;
use App\Support\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'type' => UserType::Agency,
            'timezone' => config('agency.default_timezone'),
            'locale' => 'pt_BR',
            'is_active' => true,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function agency(): static
    {
        return $this->state(fn () => ['type' => UserType::Agency]);
    }

    public function client(): static
    {
        return $this->state(fn () => ['type' => UserType::Client]);
    }

    /** Cria o usuário já com o papel atribuído e o tipo coerente. */
    public function withRole(RoleName $role): static
    {
        return $this
            ->state(fn () => ['type' => $role->userType()])
            ->afterCreating(fn (User $user) => $user->assignRole($role->value));
    }
}
