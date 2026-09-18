<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PostEditorController extends Controller
{
    /** Escolher o cliente é o primeiro passo: tudo depende dele. */
    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize('create', Post::class);

        $clientes = Client::query()->orderBy('name')->get();

        if ($clientes->isEmpty()) {
            return redirect()->route('painel.clients.index')
                ->withErrors(['client' => 'Você precisa de ao menos um cliente atribuído para criar posts.']);
        }

        $cliente = $request->filled('cliente')
            ? $clientes->firstWhere('ulid', $request->string('cliente')->toString())
            : $clientes->first();

        abort_if($cliente === null, 404);

        return view('agency.posts.create', ['client' => $cliente, 'clients' => $clientes]);
    }

    public function edit(Post $post): View
    {
        $this->authorize('update', $post);

        return view('agency.posts.edit', [
            'post' => $post->load(['postMedia.mediaAsset', 'client']),
        ]);
    }
}
