<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\Models\Post;

/**
 * Mensagem pronta para WhatsApp (Seção 6.11).
 *
 * Link wa.me com texto pré-preenchido — não é integração com a WhatsApp
 * Business API, e não se pretende uma. A pessoa revisa antes de enviar.
 */
final class WhatsAppMessage
{
    public static function forApproval(Post $post, string $url, ?string $telefone = null): string
    {
        $texto = sprintf(
            "Oi! Separei um post de %s para você aprovar.\n\n%s\n\nÉ só abrir e tocar em Aprovar — não precisa de senha:\n%s",
            $post->client->name,
            $post->scheduled_at !== null
                ? 'Previsto para '.display_datetime($post->scheduled_at, $post->client).'.'
                : 'A data a gente combina depois.',
            $url,
        );

        $base = $telefone !== null
            ? 'https://wa.me/'.preg_replace('/\D+/', '', $telefone)
            : 'https://wa.me/';

        return $base.'?text='.rawurlencode($texto);
    }
}
