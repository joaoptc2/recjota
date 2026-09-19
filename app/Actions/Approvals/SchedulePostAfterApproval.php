<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Models\Post;
use App\Support\Enums\PostStatus;

/**
 * Publicação automática ao aprovar, quando o cliente pediu (Seção 6.6).
 *
 * Data futura entra na fila agendada; data já vencida publica na próxima
 * passagem do cron, em até cinco minutos.
 */
class SchedulePostAfterApproval
{
    public function __invoke(Post $post): Post
    {
        if ($post->client->settings?->auto_publish_on_approval !== true) {
            return $post;
        }

        if ($post->scheduled_at === null) {
            // Sem data marcada, aprovar significa "pode ir agora".
            $post->scheduled_at = now();
        }

        $post->transitionTo(PostStatus::Scheduled);
        $post->save();

        return $post;
    }
}
