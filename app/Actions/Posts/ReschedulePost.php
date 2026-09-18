<?php

declare(strict_types=1);

namespace App\Actions\Posts;

use App\Models\Post;
use App\Support\Exceptions\InvalidStateTransition;
use DateTimeInterface;
use DomainException;

/**
 * Arrastar no calendário (Seção 6.3). Post publicado ou publicando não se move.
 */
class ReschedulePost
{
    public function __construct(private readonly RecordPostVersion $registrarVersao) {}

    /** @throws InvalidStateTransition|DomainException */
    public function __invoke(Post $post, ?DateTimeInterface $novaDataUtc): Post
    {
        if (! $post->status->isMovableInCalendar()) {
            throw new DomainException(sprintf(
                'Um post %s não pode ser reagendado.',
                mb_strtolower($post->status->label()),
            ));
        }

        $anterior = $post->scheduled_at;

        $post->scheduled_at = $novaDataUtc;
        $post->save();

        if ((string) $anterior === (string) $post->scheduled_at) {
            return $post;
        }

        $post->increment('current_version');
        $post->refresh();

        ($this->registrarVersao)($post, sprintf(
            'Reagendado de %s para %s',
            $anterior !== null ? display_datetime($anterior, $post->client) : 'sem data',
            $post->scheduled_at !== null ? display_datetime($post->scheduled_at, $post->client) : 'sem data',
        ));

        return $post;
    }
}
