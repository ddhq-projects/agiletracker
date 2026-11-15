<?php

declare(strict_types=1);

use App\Models\Item;

it('logs only the tracked item attributes', function () {
    $options = (new Item)->getActivitylogOptions();

    expect($options->logAttributes)->toBe([
        'title',
        'status',
        'assignee_id',
        'parent_id',
        'meta',
        'is_epic',
        'project_group_id',
        'visibility',
    ])
        ->and($options->logOnlyDirty)->toBeTrue()
        ->and($options->logEmptyChanges)->toBeFalse();
});
