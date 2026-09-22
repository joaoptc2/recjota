<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

use App\Models\Post;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Resumo de métricas de um cliente num período (Seção 6.8). Todo valor
 * pode ser null: null é "indisponível" na tela, nunca zero.
 */
final readonly class MetricsPeriodSummary
{
    /**
     * @param  Collection<int, Post>  $topPosts  Até 5, com a relação `latestMetric` carregada
     * @param  array<int, array{date: string, reach: ?int, followers: ?int}>  $daily
     * @param  array<int, array{month: string, label: string, posts: int, reach: ?int, followers: ?int, engagement: ?float}>  $months
     */
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public ?int $followersStart,
        public ?int $followersEnd,
        public ?int $reach,
        public ?int $impressions,
        public ?int $profileViews,
        public ?int $websiteClicks,
        public int $postsPublished,
        public int $postsMeasured,
        public ?float $engagementRate,
        public ?int $previousReach,
        public ?float $previousEngagementRate,
        public Collection $topPosts,
        public array $daily,
        public array $months,
        public bool $hasAnyData,
    ) {}

    public function followersDelta(): ?int
    {
        if ($this->followersStart === null || $this->followersEnd === null) {
            return null;
        }

        return $this->followersEnd - $this->followersStart;
    }

    /** Variação percentual do alcance contra o período anterior de mesma duração. */
    public function reachChangePercent(): ?float
    {
        if ($this->reach === null || $this->previousReach === null || $this->previousReach === 0) {
            return null;
        }

        return round(($this->reach - $this->previousReach) / $this->previousReach * 100, 1);
    }

    public function engagementChangePoints(): ?float
    {
        if ($this->engagementRate === null || $this->previousEngagementRate === null) {
            return null;
        }

        return round($this->engagementRate - $this->previousEngagementRate, 2);
    }
}
