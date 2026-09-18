<?php

declare(strict_types=1);

namespace App\Livewire\Tasks;

use App\Actions\Tasks\MoveTask;
use App\Actions\Tasks\SaveTask;
use App\Models\Client;
use App\Models\Task;
use App\Support\Enums\TaskPriority;
use App\Support\Enums\TaskStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Quadro Kanban de tarefas, com visão "minhas tarefas" agregada (Seção 6.9). */
class TaskBoard extends Component
{
    public ?Client $client = null;

    #[Url]
    public bool $mine = false;

    public bool $showForm = false;

    public string $title = '';

    public string $description = '';

    public ?int $assigneeId = null;

    public string $dueAt = '';

    public string $priority = 'normal';

    public ?int $clientIdForNew = null;

    public function mount(?Client $client = null): void
    {
        // Livewire injeta um model vazio quando o parâmetro não é passado.
        $this->client = $client?->exists ? $client : null;
        $this->clientIdForNew = $client?->getKey();
    }

    #[Computed]
    public function columns(): array
    {
        $tarefas = Task::query()
            ->with(['assignee', 'client'])
            ->when($this->client !== null, fn ($q) => $q->where('client_id', $this->client->getKey()))
            ->when($this->mine, fn ($q) => $q->where('assignee_id', auth()->id()))
            ->orderByRaw('due_at is null, due_at')
            ->get();

        $colunas = [];

        foreach (TaskStatus::cases() as $status) {
            $colunas[$status->value] = [
                'status' => $status,
                'tarefas' => $tarefas->where('status', $status)->values(),
            ];
        }

        return $colunas;
    }

    #[Computed]
    public function assignees(): Collection
    {
        if ($this->client === null) {
            return collect();
        }

        return $this->client->users()->where('type', 'agency')->orderBy('name')->get();
    }

    public function move(int $taskId, string $destino): void
    {
        $tarefa = Task::findOrFail($taskId);
        $this->authorize('update', $tarefa);

        app(MoveTask::class)($tarefa, TaskStatus::from($destino));

        unset($this->columns);
    }

    public function create(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'dueAt' => ['nullable', 'date'],
            'clientIdForNew' => ['required', 'integer'],
        ], [], ['title' => 'título', 'clientIdForNew' => 'cliente']);

        $cliente = Client::findOrFail($this->clientIdForNew);
        $this->authorize('create', Task::class);

        app(SaveTask::class)($cliente, [
            'title' => $this->title,
            'description' => $this->description !== '' ? $this->description : null,
            'assignee_id' => $this->assigneeId,
            'due_at' => $this->dueAt !== '' ? $this->dueAt : null,
            'priority' => TaskPriority::from($this->priority),
            'status' => TaskStatus::Todo,
        ]);

        $this->reset(['title', 'description', 'assigneeId', 'dueAt', 'showForm']);
        unset($this->columns);
    }

    public function render(): View
    {
        return view('livewire.tasks.task-board');
    }
}
