<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\MediaAsset;
use App\Support\Enums\MediaSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MediaAsset> */
class MediaAssetFactory extends Factory
{
    protected $model = MediaAsset::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'source' => MediaSource::Upload,
            'filename' => fake()->slug(3).'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1_200_000,
            // 1080x1350 = 4:5, a proporção mais usada no feed.
            'width' => 1080,
            'height' => 1350,
            'checksum' => fake()->sha256(),
            'local_path' => 'clients/1/midia/exemplo.jpg',
        ];
    }

    public function quadrada(): static
    {
        return $this->state(fn () => ['width' => 1080, 'height' => 1080]);
    }

    public function panoramicaDemais(): static
    {
        return $this->state(fn () => ['width' => 1920, 'height' => 600]);
    }

    public function pesadaDemais(): static
    {
        return $this->state(fn () => ['size_bytes' => 12 * 1024 * 1024]);
    }

    public function estreita(): static
    {
        return $this->state(fn () => ['width' => 200, 'height' => 250]);
    }

    public function video(int $segundos = 30): static
    {
        return $this->state(fn () => [
            'filename' => fake()->slug(3).'.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 20_000_000,
            'width' => 1080,
            'height' => 1920,
            'duration_ms' => $segundos * 1000,
        ]);
    }
}
