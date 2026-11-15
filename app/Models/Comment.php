<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Relaticle\Comments\Models\Comment as PackageComment;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The application's comment model. The package owns the polymorphic storage;
 * this model keeps the historical `item_id` / `user_id` names working for the
 * API contract and for code that predates the package.
 */
class Comment extends PackageComment
{
    use LogsActivity;

    protected $fillable = [
        'body',
        'parent_id',
        'commenter_id',
        'commenter_type',
        'edited_at',
        'pinned_at',
        'item_id',
        'user_id',
    ];

    /**
     * The item the comment belongs to, through the polymorphic column.
     */
    public function item(): MorphTo
    {
        return $this->morphTo('item', 'commentable_type', 'commentable_id');
    }

    /**
     * The user who wrote the comment, through the polymorphic column.
     */
    public function author(): MorphTo
    {
        return $this->morphTo('author', 'commenter_type', 'commenter_id');
    }

    protected function itemId(): Attribute
    {
        return Attribute::make(
            get: fn (): ?int => $this->commentable_id === null ? null : (int) $this->commentable_id,
            set: fn (?int $value): array => [
                'commentable_id' => $value,
                'commentable_type' => (new Item)->getMorphClass(),
            ],
        );
    }

    protected function userId(): Attribute
    {
        return Attribute::make(
            get: fn (): ?int => $this->commenter_id === null ? null : (int) $this->commenter_id,
            set: fn (?int $value): array => [
                'commenter_id' => $value,
                'commenter_type' => (new User)->getMorphClass(),
            ],
        );
    }

    // ─── Activity log ─────────────────────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['body', 'edited_at', 'pinned_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
