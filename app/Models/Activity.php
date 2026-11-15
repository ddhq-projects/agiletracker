<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\RedactedActivityData;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * The application's activity model. The stored rows are unchanged; the two
 * data columns are read through a cast that masks secrets, so every consumer
 * — the item timeline, the settings journal and any future report — sees the
 * same safe values.
 */
class Activity extends BaseActivity
{
    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'attribute_changes' => RedactedActivityData::class,
            'properties' => RedactedActivityData::class,
        ];
    }
}
