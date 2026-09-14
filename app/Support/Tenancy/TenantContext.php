<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Client;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Fonte única da verdade sobre "quais clientes este contexto pode enxergar".
 *
 * Registrado como singleton por requisição. O ClientScope consulta apenas esta
 * classe — nenhuma query de domínio depende de o desenvolvedor lembrar de
 * filtrar por client_id (Seção 4.1).
 */
final class TenantContext
{
    private bool $resolved = false;

    /** Owner/admin enxergam toda a agência. */
    private bool $unrestricted = false;

    /** @var array<int, int> */
    private array $clientIds = [];

    /** Filtro adicional de "cliente ativo" escolhido na interface. */
    private ?int $activeClientId = null;

    /** Escape hatch usada por jobs e comandos de console. */
    private bool $suppressed = false;

    public function forUser(?Authenticatable $user): self
    {
        $this->resolved = true;
        $this->unrestricted = false;
        $this->clientIds = [];

        if (! $user instanceof User) {
            // Sem usuário (console, fila, link mágico): o escopo não restringe
            // sozinho. Quem precisa de restrição usa restrictToClient().
            $this->unrestricted = true;

            return $this;
        }

        if ($user->seesEveryClient()) {
            $this->unrestricted = true;

            return $this;
        }

        $this->clientIds = $user->accessibleClientIds();

        return $this;
    }

    /** Trava o contexto em um único cliente (portal do cliente, link mágico). */
    public function restrictToClient(Client|int $client): self
    {
        $id = $client instanceof Client ? $client->getKey() : $client;

        $this->resolved = true;
        $this->unrestricted = false;
        $this->clientIds = [$id];
        $this->activeClientId = null;

        return $this;
    }

    public function setActiveClient(Client|int|null $client): self
    {
        $this->activeClientId = $client instanceof Client ? $client->getKey() : $client;

        return $this;
    }

    public function activeClientId(): ?int
    {
        return $this->activeClientId;
    }

    public function isUnrestricted(): bool
    {
        $this->resolveFromAuth();

        return $this->unrestricted;
    }

    /** @return array<int, int> */
    public function allowedClientIds(): array
    {
        $this->resolveFromAuth();

        return $this->clientIds;
    }

    public function allows(Client|int|null $client): bool
    {
        if ($client === null) {
            return false;
        }

        $id = $client instanceof Client ? $client->getKey() : $client;

        return $this->isUnrestricted() || in_array($id, $this->allowedClientIds(), true);
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed;
    }

    /**
     * Executa o callback sem o escopo de tenant. Reservado para jobs de fila,
     * comandos de console e telas da agência que precisam agregar clientes.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withoutRestriction(callable $callback): mixed
    {
        $previous = $this->suppressed;
        $this->suppressed = true;

        try {
            return $callback();
        } finally {
            $this->suppressed = $previous;
        }
    }

    /** Descarta a resolução em cache (login, logout, troca de usuário em teste). */
    public function reset(): self
    {
        $this->resolved = false;
        $this->unrestricted = false;
        $this->clientIds = [];
        $this->activeClientId = null;
        $this->suppressed = false;

        return $this;
    }

    private function resolveFromAuth(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->forUser(auth()->user());
    }
}
