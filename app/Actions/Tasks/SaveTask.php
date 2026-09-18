<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Client;
use App\Models\Task;
use App\Support\Display;
use App\Support\Enums\TaskPriority;
use App\Support\Enums\TaskStatus;

class SaveTask
{
    /** @param array<string, mixed> $dados */
    public function __invoke(Client $cliente, array $dados, ?Task $tarefa = null): Task
    {
        $status = $dados['status'] instanceof TaskStatus
            ? $dados['status']
            : TaskStatus::from((string) ($dados['status'] ?? TaskStatus::Todo->value));

        $atributos = [
            'client_id' => $cliente->getKey(),
            'title' => $dados['title'],
            'description' => $dados['description'] ?? null,
            'assignee_id' => $dados['assignee_id'] ?? null,
            'due_at' => filled($dados['due_at'] ?? null) ? Display::toUtc((string) $dados['due_at'], $cliente) : null,
            'priority' => $dados['priority'] instanceof TaskPriority
                ? $dados['priority']
                : TaskPriority::from((string) ($dados['priority'] ?? TaskPriority::Normal->value)),
            'status' => $status,
            'checklist' => $dados['checklist'] ?? null,
            'related_post_id' => $dados['related_post_id'] ?? null,
            'related_campaign_id' => $dados['related_campaign_id'] ?? null,
            'completed_at' => $status === TaskStatus::Done ? ($tarefa?->completed_at ?? now()) : null,
        ];

        if ($tarefa === null) {
            $atributos['created_by'] = auth()->id();

            return Task::create($atributos);
        }

        $tarefa->update($atributos);

        return $tarefa->refresh();
    }
}
