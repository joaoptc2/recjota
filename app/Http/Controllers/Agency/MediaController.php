<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MediaAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MediaController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $this->authorize('viewAny', MediaAsset::class);

        $clientes = Client::query()->orderBy('name')->get();

        if ($clientes->isEmpty()) {
            return redirect()->route('painel.clients.index')
                ->withErrors(['client' => 'Você precisa de ao menos um cliente atribuído para usar a biblioteca.']);
        }

        $cliente = $request->filled('cliente')
            ? $clientes->firstWhere('ulid', $request->string('cliente')->toString())
            : $clientes->first();

        abort_if($cliente === null, 404);

        return view('agency.media', ['client' => $cliente, 'clients' => $clientes]);
    }
}
