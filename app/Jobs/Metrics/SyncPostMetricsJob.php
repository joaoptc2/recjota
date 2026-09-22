<?php

declare(strict_types=1);

namespace App\Jobs\Metrics;

use App\Models\MetricPost;
use App\Models\Post;
use App\Services\Integrations\Instagram\InstagramApiException;
use App\Services\Integrations\SocialInsightsInterface;
use App\Support\Enums\ConnectionStatus;
use App\Support\Enums\PostStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Coleta as métricas de um post publicado (Seção 6.8). Uma linha por post e
 * por dia de coleta (collected_at truncado ao dia): rodar de novo no mesmo
 * dia atualiza; no dia seguinte cria o próximo ponto da série.
 */
class SyncPostMetricsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $postId) {}

    public function handle(TenantContext $tenant, SocialInsightsInterface $insights): void
    {
        $tenant->withoutRestriction(function () use ($insights): void {
            $post = Post::query()->withoutClientScope()->with('socialAccount')->find($this->postId);

            if ($post === null || $post->status !== PostStatus::Published || $post->external_post_id === null) {
                return;
            }

            $conta = $post->socialAccount;

            if ($conta === null || $conta->needsReconnection()) {
                return;
            }

            try {
                $dados = $insights->mediaInsights($conta, $post);
            } catch (InstagramApiException $e) {
                if ($e->isTokenInvalid() || $e->isPermissionDenied()) {
                    $conta->fill(['connection_status' => ConnectionStatus::Expired, 'last_error' => $e->actionableMessage()])->save();
                }

                Log::warning('Métricas: coleta do post falhou', [
                    'post_id' => $post->getKey(),
                    'conta' => $conta->handle(),
                    'permanente' => $e->isPermanent(),
                    'erro' => $e->getMessage(),
                ]);

                return;
            }

            $hoje = now()->startOfDay();

            $linha = MetricPost::query()
                ->where('post_id', $post->getKey())
                ->where('collected_at', $hoje)
                ->first() ?? new MetricPost(['post_id' => $post->getKey(), 'collected_at' => $hoje]);

            $linha->fill([
                'reach' => $dados->reach,
                'impressions' => $dados->impressions,
                'likes' => $dados->likes,
                'comments' => $dados->comments,
                'saves' => $dados->saves,
                'shares' => $dados->shares,
                'video_views' => $dados->videoViews,
                'engagement_rate' => $dados->engagementRate(),
                'raw' => $dados->raw,
            ])->save();
        });
    }
}
