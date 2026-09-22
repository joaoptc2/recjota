<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\Client;
use App\Models\MetricAccountDaily;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\DataObjects\MetricsPeriodSummary;
use App\Support\Enums\PostStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Agrega o que os jobs de coleta gravaram (Seção 6.8). Só lê o banco: nada
 * aqui fala com a API. Regras:
 *
 *  - soma de uma métrica é null quando nenhum dia a informou;
 *  - seguidores usam o último valor conhecido em cada ponta do período;
 *  - o post entra na lista de melhores pela última coleta que tem;
 *  - "melhor horário" só existe com 30 ou mais posts medidos (Seção 6.5).
 */
class MetricsSummary
{
    public const TOP_POSTS = 5;

    public const MIN_POSTS_FOR_BEST_HOURS = 30;

    public function forClient(Client $client, Carbon $from, Carbon $to): MetricsPeriodSummary
    {
        $from = $from->copy()->utc()->startOfDay();
        $to = $to->copy()->utc()->endOfDay();
        $contas = $client->socialAccounts()->withoutGlobalScopes()->pluck('id');

        $diarios = MetricAccountDaily::query()
            ->whereIn('social_account_id', $contas)
            ->whereBetween('date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('date')
            ->get();

        $posts = $this->publishedPosts($client, $from, $to);
        $medidos = $posts->filter(fn (Post $p) => $p->getRelation('latestMetric') !== null);

        $duracaoDias = (int) $from->diffInDays($to) + 1;
        $anteriorFim = $from->copy()->subSecond();
        $anteriorInicio = $anteriorFim->copy()->subDays($duracaoDias - 1)->startOfDay();

        $anteriores = MetricAccountDaily::query()
            ->whereIn('social_account_id', $contas)
            ->whereBetween('date', [$anteriorInicio->copy()->startOfDay(), $anteriorFim->copy()->endOfDay()])
            ->get();
        $postsAnteriores = $this->publishedPosts($client, $anteriorInicio, $anteriorFim)
            ->filter(fn (Post $p) => $p->getRelation('latestMetric') !== null);

        return new MetricsPeriodSummary(
            from: $from,
            to: $to,
            followersStart: $this->followersAt($contas, $from, $diarios, first: true),
            followersEnd: $this->followersAt($contas, $to, $diarios, first: false),
            reach: $this->sumOrNull($diarios, 'reach'),
            impressions: $this->sumOrNull($diarios, 'impressions'),
            profileViews: $this->sumOrNull($diarios, 'profile_views'),
            websiteClicks: $this->sumOrNull($diarios, 'website_clicks'),
            postsPublished: $posts->count(),
            postsMeasured: $medidos->count(),
            engagementRate: $this->averageEngagement($medidos),
            previousReach: $this->sumOrNull($anteriores, 'reach'),
            previousEngagementRate: $this->averageEngagement($postsAnteriores),
            topPosts: $medidos
                ->sortByDesc(fn (Post $p) => [(float) ($p->getRelation('latestMetric')->engagement_rate ?? -1), (int) ($p->getRelation('latestMetric')->reach ?? 0)])
                ->take(self::TOP_POSTS)
                ->values(),
            daily: $this->dailySeries($diarios, $from, $to),
            months: $this->monthComparison($client, $contas, $to),
            hasAnyData: $diarios->isNotEmpty() || $medidos->isNotEmpty(),
        );
    }

    /**
     * Horas do dia (no fuso do cliente) com melhor engajamento médio, quando
     * há posts medidos suficientes. Vazio = sem base para sugerir.
     *
     * @return array<int, array{hour: int, engagement: float, posts: int}>
     */
    public function bestPostingHours(SocialAccount $conta, int $limite = 3): array
    {
        $fuso = $conta->client?->displayTimezone() ?? config('agency.default_timezone');

        $posts = Post::query()
            ->withoutClientScope()
            ->where('social_account_id', $conta->getKey())
            ->where('status', PostStatus::Published->value)
            ->whereNotNull('published_at')
            ->with('latestMetric')
            ->get()
            ->filter(fn (Post $p) => $p->getRelation('latestMetric')?->engagement_rate !== null);

        if ($posts->count() < self::MIN_POSTS_FOR_BEST_HOURS) {
            return [];
        }

        return $posts
            ->groupBy(fn (Post $p) => (int) $p->published_at->copy()->setTimezone($fuso)->format('G'))
            ->map(fn (Collection $grupo, int $hora) => [
                'hour' => $hora,
                'engagement' => round((float) $grupo->avg(fn (Post $p) => (float) $p->getRelation('latestMetric')->engagement_rate), 2),
                'posts' => $grupo->count(),
            ])
            ->filter(fn (array $h) => $h['posts'] >= 2)
            ->sortByDesc('engagement')
            ->take($limite)
            ->values()
            ->all();
    }

    // ---------------------------------------------------------------- interno

    /** @return Collection<int, Post> */
    private function publishedPosts(Client $client, Carbon $from, Carbon $to): Collection
    {
        return Post::query()
            ->withoutClientScope()
            ->where('client_id', $client->getKey())
            ->where('status', PostStatus::Published->value)
            ->whereBetween('published_at', [$from, $to])
            ->with(['latestMetric', 'socialAccount', 'postMedia.mediaAsset'])
            ->orderByDesc('published_at')
            ->get();
    }

    /** @param  Collection<int, MetricAccountDaily>  $diarios */
    private function followersAt(Collection $contas, Carbon $momento, Collection $diarios, bool $first): ?int
    {
        // Ponta inicial: o primeiro dia do período com dado; ponta final: o
        // último. Se o período não tem nada, olha o último dia conhecido antes.
        $comDado = $diarios->filter(fn (MetricAccountDaily $d) => $d->followers !== null);

        if ($comDado->isNotEmpty()) {
            $dia = $first ? $comDado->first()->date : $comDado->last()->date;

            return (int) $comDado->where('date', $dia)->sum('followers');
        }

        $ultimoDia = MetricAccountDaily::query()
            ->whereIn('social_account_id', $contas)
            ->whereNotNull('followers')
            ->where('date', '<=', $momento->copy()->endOfDay())
            ->max('date');

        if ($ultimoDia === null) {
            return null;
        }

        return (int) MetricAccountDaily::query()
            ->whereIn('social_account_id', $contas)
            ->whereDate('date', Carbon::parse((string) $ultimoDia)->toDateString())
            ->sum('followers');
    }

    /** @param  Collection<int, MetricAccountDaily>  $linhas */
    private function sumOrNull(Collection $linhas, string $coluna): ?int
    {
        $comDado = $linhas->filter(fn (MetricAccountDaily $d) => $d->{$coluna} !== null);

        return $comDado->isEmpty() ? null : (int) $comDado->sum($coluna);
    }

    /** @param  Collection<int, Post>  $medidos */
    private function averageEngagement(Collection $medidos): ?float
    {
        $taxas = $medidos
            ->map(fn (Post $p) => $p->getRelation('latestMetric')?->engagement_rate)
            ->filter(fn ($t) => $t !== null)
            ->map(fn ($t) => (float) $t);

        return $taxas->isEmpty() ? null : round((float) $taxas->avg(), 2);
    }

    /**
     * @param  Collection<int, MetricAccountDaily>  $diarios
     * @return array<int, array{date: string, reach: ?int, followers: ?int}>
     */
    private function dailySeries(Collection $diarios, Carbon $from, Carbon $to): array
    {
        $porDia = $diarios->groupBy(fn (MetricAccountDaily $d) => $d->date->toDateString());
        $serie = [];

        for ($dia = $from->copy(); $dia->lessThanOrEqualTo($to); $dia->addDay()) {
            $chave = $dia->toDateString();
            $linhas = $porDia->get($chave, collect());

            $serie[] = [
                'date' => $chave,
                'reach' => $this->sumOrNull($linhas, 'reach'),
                'followers' => $this->sumOrNull($linhas, 'followers'),
            ];
        }

        return $serie;
    }

    /**
     * Últimos 6 meses fechados até $to, mês a mês (Seção 6.8).
     *
     * @return array<int, array{month: string, label: string, posts: int, reach: ?int, followers: ?int, engagement: ?float}>
     */
    private function monthComparison(Client $client, Collection $contas, Carbon $to): array
    {
        $meses = [];
        $cursor = $to->copy()->startOfMonth();

        for ($i = 0; $i < 6; $i++) {
            $inicio = $cursor->copy()->startOfMonth();
            $fim = $cursor->copy()->endOfMonth();

            $linhas = MetricAccountDaily::query()
                ->whereIn('social_account_id', $contas)
                ->whereBetween('date', [$inicio->copy()->startOfDay(), $fim->copy()->endOfDay()])
                ->get();

            $posts = $this->publishedPosts($client, $inicio, $fim);
            $medidos = $posts->filter(fn (Post $p) => $p->getRelation('latestMetric') !== null);
            $ultimoComSeguidores = $linhas->filter(fn (MetricAccountDaily $d) => $d->followers !== null)->sortBy('date')->last();

            $meses[] = [
                'month' => $inicio->format('Y-m'),
                'label' => ucfirst($inicio->locale('pt_BR')->translatedFormat('M/Y')),
                'posts' => $posts->count(),
                'reach' => $this->sumOrNull($linhas, 'reach'),
                'followers' => $ultimoComSeguidores === null
                    ? null
                    : (int) $linhas->where('date', $ultimoComSeguidores->date)->sum('followers'),
                'engagement' => $this->averageEngagement($medidos),
            ];

            $cursor->subMonth();
        }

        return array_reverse($meses);
    }

    /** Atalho para telas que só precisam saber se já houve coleta. */
    public function hasCollectedAnything(Client $client): bool
    {
        $contas = $client->socialAccounts()->withoutGlobalScopes()->pluck('id');

        return MetricAccountDaily::query()->whereIn('social_account_id', $contas)->exists()
            || DB::table('metrics_post')
                ->join('posts', 'posts.id', '=', 'metrics_post.post_id')
                ->where('posts.client_id', $client->getKey())
                ->exists();
    }
}
