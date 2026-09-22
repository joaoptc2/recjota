<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Post;
use App\Models\PublishLog;
use App\Support\Enums\PublishStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublishLog> */
class PublishLogFactory extends Factory
{
    protected $model = PublishLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'attempt' => 1,
            'stage' => PublishStage::Container,
            'request' => ['image_url' => 'https://agencia.example.com/media-tmp/x.jpg', 'access_token' => 'IGAA…0000'],
            'response' => ['id' => '17889455560051444'],
            'succeeded' => true,
            'error' => null,
        ];
    }
}
