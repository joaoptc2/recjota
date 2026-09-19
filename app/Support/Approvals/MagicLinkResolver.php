<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\Models\ApprovalLink;
use App\Support\Tenancy\TenantContext;

/**
 * Traduz o token da URL em um link utilizável, e tranca o contexto de tenant
 * no cliente dono dele.
 *
 * Sem essa trava, uma requisição sem usuário autenticado rodaria com o escopo
 * irrestrito — e um link do cliente A enxergaria dados do cliente B.
 */
final class MagicLinkResolver
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function resolve(string $token): ApprovalLink
    {
        $link = ApprovalLink::query()
            ->withoutClientScope()
            ->with(['post.client', 'post.postMedia.mediaAsset'])
            ->where('token', ApprovalLink::hashToken($token))
            ->first();

        abort_if($link === null, 404);

        $this->tenant->reset()->restrictToClient($link->client_id);

        return $link;
    }

    public function registerUse(ApprovalLink $link): void
    {
        $link->forceFill([
            'used_count' => $link->used_count + 1,
            'last_used_at' => now(),
        ])->save();
    }
}
