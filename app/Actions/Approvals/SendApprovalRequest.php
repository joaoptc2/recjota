<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Models\Post;
use App\Models\User;
use App\Notifications\PostAwaitingApproval;
use App\Support\Enums\RoleName;
use App\Support\Enums\UserType;
use Illuminate\Support\Collection;

/**
 * Abre o pedido e avisa quem decide (Seção 6.6 / 6.11).
 *
 * Cada aprovador recebe um link próprio — a decisão fica atribuída a uma
 * pessoa, e revogar o acesso de uma não derruba o das outras.
 */
class SendApprovalRequest
{
    public function __construct(
        private readonly RequestApproval $abrirPedido,
        private readonly IssueApprovalLink $emitirLink,
    ) {}

    /**
     * @return array<int, array{email: string, url: string}> Links emitidos, para
     *                                                       a agência copiar ou mandar por WhatsApp.
     */
    public function __invoke(Post $post, ?int $solicitanteId = null): array
    {
        $aprovacao = ($this->abrirPedido)($post, $solicitanteId);
        $post->refresh();

        $emitidos = [];

        foreach ($this->aprovadores($post) as $aprovador) {
            $link = ($this->emitirLink)->forPost($post, $aprovador->email, $aprovador->name);

            $aprovador->notify(new PostAwaitingApproval($post, $aprovacao, $link['url']));

            $emitidos[] = ['email' => $aprovador->email, 'url' => $link['url'], 'nome' => $aprovador->name];
        }

        // Cliente sem aprovador cadastrado ainda precisa de um link: a agência
        // manda por WhatsApp. Ficar em silêncio seria o pior desfecho.
        if ($emitidos === []) {
            $link = ($this->emitirLink)->forPost($post);
            $emitidos[] = ['email' => null, 'url' => $link['url'], 'nome' => null];
        }

        return $emitidos;
    }

    /** @return Collection<int, User> */
    private function aprovadores(Post $post): Collection
    {
        return $post->client->users()
            ->where('users.type', UserType::Client->value)
            ->where('users.is_active', true)
            ->wherePivot('role', RoleName::ClientAdmin->value)
            ->get();
    }
}
