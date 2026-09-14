<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\View\View;

class PostController extends Controller
{
    public function show(Post $post): View
    {
        $this->authorize('view', $post);

        return view('agency.posts.show', [
            'post' => $post->load(['client', 'socialAccount', 'campaign', 'author']),
            'comments' => $post->comments()->visibleTo(auth()->user())->with('user')->get(),
        ]);
    }
}
