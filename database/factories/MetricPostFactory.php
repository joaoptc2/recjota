<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MetricPost;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MetricPost> */
class MetricPostFactory extends Factory
{
    protected $model = MetricPost::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $reach = fake()->numberBetween(300, 3000);
        $likes = fake()->numberBetween(10, 200);
        $comments = fake()->numberBetween(0, 30);
        $saves = fake()->numberBetween(0, 40);
        $shares = fake()->numberBetween(0, 20);

        return [
            'post_id' => Post::factory()->published(),
            'collected_at' => now()->startOfDay(),
            'reach' => $reach,
            'impressions' => null,
            'likes' => $likes,
            'comments' => $comments,
            'saves' => $saves,
            'shares' => $shares,
            'video_views' => null,
            'engagement_rate' => round(($likes + $comments + $saves + $shares) / $reach * 100, 4),
            'raw' => null,
        ];
    }
}
