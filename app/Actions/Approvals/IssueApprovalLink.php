<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Models\ApprovalLink;
use App\Models\Client;
use App\Models\Post;
use App\Support\Enums\ApprovalLinkScope;
use Illuminate\Support\Carbon;

/**
 * Emite o link mágico de aprovação (Seção 6.6 / 10).
 *
 * Este é o caminho PRIMÁRIO de aprovação — o login é a alternativa. O token em
 * claro existe só no retorno deste método, para entrar no e-mail; o banco
 * guarda apenas o hash.
 *
 * @phpstan-type LinkEmitido array{link: ApprovalLink, token: string, url: string}
 */
class IssueApprovalLink
{
    /** @return array{link: ApprovalLink, token: string, url: string} */
    public function forPost(Post $post, ?string $email = null, ?string $nome = null): array
    {
        $token = ApprovalLink::generateToken();

        $link = ApprovalLink::create([
            'client_id' => $post->client_id,
            'post_id' => $post->getKey(),
            'token' => ApprovalLink::hashToken($token),
            'scope' => ApprovalLinkScope::SinglePost,
            'expires_at' => $this->validade($post->client),
            'max_uses' => 20,
            'recipient_email' => $email,
            'recipient_name' => $nome,
            // Amarrado à versão: editar o post mata este link.
            'bound_version' => $post->current_version,
            'created_by' => auth()->id(),
        ]);

        return [
            'link' => $link,
            'token' => $token,
            'url' => route('aprovacao.post', ['token' => $token]),
        ];
    }

    /** Link de lote: tudo o que estiver pendente para o cliente. */
    public function forBatch(Client $client, ?string $email = null, ?string $nome = null): array
    {
        $token = ApprovalLink::generateToken();

        $link = ApprovalLink::create([
            'client_id' => $client->getKey(),
            'token' => ApprovalLink::hashToken($token),
            'scope' => ApprovalLinkScope::Batch,
            'expires_at' => $this->validade($client),
            'max_uses' => 50,
            'recipient_email' => $email,
            'recipient_name' => $nome,
            'created_by' => auth()->id(),
        ]);

        return [
            'link' => $link,
            'token' => $token,
            'url' => route('aprovacao.lote', ['token' => $token]),
        ];
    }

    private function validade(Client $client): Carbon
    {
        $prazo = $client->settings?->approval_deadline_hours
            ?? config('agency.approval.default_deadline_hours');

        // O link dura o prazo de decisão mais folga, com um teto — link eterno
        // é credencial permanente circulando por e-mail.
        return now()->addHours(min($prazo * 3, config('agency.approval.magic_link_ttl_hours')));
    }
}
