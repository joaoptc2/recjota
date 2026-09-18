<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\Post;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PostType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Post> */
class PostFactory extends Factory
{
    protected $model = Post::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'type' => PostType::FeedImage,
            'caption' => fake()->sentence(12).' #marketing #conteudo',
            // Sempre UTC no banco (R2).
            'scheduled_at' => now()->addDays(fake()->numberBetween(1, 20)),
            'status' => PostStatus::Draft,
            'approval_status' => ApprovalStatus::Pending,
            'current_version' => 1,
        ];
    }

    public function awaitingClient(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::AwaitingClient,
            'approval_status' => ApprovalStatus::Pending,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Approved,
            'approval_status' => ApprovalStatus::Approved,
            'approved_version' => 1,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Published,
            'approval_status' => ApprovalStatus::Approved,
            'approved_version' => 1,
            'published_at' => now()->subDays(fake()->numberBetween(1, 20)),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Failed,
            'last_error' => 'Token da conta expirado. Reconecte a conta para retomar a publicação.',
            'last_error_is_permanent' => true,
            'publish_attempts' => 3,
        ]);
    }
}
