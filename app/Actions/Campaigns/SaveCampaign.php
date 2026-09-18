<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Models\Campaign;
use App\Models\Client;
use App\Support\Display;

class SaveCampaign
{
    /** @param array<string, mixed> $dados */
    public function __invoke(Client $cliente, array $dados, ?Campaign $campanha = null): Campaign
    {
        $atributos = [
            'client_id' => $cliente->getKey(),
            'name' => $dados['name'],
            'objective' => $dados['objective'] ?? null,
            'color' => $dados['color'] ?? '#6366F1',
            // Datas digitadas no fuso do cliente, gravadas em UTC (R2).
            'starts_at' => filled($dados['starts_at'] ?? null) ? Display::toUtc((string) $dados['starts_at'], $cliente) : null,
            'ends_at' => filled($dados['ends_at'] ?? null) ? Display::toUtc((string) $dados['ends_at'], $cliente) : null,
        ];

        if ($campanha === null) {
            $atributos['created_by'] = auth()->id();

            return Campaign::create($atributos);
        }

        $campanha->update($atributos);

        return $campanha->refresh();
    }
}
