<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientSetting;
use App\Support\Enums\ClientStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' LTDA',
            'document' => fake()->numerify('##.###.###/0001-##'),
            'brand_colors' => ['primary' => fake()->hexColor()],
            'timezone' => config('agency.default_timezone'),
            'contract_start' => fake()->dateTimeBetween('-2 years', 'now'),
            'status' => ClientStatus::Active,
        ];
    }

    public function configured(array $settings = []): static
    {
        return $this->afterCreating(function (Client $client) use ($settings): void {
            ClientSetting::create(array_merge([
                'client_id' => $client->getKey(),
                'approval_required' => true,
                'approval_deadline_hours' => config('agency.approval.default_deadline_hours'),
                'min_approvals' => config('agency.approval.default_min_approvals'),
            ], $settings));
        });
    }
}
