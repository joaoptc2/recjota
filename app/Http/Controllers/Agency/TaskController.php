<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function __invoke(Request $request): View
    {
        $this->authorize('viewAny', Task::class);

        $clientes = Client::query()->orderBy('name')->get();

        $cliente = $request->filled('cliente')
            ? $clientes->firstWhere('ulid', $request->string('cliente')->toString())
            : null;

        return view('agency.tasks', ['clients' => $clientes, 'client' => $cliente]);
    }
}
