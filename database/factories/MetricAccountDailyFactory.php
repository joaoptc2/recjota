<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MetricAccountDaily;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MetricAccountDaily> */
class MetricAccountDailyFactory extends Factory
{
    protected $model = MetricAccountDaily::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'social_account_id' => SocialAccount::factory(),
            'date' => now()->subDay()->toDateString(),
            'followers' => fake()->numberBetween(1000, 5000),
            'follows' => fake()->numberBetween(100, 500),
            'reach' => fake()->numberBetween(200, 2000),
            'impressions' => null,
            'profile_views' => fake()->numberBetween(10, 100),
            'website_clicks' => fake()->numberBetween(0, 20),
            'raw' => null,
        ];
    }
}
