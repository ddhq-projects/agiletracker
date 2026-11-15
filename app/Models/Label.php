<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Label extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'color'];

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'item_label');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'color'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
