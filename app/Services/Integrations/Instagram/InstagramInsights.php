<?php

declare(strict_types=1);

namespace App\Services\Integrations\Instagram;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Integrations\SocialInsightsInterface;
use App\Support\DataObjects\AccountInsights;
use App\Support\DataObjects\AccountSnapshot;
use App\Support\DataObjects\MediaInsights;
use App\Support\Enums\PostType;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Insights do Instagram (Seção 6.8) por cima do InstagramClient.
 *
 * A Meta muda o catálogo de métricas com frequência (impressions saiu,
 * views entrou, plays virou views). Cada consulta pede o conjunto atual e,
 * se a API recusar com código 100 (métrica inválida para a conta ou versão),
 * repete com o conjunto mínimo. O que não vier fica null — "indisponível".
 */
class InstagramInsights implements SocialInsightsInterface
{
    public function __construct(private readonly InstagramClient $client) {}

    /** GET /{ig-user-id}?fields=followers_count,follows_count,media_count */
    public function accountSnapshot(SocialAccount $account): AccountSnapshot
    {
        $payload = $this->client->get('/'.$this->igUserId($account), $this->token($account), [
            'fields' => 'followers_count,follows_count,media_count',
        ]);

        return new AccountSnapshot(
            followers: $this->intOrNull($payload['followers_count'] ?? null),
            follows: $this->intOrNull($payload['follows_count'] ?? null),
            mediaCount: $this->intOrNull($payload['media_count'] ?? null),
            raw: $payload,
        );
    }

    /**
     * Duas chamadas: métricas de série (reach, período day) e métricas de
     * valor total (profile_views, website_clicks), que a API só entrega com
     * metric_type=total_value.
     */
    public function accountDailyInsights(SocialAccount $account, DateTimeInterface $day): AccountInsights
    {
        $inicio = Carbon::instance($day)->utc()->startOfDay();
        $janela = ['period' => 'day', 'since' => $inicio->timestamp, 'until' => $inicio->copy()->endOfDay()->timestamp];
        $raw = [];

        $serie = $this->tryMetrics($account, '/'.$this->igUserId($account).'/insights', ['reach,impressions', 'reach'], $janela, $raw, 'serie');
        $totais = $this->tryMetrics($account, '/'.$this->igUserId($account).'/insights', ['profile_views,website_clicks', 'profile_views'], $janela + ['metric_type' => 'total_value'], $raw, 'totais');

        return new AccountInsights(
            reach: $this->seriesValue($serie, 'reach'),
            impressions: $this->seriesValue($serie, 'impressions'),
            profileViews: $this->totalValue($totais, 'profile_views'),
            websiteClicks: $this->totalValue($totais, 'website_clicks'),
            raw: $raw,
        );
    }

    /**
     * GET /{media-id}?fields=like_count,comments_count,media_product_type e
     * GET /{media-id}/insights?metric=... conforme o tipo de mídia.
     */
    public function mediaInsights(SocialAccount $account, Post $post): MediaInsights
    {
        $mediaId = (string) $post->external_post_id;
        $raw = [];

        $campos = $this->client->get('/'.$mediaId, $this->token($account), [
            'fields' => 'like_count,comments_count,media_type,media_product_type',
        ]);
        $raw['fields'] = $campos;

        $conjuntos = match ($post->type) {
            PostType::Reel, PostType::FeedVideo => ['reach,saved,shares,views,plays', 'reach,saved,shares,views', 'reach,saved'],
            PostType::Story => ['reach,views,impressions', 'reach'],
            default => ['reach,saved,shares,views,impressions', 'reach,saved,shares,views', 'reach,saved'],
        };

        $insights = $this->tryMetrics($account, '/'.$mediaId.'/insights', $conjuntos, [], $raw, 'insights');

        $views = $this->seriesValue($insights, 'views');
        $eVideo = in_array($post->type, [PostType::Reel, PostType::FeedVideo], true);

        return new MediaInsights(
            reach: $this->seriesValue($insights, 'reach'),
            impressions: $this->seriesValue($insights, 'impressions') ?? ($eVideo ? null : $views),
            likes: $this->intOrNull($campos['like_count'] ?? null),
            comments: $this->intOrNull($campos['comments_count'] ?? null),
            saves: $this->seriesValue($insights, 'saved'),
            shares: $this->seriesValue($insights, 'shares'),
            videoViews: $eVideo ? ($views ?? $this->seriesValue($insights, 'plays')) : null,
            raw: $raw,
        );
    }

    // ---------------------------------------------------------------- interno

    /**
     * Tenta cada conjunto de métricas até um ser aceito. Código 100 = a API
     * não reconhece a métrica para esta conta/versão: tenta o próximo; outro
     * erro sobe (token, rate limit) para o job decidir.
     *
     * @param  array<int, string>  $conjuntos
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $raw
     * @return array<int, array<string, mixed>>
     */
    private function tryMetrics(SocialAccount $account, string $path, array $conjuntos, array $query, array &$raw, string $chave): array
    {
        $ultimo = null;

        foreach ($conjuntos as $metricas) {
            try {
                $payload = $this->client->get($path, $this->token($account), ['metric' => $metricas] + $query);
                $raw[$chave] = $payload;

                return array_values(array_filter((array) ($payload['data'] ?? []), 'is_array'));
            } catch (InstagramApiException $e) {
                if ($e->apiCode !== 100) {
                    throw $e;
                }

                $ultimo = $e;
            }
        }

        Log::info('Instagram: nenhuma métrica aceita para a consulta', [
            'conta' => $account->handle(),
            'path' => $path,
            'erro' => $ultimo?->getMessage(),
        ]);

        $raw[$chave] = ['indisponivel' => $ultimo?->getMessage()];

        return [];
    }

    /** @param  array<int, array<string, mixed>>  $data */
    private function seriesValue(array $data, string $metric): ?int
    {
        foreach ($data as $item) {
            if (($item['name'] ?? null) !== $metric) {
                continue;
            }

            if (isset($item['values'][0]['value'])) {
                return $this->intOrNull($item['values'][0]['value']);
            }

            if (isset($item['total_value']['value'])) {
                return $this->intOrNull($item['total_value']['value']);
            }
        }

        return null;
    }

    /** @param  array<int, array<string, mixed>>  $data */
    private function totalValue(array $data, string $metric): ?int
    {
        return $this->seriesValue($data, $metric);
    }

    private function intOrNull(mixed $valor): ?int
    {
        return is_numeric($valor) ? (int) $valor : null;
    }

    private function igUserId(SocialAccount $account): string
    {
        return (string) $account->external_id;
    }

    private function token(SocialAccount $account): string
    {
        $token = (string) $account->access_token;

        if ($token === '') {
            throw new InstagramApiException(
                message: sprintf('A conta %s não tem token de acesso: reconecte-a.', $account->handle()),
                apiCode: 190,
            );
        }

        return $token;
    }
}
