<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    public function __invoke(): View
    {
        $this->authorize('viewAny', Post::class);

        return view('portal.approvals', [
            'client' => auth()->user()->clients()->first(),
        ]);
    }
}
