<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invitation;
use App\Support\Enums\RoleName;
use App\Support\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invitation> */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // Só o hash vai ao banco; o token em claro existe apenas no e-mail.
            'token' => Invitation::hashToken(Invitation::generateToken()),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'type' => UserType::Agency,
            'role' => RoleName::Criador,
            'client_id' => null,
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['accepted_at' => now()->subHour()]);
    }
}
