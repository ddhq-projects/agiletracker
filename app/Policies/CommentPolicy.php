<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Comment $comment): bool
    {
        return true;
    }

    /**
     * The commentable and commenter are filled by the application, so the
     * policy only decides that the user may comment at all.
     */
    public function create(User $user, mixed $draft = null): bool
    {
        return true;
    }

    public function update(User $user, Comment $comment): bool
    {
        return $this->isAuthorOrAdmin($user, $comment);
    }

    public function delete(User $user, Comment $comment): bool
    {
        return $this->isAuthorOrAdmin($user, $comment);
    }

    /**
     * The package caps the thread depth in `comments.threading.max_depth`.
     */
    public function reply(User $user, Comment $comment): bool
    {
        return $comment->canReply();
    }

    public function pin(User $user, Comment $comment): bool
    {
        return $this->isAuthorOrAdmin($user, $comment);
    }

    private function isAuthorOrAdmin(User $user, Comment $comment): bool
    {
        return $comment->commenter_id === $user->getKey() || $user->hasRole('admin');
    }
}
