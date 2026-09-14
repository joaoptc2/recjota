<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Client;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Task;
use App\Support\Enums\PostStatus;
use Illuminate\View\View;

/**
 * Dashboard da agência (Seção 6.2): o que exige ação minha, o que vence em 24h,
 * o que falhou, e o que vai quebrar em breve (tokens).
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $user = auth()->user();

        return view('agency.dashboard', [
            'awaitingClient' => Post::query()
                ->awaitingApproval()
                ->with(['client', 'socialAccount'])
                ->orderBy('scheduled_at')
                ->limit(8)
                ->get(),

            'approvalsDueSoon' => Approval::query()
                ->pending()
                ->dueWithin(24)
                ->with(['post.client'])
                ->orderBy('due_at')
                ->limit(8)
                ->get(),

            'failedPosts' => Post::query()
                ->where('status', PostStatus::Failed->value)
                ->with(['client', 'socialAccount'])
                ->orderByDesc('updated_at')
                ->limit(8)
                ->get(),

            'expiringAccounts' => SocialAccount::query()
                ->expiringWithin(7)
                ->with('client')
                ->orderBy('token_expires_at')
                ->limit(8)
                ->get(),

            'overdueTasks' => Task::query()
                ->overdue()
                ->with(['client', 'assignee'])
                ->orderBy('due_at')
                ->limit(8)
                ->get(),

            'myTasks' => Task::query()
                ->where('assignee_id', $user->getKey())
                ->where('status', '!=', 'done')
                ->with('client')
                ->orderBy('due_at')
                ->limit(8)
                ->get(),

            'publishedThisMonth' => Post::query()
                ->where('status', PostStatus::Published->value)
                ->whereBetween('published_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->selectRaw('client_id, count(*) as total')
                ->groupBy('client_id')
                ->pluck('total', 'client_id'),

            'clients' => Client::query()->orderBy('name')->get(),
        ]);
    }
}
