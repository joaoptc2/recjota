<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

use App\Support\Enums\AccountType;

/**
 * Resposta de GET /me?fields=id,user_id,username,name,account_type,profile_picture_url.
 *
 * `id` é o ID do usuário no escopo do app; `igUserId` é o ID da conta
 * profissional (user_id), usado em /{ig-user-id}/media. Quando a API não
 * informa user_id, os dois coincidem.
 */
final readonly class InstagramProfile
{
    public function __construct(
        public string $id,
        public string $igUserId,
        public ?string $username,
        public ?string $name,
        public AccountType $accountType,
        public ?string $profilePictureUrl,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public static function fromApi(array $payload): self
    {
        $tipo = strtolower((string) ($payload['account_type'] ?? 'business'));

        return new self(
            id: (string) $payload['id'],
            igUserId: (string) ($payload['user_id'] ?? $payload['id']),
            username: isset($payload['username']) ? (string) $payload['username'] : null,
            name: isset($payload['name']) ? (string) $payload['name'] : null,
            // MEDIA_CREATOR é o valor que a API devolve para contas Creator.
            accountType: str_contains($tipo, 'creator') ? AccountType::Creator : AccountType::Business,
            profilePictureUrl: isset($payload['profile_picture_url']) ? (string) $payload['profile_picture_url'] : null,
        );
    }
}
