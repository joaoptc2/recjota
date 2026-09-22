<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Client::class);

        return view('agency.clients.index', [
            'clients' => Client::query()
                ->withCount(['posts', 'socialAccounts'])
                ->with('settings')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function show(Client $client): View
    {
        // Falha aqui = 403. É este o ponto que o teste de isolamento cobre.
        $this->authorize('view', $client);

        return view('agency.clients.show', [
            'client' => $client->load(['settings', 'socialAccounts', 'cloudConnections', 'users']),
            'recentPosts' => $client->posts()
                ->with('socialAccount')
                ->orderByDesc('scheduled_at')
                ->limit(10)
                ->get(),
        ]);
    }
}
