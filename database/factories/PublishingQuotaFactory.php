<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PublishingQuota;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublishingQuota> */
class PublishingQuotaFactory extends Factory
{
    protected $model = PublishingQuota::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'social_account_id' => SocialAccount::factory(),
            'window_start' => now()->subHours(2)->startOfMinute(),
            'used_count' => 3,
            'quota_total' => 50,
            'checked_at' => now()->subMinutes(5),
        ];
    }

    public function exhausted(): static
    {
        return $this->state(fn () => ['used_count' => 50, 'quota_total' => 50]);
    }
}
