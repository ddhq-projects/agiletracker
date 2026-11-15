<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Favorite extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'url',
        'favoritable_type',
        'favoritable_id',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Favorite $favorite): void {
            if ($favorite->position !== null) {
                return;
            }

            $favorite->position = (int) static::query()
                ->where('user_id', $favorite->user_id)
                ->max('position') + 1;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function favoritable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The personal order shared by the full page, the dashboard and the sidebar.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
