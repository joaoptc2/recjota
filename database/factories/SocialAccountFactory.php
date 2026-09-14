<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\SocialAccount;
use App\Support\Enums\AccountType;
use App\Support\Enums\ConnectionStatus;
use App\Support\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SocialAccount> */
class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'platform' => SocialPlatform::Instagram,
            'external_id' => (string) fake()->unique()->numerify('178414#########'),
            'username' => fake()->unique()->userName(),
            'display_name' => fake()->company(),
            'account_type' => AccountType::Business,
            'access_token' => 'IGQ'.fake()->sha256(),
            'token_expires_at' => now()->addDays(60),
            'scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],
            'connection_status' => ConnectionStatus::Connected,
        ];
    }

    public function expiringSoon(): static
    {
        return $this->state(fn () => ['token_expires_at' => now()->addDays(4)]);
    }

    public function creator(): static
    {
        return $this->state(fn () => ['account_type' => AccountType::Creator]);
    }
}
