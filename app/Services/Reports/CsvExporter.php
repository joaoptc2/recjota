<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\MetricAccountDaily;
use App\Models\Post;
use App\Support\Enums\PostStatus;
use Illuminate\Support\Carbon;

/**
 * Exportação CSV (Seção 6.8): uma seção por dia da conta, outra por post
 * publicado com a última coleta. Célula vazia = indisponível. Separador
 * ponto-e-vírgula e BOM para abrir direto no Excel em pt-BR.
 */
class CsvExporter
{
    public const SEPARATOR = ';';

    public function forClient(Client $client, Carbon $from, Carbon $to): string
    {
        $from = $from->copy()->utc()->startOfDay();
        $to = $to->copy()->utc()->endOfDay();
        $linhas = [];

        $linhas[] = ['# Métricas da conta por dia', '', '', '', '', '', ''];
        $linhas[] = ['data', 'conta', 'seguidores', 'alcance', 'impressoes', 'visitas_ao_perfil', 'cliques_no_site'];

        $contas = $client->socialAccounts()->withoutGlobalScopes()->get()->keyBy('id');

        MetricAccountDaily::query()
            ->whereIn('social_account_id', $contas->keys())
            ->whereBetween('date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('date')
            ->orderBy('social_account_id')
            ->get()
            ->each(function (MetricAccountDaily $d) use (&$linhas, $contas): void {
                $linhas[] = [
                    $d->date->toDateString(),
                    $contas->get($d->social_account_id)?->handle() ?? 'conta removida',
                    $d->followers,
                    $d->reach,
                    $d->impressions,
                    $d->profile_views,
                    $d->website_clicks,
                ];
            });

        $linhas[] = [];
        $linhas[] = ['# Posts publicados (última coleta)', '', '', '', '', '', '', '', '', '', ''];
        $linhas[] = ['publicado_em', 'conta', 'tipo', 'legenda', 'link', 'alcance', 'curtidas', 'comentarios', 'salvos', 'compartilhamentos', 'engajamento_pct'];

        Post::query()
            ->withoutClientScope()
            ->where('client_id', $client->getKey())
            ->where('status', PostStatus::Published->value)
            ->whereBetween('published_at', [$from, $to])
            ->with(['latestMetric', 'socialAccount'])
            ->orderBy('published_at')
            ->get()
            ->each(function (Post $p) use (&$linhas, $client): void {
                $m = $p->getRelation('latestMetric');

                $linhas[] = [
                    display_datetime($p->published_at, $client),
                    $p->socialAccount?->handle() ?? '',
                    $p->type->label(),
                    str_replace(["\r", "\n"], ' ', (string) $p->caption),
                    $p->external_permalink,
                    $m?->reach,
                    $m?->likes,
                    $m?->comments,
                    $m?->saves,
                    $m?->shares,
                    $m?->engagement_rate !== null ? number_format((float) $m->engagement_rate, 2, ',', '') : null,
                ];
            });

        $saida = fopen('php://temp', 'r+');
        fwrite($saida, "\xEF\xBB\xBF");

        foreach ($linhas as $linha) {
            fputcsv($saida, array_map(fn ($v) => $v === null ? '' : (string) $v, $linha), self::SEPARATOR, '"', '');
        }

        rewind($saida);
        $conteudo = (string) stream_get_contents($saida);
        fclose($saida);

        return $conteudo;
    }
}
