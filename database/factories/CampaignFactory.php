<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Campaign> */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->sentence(3),
            'objective' => fake()->sentence(6),
            'starts_at' => now()->startOfMonth(),
            'ends_at' => now()->endOfMonth(),
            'color' => fake()->hexColor(),
        ];
    }
}
