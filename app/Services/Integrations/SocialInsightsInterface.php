<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\DataObjects\AccountInsights;
use App\Support\DataObjects\AccountSnapshot;
use App\Support\DataObjects\MediaInsights;
use DateTimeInterface;

/**
 * Leitura de métricas (Seção 6.8), separada da publicação. Toda métrica que
 * a API não devolver chega como null: a interface mostra "indisponível", e
 * nunca um zero inventado.
 */
interface SocialInsightsInterface
{
    public function accountSnapshot(SocialAccount $account): AccountSnapshot;

    public function accountDailyInsights(SocialAccount $account, DateTimeInterface $day): AccountInsights;

    public function mediaInsights(SocialAccount $account, Post $post): MediaInsights;
}
