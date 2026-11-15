<?php

declare(strict_types=1);

use App\Actions\BackfillItemStatus;
use App\Actions\RegenerateItemPositions;
use App\Enums\ItemStatus;
use App\Models\Item;
use App\Models\Label;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relaticle\Flowforge\Services\DecimalPosition;

// ─── Status backfill from the old workflow labels ─────────────────────────

test('a workflow label becomes the native status', function () {
    $item = Item::factory()->create();
    $item->labels()->attach(Label::create(['name' => 'In Progress']));

    (new BackfillItemStatus)();

    expect($item->fresh()->status)->toBe(ItemStatus::InProgress);
});

test('the lowercase column keys written by the old handler are recognised', function () {
    $item = Item::factory()->create();
    $item->labels()->attach(Label::create(['name' => 'in_progress']));

    (new BackfillItemStatus)();

    expect($item->fresh()->status)->toBe(ItemStatus::InProgress);
});

test('an item with several workflow labels takes the furthest state', function () {
    $item = Item::factory()->create();

    foreach (['To Do', 'In Progress', 'Review', 'Done'] as $name) {
        $item->labels()->attach(Label::create(['name' => $name]));
    }

    (new BackfillItemStatus)();

    expect($item->fresh()->status)->toBe(ItemStatus::Done);
});

test('items without a workflow label stay in the todo column', function () {
    $item = Item::factory()->create();
    $item->labels()->attach(Label::create(['name' => 'Bug']));

    (new BackfillItemStatus)();

    expect($item->fresh()->status)->toBe(ItemStatus::Todo);
});

test('only the workflow labels are detached, and the labels themselves survive', function () {
    $item = Item::factory()->create();
    $bug = Label::create(['name' => 'Bug']);
    $review = Label::create(['name' => 'Review']);
    $done = Label::create(['name' => 'Done']);

    $item->labels()->attach([$bug->id, $review->id, $done->id]);

    (new BackfillItemStatus)();

    expect($item->labels()->pluck('name')->all())->toBe(['Bug'])
        ->and(Label::query()->whereKey([$review->id, $done->id])->count())->toBe(2);
});

// ─── Decimal positions ────────────────────────────────────────────────────

test('positions are regenerated in id order, one sequence per status', function () {
    $todo = Item::factory()->count(3)->status(ItemStatus::Todo)->create();
    $done = Item::factory()->count(2)->status(ItemStatus::Done)->create();

    foreach ($todo->concat($done)->values() as $index => $item) {
        DB::table('items')->where('id', $item->id)->update(['position' => 900000 + $index]);
    }

    (new RegenerateItemPositions)();

    $first = DecimalPosition::forEmptyColumn();
    $second = DecimalPosition::after($first);
    $third = DecimalPosition::after($second);

    expect(DecimalPosition::normalize($todo[0]->fresh()->position))->toBe(DecimalPosition::normalize($first))
        ->and(DecimalPosition::normalize($todo[1]->fresh()->position))->toBe(DecimalPosition::normalize($second))
        ->and(DecimalPosition::normalize($todo[2]->fresh()->position))->toBe(DecimalPosition::normalize($third))
        ->and(DecimalPosition::normalize($done[0]->fresh()->position))->toBe(DecimalPosition::normalize($first))
        ->and(DecimalPosition::normalize($done[1]->fresh()->position))->toBe(DecimalPosition::normalize($second));
});

test('positions are regenerated for soft-deleted items too', function () {
    $item = Item::factory()->create(['position' => 42]);
    $item->delete();

    (new RegenerateItemPositions)();

    expect((float) $item->fresh()->position)->toBe((float) DecimalPosition::forEmptyColumn());
});

test('a new item takes the next position in its column', function () {
    $first = Item::factory()->create();
    $second = Item::factory()->create();

    expect((float) $second->position)->toBeGreaterThan((float) $first->position)
        ->and((float) Item::factory()->status(ItemStatus::Done)->create()->position)
        ->toBe((float) DecimalPosition::forEmptyColumn());
});

test('the database refuses two cards of one column at the same position', function () {
    expect(Schema::hasIndex('items', 'items_status_position_unique'))->toBeTrue();

    $item = Item::factory()->create();

    DB::table('items')->insert([
        'title' => 'Duplicate',
        'status' => $item->status->value,
        'position' => $item->position,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);
