<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GroupMemberRole;
use App\Enums\RoleName;
use App\Models\Concerns\Favoritable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Relaticle\Comments\Concerns\CanComment;
use Relaticle\Comments\Contracts\Commentator;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements Commentator, FilamentUser
{
    use CanComment, Favoritable, HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'settings' => $this->hasRole('admin'),
            default => true,
        };
    }

    /**
     * A user always holds one base role: anything created without an explicit
     * role starts as `user`.
     */
    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if ($user->roles()->exists()) {
                return;
            }

            $user->assignRole(Role::findOrCreate(RoleName::User->value, 'web'));
        });
    }

    public function assignedItems(): HasMany
    {
        return $this->hasMany(Item::class, 'assignee_id');
    }

    public function reportedItems(): HasMany
    {
        return $this->hasMany(Item::class, 'reporter_id');
    }

    public function toItems(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'item_to');
    }

    public function ccItems(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'item_cc');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commenter');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    // ─── Groups ───────────────────────────────────────────────────────────────

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function managedGroups(): BelongsToMany
    {
        return $this->groups()->wherePivot('role', GroupMemberRole::Manager->value);
    }

    // ─── Activity log ─────────────────────────────────────────────────────────

    /**
     * Only identity fields are tracked. Role changes are a separate
     * `roles_updated` event and passwords never reach the log at all.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
