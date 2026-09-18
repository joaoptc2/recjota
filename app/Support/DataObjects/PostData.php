<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

use App\Models\Client;
use App\Support\Display;
use App\Support\Enums\PostType;
use Illuminate\Support\Carbon;

/**
 * Entrada tipada das Actions de post (Seção 4.3). Nenhuma Action recebe array
 * solto: o que chega aqui já passou pela validação da camada de apresentação.
 */
final readonly class PostData
{
    public function __construct(
        public int $clientId,
        public PostType $type,
        public ?string $caption = null,
        public ?string $firstComment = null,
        public ?int $socialAccountId = null,
        public ?int $campaignId = null,
        /** UTC, sempre (R2). */
        public ?Carbon $scheduledAt = null,
        /** @var array<int, array{media_asset_id: int, alt_text?: ?string, thumbnail_offset_ms?: ?int}> */
        public array $media = [],
        public ?int $createdBy = null,
    ) {}

    /**
     * Converte o horário digitado no fuso do cliente para o UTC que vai ao
     * banco. Único caminho permitido para nascer um scheduled_at.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromForm(array $input, Client $client, ?int $createdBy = null): self
    {
        $agendamento = filled($input['scheduled_at'] ?? null)
            ? Display::toUtc((string) $input['scheduled_at'], $client)
            : null;

        return new self(
            clientId: $client->getKey(),
            type: $input['type'] instanceof PostType ? $input['type'] : PostType::from((string) $input['type']),
            caption: $input['caption'] ?? null,
            firstComment: $input['first_comment'] ?? null,
            socialAccountId: $input['social_account_id'] ?? null,
            campaignId: $input['campaign_id'] ?? null,
            scheduledAt: $agendamento,
            media: $input['media'] ?? [],
            createdBy: $createdBy,
        );
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return array_filter([
            'client_id' => $this->clientId,
            'type' => $this->type,
            'caption' => $this->caption,
            'first_comment' => $this->firstComment,
            'social_account_id' => $this->socialAccountId,
            'campaign_id' => $this->campaignId,
            'scheduled_at' => $this->scheduledAt,
            'created_by' => $this->createdBy,
        ], fn ($valor) => $valor !== null);
    }
}
