<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\Task;
use App\Support\Enums\TaskPriority;
use App\Support\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'due_at' => now()->addDays(fake()->numberBetween(-5, 15)),
            'priority' => TaskPriority::Normal,
            'status' => TaskStatus::Todo,
        ];
    }
}
