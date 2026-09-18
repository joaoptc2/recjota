<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Support\Enums\TaskStatus;

/** Mover no quadro Kanban (Seção 6.9). */
class MoveTask
{
    public function __invoke(Task $tarefa, TaskStatus $destino): Task
    {
        $tarefa->status = $destino;
        $tarefa->completed_at = $destino === TaskStatus::Done ? ($tarefa->completed_at ?? now()) : null;
        $tarefa->save();

        return $tarefa;
    }
}
