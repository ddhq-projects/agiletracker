<?php

declare(strict_types=1);

namespace App\Models;

use App\Access\ItemAccess;
use App\Enums\ItemStatus;
use App\Enums\ItemVisibility;
use App\Models\Concerns\Favoritable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Relaticle\ActivityLog\Concerns\InteractsWithTimeline;
use Relaticle\ActivityLog\Contracts\HasTimeline;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Relaticle\Comments\Concerns\HasComments;
use Relaticle\Comments\Contracts\Commentable;
use Relaticle\Flowforge\Services\DecimalPosition;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Item extends Model implements Commentable, HasTimeline
{
    use Favoritable, HasComments, HasFactory, InteractsWithTimeline, LogsActivity, SoftDeletes;

    /**
     * A new model carries the default workflow state from the start, so the
     * first update of any other attribute does not read as a status change.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'todo',
    ];

    protected $fillable = [
        'title',
        'status',
        'description',
        'is_epic',
        'meta',
        'parent_id',
        'project_group_id',
        'visibility',
        'assignee_id',
        'reporter_id',
        'due_date',
        'start_date',
        'end_date',
        'etd_date',
        'eta_date',
        'position',
        'to',
        'cc',
    ];

    protected $casts = [
        'status' => ItemStatus::class,
        'visibility' => ItemVisibility::class,
        'is_epic' => 'boolean',
        'due_date' => 'date',
        'start_date' => 'date',
        'end_date' => 'date',
        'etd_date' => 'date',
        'eta_date' => 'date',
        'meta' => 'array',
    ];

    /**
     * New items land at the bottom of their workflow column. Flowforge only
     * recalculates positions on a move, so without this a fresh card would sit
     * unordered until someone dragged it.
     */
    protected static function booted(): void
    {
        static::creating(function (Item $item): void {
            if ($item->visibility === null) {
                $item->visibility = ItemVisibility::Restricted;
            }

            if ($item->position !== null) {
                return;
            }

            $status = $item->status instanceof ItemStatus ? $item->status : ItemStatus::from((string) ($item->status ?? ItemStatus::Todo->value));

            $last = static::withTrashed()
                ->where('status', $status->value)
                ->max('position');

            $item->position = $last === null
                ? DecimalPosition::forEmptyColumn()
                : DecimalPosition::after((string) $last);
        });
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Item::class, 'parent_id');
    }

    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'item_label');
    }

    public function boards(): BelongsToMany
    {
        return $this->belongsToMany(Board::class, 'board_items')
            ->withPivot(['board_column_id', 'position'])
            ->withTimestamps();
    }

    public function boardItems(): HasMany
    {
        return $this->hasMany(BoardItem::class);
    }

    public function to(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'item_to');
    }

    public function cc(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'item_cc');
    }

    public function projectGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'project_group_id');
    }

    public function accessGroups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'item_group')->withTimestamps();
    }

    // ─── Visibility ───────────────────────────────────────────────────────────

    public function isPublic(): bool
    {
        return $this->visibility === ItemVisibility::Public;
    }

    public function isRestricted(): bool
    {
        return ! $this->isPublic();
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeRootLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assignee_id', $userId);
    }

    public function scopeWithLabel($query, string $labelName)
    {
        return $query->whereHas('labels', fn ($q) => $q->where('name', $labelName));
    }

    /**
     * The row-level perimeter, delegating to the single access service.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return app(ItemAccess::class)->scopeVisibleTo($query, $user);
    }

    // ─── Activity log ─────────────────────────────────────────────────────────

    public function timeline(): TimelineBuilder
    {
        return TimelineBuilder::make($this)->fromActivityLog();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'title',
                'status',
                'assignee_id',
                'parent_id',
                'meta',
                'is_epic',
                'project_group_id',
                'visibility',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
