<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public function __invoke(Request $request): View
    {
        $this->authorize('viewAny', Post::class);

        $clientes = Client::query()->orderBy('name')->get();

        // Multi-cliente na visão da agência; um cliente por vez quando escolhido.
        $cliente = $request->filled('cliente')
            ? $clientes->firstWhere('ulid', $request->string('cliente')->toString())
            : null;

        return view('agency.calendar', ['clients' => $clientes, 'client' => $cliente]);
    }
}
