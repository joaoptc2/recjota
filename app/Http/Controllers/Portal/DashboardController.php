<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Brief;
use App\Models\Post;
use App\Support\Enums\PostStatus;
use Illuminate\View\View;

/**
 * Dashboard do cliente (Seção 6.2): quantos posts esperam por mim, até quando,
 * o que vem por aí. Densidade baixa — quem usa 4 min por semana quer clareza.
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $client = auth()->user()->clients()->first();

        return view('portal.dashboard', [
            'client' => $client,

            'awaitingMe' => Post::query()
                ->awaitingApproval()
                ->with('socialAccount')
                ->orderBy('scheduled_at')
                ->get(),

            'nextApprovalDeadline' => Approval::query()
                ->pending()
                ->orderBy('due_at')
                ->value('due_at'),

            'upcoming' => Post::query()
                ->whereIn('status', [PostStatus::Approved->value, PostStatus::Scheduled->value])
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '>=', now())
                ->with('socialAccount')
                ->orderBy('scheduled_at')
                ->limit(6)
                ->get(),

            'publishedThisMonth' => Post::query()
                ->where('status', PostStatus::Published->value)
                ->whereBetween('published_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),

            'openBriefs' => Brief::query()
                ->whereIn('status', ['submitted', 'in_progress'])
                ->orderByDesc('submitted_at')
                ->limit(5)
                ->get(),
        ]);
    }
}
