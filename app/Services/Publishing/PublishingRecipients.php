<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Models\Post;
use App\Models\User;
use App\Support\Enums\RoleName;
use App\Support\Enums\UserType;
use Illuminate\Database\Eloquent\Collection;

/**
 * Quem recebe os avisos do motor de publicação (Seção 8.3).
 *
 * Gestores vinculados ao cliente; sem gestor, owner/admin da agência — um
 * post que falhou nunca fica sem dono. O autor entra junto no aviso de
 * sucesso e de falha.
 */
class PublishingRecipients
{
    /** @return Collection<int, User> */
    public function managers(Post $post): Collection
    {
        $cliente = $post->client;

        $gestores = $cliente === null ? new Collection : $cliente->users()
            ->where('users.type', UserType::Agency->value)
            ->where('users.is_active', true)
            ->where('client_user.role', RoleName::Gestor->value)
            ->get();

        if ($gestores->isEmpty()) {
            $gestores = User::query()
                ->where('type', UserType::Agency->value)
                ->where('is_active', true)
                ->role([RoleName::Owner->value, RoleName::Admin->value])
                ->get();
        }

        return $gestores;
    }

    /** Gestores + autor, sem repetição. @return Collection<int, User> */
    public function managersAndAuthor(Post $post): Collection
    {
        $destinatarios = $this->managers($post);
        $autor = $post->author;

        if ($autor !== null && $autor->is_active && ! $destinatarios->contains($autor)) {
            $destinatarios->push($autor);
        }

        return $destinatarios;
    }
}
