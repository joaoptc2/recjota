<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Post;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;
use App\Support\Enums\PostStatus;

class PostPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::PostsView);
    }

    public function view(User $user, Post $post): bool
    {
        return $this->allows($user, Permission::PostsView, $post->client_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::PostsCreate);
    }

    public function update(User $user, Post $post): bool
    {
        return $this->allows($user, Permission::PostsUpdate, $post->client_id)
            && $post->status->isEditable();
    }

    public function delete(User $user, Post $post): bool
    {
        return $this->allows($user, Permission::PostsDelete, $post->client_id)
            && $post->status !== PostStatus::Published;
    }

    public function restore(User $user, Post $post): bool
    {
        return $this->delete($user, $post);
    }

    public function forceDelete(User $user, Post $post): bool
    {
        return $this->delete($user, $post);
    }

    public function schedule(User $user, Post $post): bool
    {
        return $this->allows($user, Permission::PostsSchedule, $post->client_id);
    }

    public function publish(User $user, Post $post): bool
    {
        return $this->allows($user, Permission::PostsPublish, $post->client_id);
    }

    /** Revisão interna da agência, anterior ao envio ao cliente. */
    public function reviewInternally(User $user, Post $post): bool
    {
        return $this->allows($user, Permission::PostsReviewInternal, $post->client_id);
    }

    public function requestApproval(User $user, Post $post): bool
    {
        return $this->allows($user, Permission::ApprovalsRequest, $post->client_id);
    }

    /** Decisão do cliente: aprovar, reprovar ou pedir ajustes. */
    public function decide(User $user, Post $post): bool
    {
        return $this->allows($user, Permission::ApprovalsDecide, $post->client_id);
    }

    /**
     * Quem pode reagendar. O que pode ser reagendado é outra pergunta, e ela
     * é respondida pela Action ReschedulePost: post publicado não se move
     * (Seção 6.3).
     *
     * Separar as duas coisas importa: negar por permissão é 403, negar por
     * estado é uma frase explicando o porquê.
     */
    public function reschedule(User $user, Post $post): bool
    {
        return $this->schedule($user, $post);
    }
}
