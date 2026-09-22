<?php

declare(strict_types=1);

namespace App\Jobs\Publishing\Concerns;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Tenancy\TenantContext;

/**
 * O que os três jobs do motor têm em comum: carregar o post fora do escopo de
 * tenant (job não tem usuário) e conferir que ainda é a mesma versão que foi
 * despachada. Versão diferente = alguém editou no meio; o despachante decide
 * de novo na próxima passada.
 */
trait LoadsPublishingPost
{
    public int $tries = 1;

    public function __construct(
        public readonly int $postId,
        public readonly int $version,
    ) {}

    /** Post com as relações que o motor usa, ou null se sumiu ou mudou de versão. */
    protected function loadPost(): ?Post
    {
        return app(TenantContext::class)->withoutRestriction(function (): ?Post {
            $post = Post::query()
                ->withoutClientScope()
                ->with(['socialAccount', 'client', 'author', 'postMedia.mediaAsset'])
                ->find($this->postId);

            if ($post === null || $post->current_version !== $this->version) {
                return null;
            }

            return $post;
        });
    }

    protected function token(SocialAccount $conta): string
    {
        return (string) $conta->access_token;
    }
}
