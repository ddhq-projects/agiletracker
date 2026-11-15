<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Relaticle\Comments\Livewire\Comments;

test('a comment written in the comments component is stored polymorphically', function () {
    $user = actingAsUser();
    $item = Item::factory()->create();

    Livewire::test(Comments::class, ['model' => $item])
        ->set('commentData.body', '<p>Hello team</p>')
        ->call('addComment');

    $comment = Comment::firstOrFail();

    expect($comment->commentable_type)->toBe(Item::class)
        ->and((int) $comment->commentable_id)->toBe($item->id)
        ->and($comment->commenter_type)->toBe(User::class)
        ->and((int) $comment->commenter_id)->toBe($user->getKey())
        ->and($comment->item_id)->toBe($item->id)
        ->and($comment->user_id)->toBe($user->getKey())
        ->and($comment->item?->is($item))->toBeTrue()
        ->and($comment->author?->is($user))->toBeTrue();
});

test('comment bodies are sanitized before they are stored', function () {
    $user = actingAsUser();
    $item = Item::factory()->create();

    $comment = Comment::create([
        'item_id' => $item->id,
        'user_id' => $user->getKey(),
        'body' => '<p>Hi<script>alert(1)</script><a href="javascript:alert(2)">link</a></p>',
    ]);

    expect($comment->fresh()->body)->not->toContain('<script>')
        ->and($comment->fresh()->body)->not->toContain('javascript:')
        ->and($comment->fresh()->body)->toContain('Hi');
});

test('the item exposes its comments and their count through the package trait', function () {
    $user = actingAsUser();
    $item = Item::factory()->create();

    Comment::create(['item_id' => $item->id, 'user_id' => $user->getKey(), 'body' => 'One']);
    Comment::create(['item_id' => $item->id, 'user_id' => $user->getKey(), 'body' => 'Two']);

    expect($item->commentCount())->toBe(2)
        ->and($item->comments()->count())->toBe(2)
        ->and($item->topLevelComments()->count())->toBe(2);
});

test('threading stops at the configured depth', function () {
    $user = actingAsUser();
    $item = Item::factory()->create();

    $root = Comment::create(['item_id' => $item->id, 'user_id' => $user->getKey(), 'body' => 'Root']);
    $reply = Comment::create([
        'item_id' => $item->id,
        'user_id' => $user->getKey(),
        'body' => 'Reply',
        'parent_id' => $root->id,
    ]);
    $nested = Comment::create([
        'item_id' => $item->id,
        'user_id' => $user->getKey(),
        'body' => 'Nested',
        'parent_id' => $reply->id,
    ]);

    expect(Gate::forUser($user)->allows('reply', $root))->toBeTrue()
        ->and(Gate::forUser($user)->allows('reply', $nested))->toBeFalse();
});

test('attachments, notifications, subscriptions and broadcasting stay off', function () {
    expect(config('comments.attachments.enabled'))->toBeFalse()
        ->and(config('comments.notifications.enabled'))->toBeFalse()
        ->and(config('comments.subscriptions.auto_subscribe'))->toBeFalse()
        ->and(config('comments.broadcasting.enabled'))->toBeFalse()
        ->and(config('comments.mentions.enabled'))->toBeTrue()
        ->and(config('comments.pinning.enabled'))->toBeTrue()
        ->and(config('comments.models.comment'))->toBe(Comment::class);
});

test('only the author or an admin may pin a comment', function () {
    $author = actingAsUser();
    $item = Item::factory()->create();
    $comment = Comment::create(['item_id' => $item->id, 'user_id' => $author->getKey(), 'body' => 'Pin me']);

    expect(Gate::forUser($author)->allows('pin', $comment))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('pin', $comment))->toBeFalse();
});

test('the item view offers the comments action instead of the relation manager', function () {
    actingAsUser();
    $item = Item::factory()->create();

    $this->get("/i/{$item->id}")->assertOk()->assertSee('Comments');
});
