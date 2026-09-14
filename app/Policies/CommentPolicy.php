<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class CommentPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::PostsView);
    }

    /** Comentário interno nunca é visível a usuário do tipo client (Seção 5). */
    public function view(User $user, Comment $comment): bool
    {
        if ($comment->is_internal && $user->isClient()) {
            return false;
        }

        return $this->allows($user, Permission::PostsView, $comment->client_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::CommentsCreate);
    }

    public function createInternal(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::CommentsInternal);
    }

    public function update(User $user, Comment $comment): bool
    {
        return $this->view($user, $comment)
            && $comment->user_id === $user->getKey();
    }

    public function delete(User $user, Comment $comment): bool
    {
        if ($this->update($user, $comment)) {
            return true;
        }

        return $this->allows($user, Permission::PostsDelete, $comment->client_id);
    }

    public function restore(User $user, Comment $comment): bool
    {
        return $this->delete($user, $comment);
    }

    public function forceDelete(User $user, Comment $comment): bool
    {
        return $this->delete($user, $comment);
    }

    public function resolve(User $user, Comment $comment): bool
    {
        return $this->view($user, $comment) && $user->isAgency();
    }
}
