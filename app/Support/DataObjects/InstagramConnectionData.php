<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

use App\Models\Client;
use App\Models\User;

/**
 * Tudo que o ConnectInstagramAccount precisa para gravar (ou regravar) uma
 * SocialAccount: o perfil, o token de longa duração, os escopos concedidos e
 * quem consentiu.
 */
final readonly class InstagramConnectionData
{
    /** @param  array<int, string>  $scopes */
    public function __construct(
        public Client $client,
        public InstagramProfile $profile,
        public InstagramTokens $tokens,
        public array $scopes,
        public ?User $consentedBy,
    ) {}
}
