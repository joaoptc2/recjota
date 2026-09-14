<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * Papéis do sistema (Seção 4.2). Os quatro primeiros pertencem à agência,
 * os dois últimos ao cliente.
 */
enum RoleName: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Gestor = 'gestor';
    case Criador = 'criador';
    case ClientAdmin = 'client_admin';
    case ClientViewer = 'client_viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Proprietário',
            self::Admin => 'Administrador',
            self::Gestor => 'Gestor de contas',
            self::Criador => 'Criador de conteúdo',
            self::ClientAdmin => 'Aprovador do cliente',
            self::ClientViewer => 'Visualizador do cliente',
        };
    }

    public function userType(): UserType
    {
        return match ($this) {
            self::Owner, self::Admin, self::Gestor, self::Criador => UserType::Agency,
            self::ClientAdmin, self::ClientViewer => UserType::Client,
        };
    }

    /** Papéis que enxergam todos os clientes da agência sem vínculo explícito. */
    public function seesEveryClient(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /** @return array<int, self> */
    public static function agencyRoles(): array
    {
        return [self::Owner, self::Admin, self::Gestor, self::Criador];
    }

    /** @return array<int, self> */
    public static function clientRoles(): array
    {
        return [self::ClientAdmin, self::ClientViewer];
    }
}
