<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Comment;
use App\Models\User;

/**
 * A comment is only reachable through its item: the inherited `ItemPolicy`
 * check comes first, and the author/moderator rules apply after it.
 */
class CommentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::CommentsView->value);
    }

    public function view(User $user, Comment $comment): bool
    {
        return $user->can(PermissionName::CommentsView->value)
            && $this->canReachItem($user, $comment);
    }

    /**
     * The commentable and commenter are filled by the application, so the
     * policy only decides that the user may comment at all.
     */
    public function create(User $user, mixed $draft = null): bool
    {
        return $user->can(PermissionName::CommentsCreate->value)
            && $this->draftIsReachable($user, $draft);
    }

    public function update(User $user, Comment $comment): bool
    {
        return $this->canReachItem($user, $comment)
            && ($this->moderates($user) || $this->isAuthor($user, $comment));
    }

    public function delete(User $user, Comment $comment): bool
    {
        return $this->update($user, $comment);
    }

    /**
     * The package caps the thread depth in `comments.threading.max_depth`.
     */
    public function reply(User $user, Comment $comment): bool
    {
        return $this->view($user, $comment) && $comment->canReply();
    }

    public function pin(User $user, Comment $comment): bool
    {
        return $this->update($user, $comment);
    }

    private function canReachItem(User $user, Comment $comment): bool
    {
        $item = $comment->commentable;

        return $item === null || $user->can('view', $item);
    }

    /**
     * A comment being written may already carry its item; when it does, the
     * draft cannot point at an item the user cannot see.
     */
    private function draftIsReachable(User $user, mixed $draft): bool
    {
        if (! $draft instanceof Comment) {
            return true;
        }

        $item = $draft->commentable;

        return $item === null || $user->can('view', $item);
    }

    private function moderates(User $user): bool
    {
        return $user->can(PermissionName::CommentsModerate->value);
    }

    private function isAuthor(User $user, Comment $comment): bool
    {
        return $comment->commenter_id === $user->getKey();
    }
}
