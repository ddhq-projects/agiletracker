<?php

declare(strict_types=1);

use App\Enums\ItemStatus;
use App\Models\Item;

test('creation defaults the status to todo', function () {
    actingAsApi(['items:write'], actingAsManagerSolo());

    $response = $this->postJson('/api/v1/items/mutate', [
        'mutate' => [['operation' => 'create', 'attributes' => ['title' => 'No status sent', 'type' => 'task']]],
        'response' => ['selects' => [['field' => 'status']]],
    ])->assertOk();

    expect($response->json('records.0.status'))->toBe(ItemStatus::Todo->value)
        ->and(Item::firstOrFail()->status)->toBe(ItemStatus::Todo);
});

test('a supplied status is written and read back', function () {
    actingAsApi(['items:write'], actingAsManagerSolo());

    $response = $this->postJson('/api/v1/items/mutate', [
        'mutate' => [['operation' => 'create', 'attributes' => [
            'title' => 'Review the RFC',
            'type' => 'task',
            'status' => ItemStatus::Review->value,
        ]]],
        'response' => ['selects' => [['field' => 'status']]],
    ])->assertOk();

    expect($response->json('records.0.status'))->toBe('review')
        ->and(Item::firstOrFail()->status)->toBe(ItemStatus::Review);
});

test('creation refuses a status outside the workflow', function () {
    actingAsApi(['items:write'], actingAsManagerSolo());

    $this->postJson('/api/v1/items/mutate', [
        'mutate' => [['operation' => 'create', 'attributes' => ['title' => 'Bad', 'type' => 'task', 'status' => 'archived']]],
    ])->assertUnprocessable()->assertJsonValidationErrors('mutate.0.attributes.status');

    expect(Item::count())->toBe(0);
});

test('an update refuses an unknown status and keeps the stored one', function () {
    actingAsApi(['items:write'], actingAsManagerSolo());
    $item = Item::factory()->create();

    $this->postJson('/api/v1/items/mutate', [
        'mutate' => [['operation' => 'update', 'key' => $item->id, 'attributes' => ['status' => 'blocked']]],
    ])->assertUnprocessable()->assertJsonValidationErrors('mutate.0.attributes.status');

    expect($item->fresh()->status)->toBe(ItemStatus::Todo);
});

test('an update writes a valid status', function () {
    actingAsApi(['items:write'], actingAsManagerSolo());
    $item = Item::factory()->create();

    $this->postJson('/api/v1/items/mutate', [
        'mutate' => [['operation' => 'update', 'key' => $item->id, 'attributes' => ['status' => 'done']]],
    ])->assertOk();

    expect($item->fresh()->status)->toBe(ItemStatus::Done);
});

test('items filter on the native status', function () {
    actingAsApi(['items:read']);

    Item::factory()->status(ItemStatus::Done)->create();
    Item::factory()->status(ItemStatus::Todo)->count(2)->create();

    $response = $this->postJson('/api/v1/items/search', [
        'search' => ['filters' => [['field' => 'status', 'operator' => '=', 'value' => 'done']]],
    ])->assertOk();

    expect($response->json('total'))->toBe(1)
        ->and($response->json('data.0.status'))->toBe('done');
});
