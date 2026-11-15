<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GroupMemberRole;
use App\Enums\GroupType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A typed group of users. Groups are the shared foundation of the access
 * model: a `project` group names the item's project, while any group can be
 * attached to an item as an access participant.
 */
class Group extends Model
{
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'parent_id',
        'type',
    ];

    protected $casts = [
        'type' => GroupType::class,
    ];

    protected static function booted(): void
    {
        // The parent may not be the group itself or one of its descendants.
        static::saving(function (Group $group): void {
            if ($group->parent_id === null) {
                return;
            }

            if ((int) $group->parent_id === (int) $group->getKey()) {
                throw ValidationException::withMessages([
                    'data.parent_id' => __('A group cannot be its own parent.'),
                ]);
            }

            if ($group->exists && in_array((int) $group->parent_id, $group->descendantIds(), true)) {
                throw ValidationException::withMessages([
                    'data.parent_id' => __('A group cannot be moved below one of its own descendants.'),
                ]);
            }
        });

        // A project group names the project of concrete items: deleting it
        // would leave them without one, so the items are moved first.
        static::deleting(function (Group $group): void {
            if ($group->items()->exists()) {
                throw ValidationException::withMessages([
                    'data.name' => __('The group is the project of existing items and cannot be deleted.'),
                ]);
            }
        });
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function members(): BelongsToMany
    {
        return $this->users()->wherePivot('role', GroupMemberRole::Member->value);
    }

    public function managers(): BelongsToMany
    {
        return $this->users()->wherePivot('role', GroupMemberRole::Manager->value);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'project_group_id');
    }

    // ─── Hierarchy ────────────────────────────────────────────────────────────

    /**
     * The ids of every group below this one, computed breadth-first because
     * the hierarchy is shallow and readable in memory.
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $collected = [];
        $frontier = [(int) $this->getKey()];

        while ($frontier !== []) {
            $children = static::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn (int|string $id): int => (int) $id)
                ->all();

            $children = array_values(array_diff($children, $collected, [(int) $this->getKey()]));

            if ($children === []) {
                break;
            }

            $collected = [...$collected, ...$children];
            $frontier = $children;
        }

        return $collected;
    }

    /**
     * The group itself plus every group below it, used to widen a manager's
     * reach to the sub-teams of the group they lead.
     *
     * @return list<int>
     */
    public function selfAndDescendantIds(): array
    {
        return [(int) $this->getKey(), ...$this->descendantIds()];
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeOfType(Builder $query, GroupType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    public function scopeProjects(Builder $query): Builder
    {
        return $query->ofType(GroupType::Project);
    }

    // ─── Activity log ─────────────────────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'description', 'parent_id', 'type'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
