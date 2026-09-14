<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\View\View;

class PostController extends Controller
{
    public function show(Post $post): View
    {
        // Cliente A pedindo o post do cliente B recebe 403 aqui.
        $this->authorize('view', $post);

        return view('portal.posts.show', [
            'post' => $post->load(['socialAccount', 'campaign']),
            // visibleTo() garante que comentário interno nunca chega ao cliente.
            'comments' => $post->comments()->visibleTo(auth()->user())->with('user')->get(),
        ]);
    }
}
