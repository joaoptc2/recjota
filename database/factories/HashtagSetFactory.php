<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\HashtagSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HashtagSet> */
class HashtagSetFactory extends Factory
{
    protected $model = HashtagSet::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => 'Conjunto '.fake()->word(),
            'hashtags' => ['#marketing', '#conteudo', '#agencia'],
        ];
    }
}
