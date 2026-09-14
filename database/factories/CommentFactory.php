<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Comment> */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'body' => fake()->sentence(12),
            'is_internal' => false,
        ];
    }

    /** Amarra o comentário a um post, herdando o cliente dele. */
    public function forPost(Post $post): static
    {
        return $this->state(fn () => [
            'client_id' => $post->client_id,
            'commentable_type' => Post::class,
            'commentable_id' => $post->getKey(),
        ]);
    }

    public function internal(): static
    {
        return $this->state(fn () => ['is_internal' => true]);
    }
}
