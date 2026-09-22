<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\CloudConnection;
use App\Models\User;
use App\Support\Enums\CloudProvider;
use App\Support\Enums\ConnectionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CloudConnection> */
class CloudConnectionFactory extends Factory
{
    protected $model = CloudConnection::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'user_id' => User::factory(),
            'provider' => CloudProvider::GoogleDrive,
            'account_id' => (string) fake()->numberBetween(100000000000, 999999999999),
            'account_email' => fake()->safeEmail(),
            'account_name' => fake()->name(),
            'access_token' => 'ya29.tokenDeAcessoFalso'.fake()->sha1(),
            'refresh_token' => '1//refreshTokenFalso'.fake()->sha1(),
            'token_expires_at' => now()->addMinutes(55),
            'token_refreshed_at' => now(),
            'scopes' => ['https://www.googleapis.com/auth/drive.file'],
            'status' => ConnectionStatus::Connected,
            'consent_given_at' => now(),
        ];
    }

    public function onedrive(): static
    {
        return $this->state(fn () => [
            'provider' => CloudProvider::OneDrive,
            'access_token' => 'EwB.tokenDeAcessoFalso'.fake()->sha1(),
            'refresh_token' => 'M.C5.refreshTokenFalso'.fake()->sha1(),
            'scopes' => ['Files.Read', 'offline_access', 'Sites.Read.All'],
        ]);
    }

    public function expiredToken(): static
    {
        return $this->state(fn () => ['token_expires_at' => now()->subMinutes(10)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => ConnectionStatus::Expired,
            'last_error' => 'O Google Drive não reconhece mais o acesso desta conexão. Clique em "Reconectar" e autorize novamente.',
        ]);
    }
}
