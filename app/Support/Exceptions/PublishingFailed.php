<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * Falha do motor de publicação que não vem da API em si: container rejeitado
 * (ERROR/EXPIRED), processamento que estourou o prazo, post fora do estado
 * esperado. Carrega a classificação permanente/transitória que o tratador de
 * falhas usa para decidir entre backoff e alerta, e a mensagem já é a que o
 * gestor vai ler.
 */
final class PublishingFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent)
    {
        parent::__construct($message);
    }

    public static function containerRejected(?string $motivo): self
    {
        return new self(sprintf(
            'O Instagram recusou a mídia deste post%s. Confira formato, proporção e duração, substitua o arquivo e reagende.',
            $motivo !== null && $motivo !== '' ? ' ("'.trim($motivo).'")' : '',
        ), permanent: true);
    }

    public static function containerTimedOut(int $minutos): self
    {
        return new self(sprintf(
            'O Instagram não terminou de processar a mídia em %d minutos. O sistema vai tentar de novo automaticamente.',
            $minutos,
        ), permanent: false);
    }

    public static function unexpectedState(string $detalhe): self
    {
        return new self('A publicação foi interrompida: '.$detalhe, permanent: false);
    }

    public function isPermanent(): bool
    {
        return $this->permanent;
    }
}
