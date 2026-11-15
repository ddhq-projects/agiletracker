<?php

declare(strict_types=1);

use App\Enums\GroupMemberRole;
use App\Enums\ItemStatus;
use App\Enums\RoleName;
use App\Models\Group;
use App\Models\Item;
use App\Models\User;

/**
 * @param  array<string, mixed>  $attributes
 */
function hiddenItem(User $reporter, array $attributes = []): Item
{
    return Item::factory()->restricted()->create([
        'reporter_id' => $reporter->getKey(),
        ...$attributes,
    ]);
}

test('search returns only the items inside the perimeter', function () {
    $user = actingAsManagerSolo();
    actingAsApi(['items:read'], $user);

    $public = Item::factory()->create(['title' => 'Public task']);
    $mine = hiddenItem(User::factory()->create(), ['title' => 'Assigned to me', 'assignee_id' => $user->getKey()]);
    hiddenItem(User::factory()->create(), ['title' => 'Secret task']);

    $response = $this->postJson('/api/v1/items/search', [])->assertOk();

    expect($response->json('total'))->toBe(2)
        ->and(collect($response->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$public->getKey(), $mine->getKey()])->sort()->values()->all());
});

test('an invisible item cannot be read through a filter', function () {
    actingAsApi(['items:read']);
    $hidden = hiddenItem(User::factory()->create());

    $response = $this->postJson('/api/v1/items/search', [
        'search' => ['filters' => [['field' => 'id', 'operator' => '=', 'value' => $hidden->getKey()]]],
    ])->assertOk();

    expect($response->json('total'))->toBe(0);
});

test('an invisible item cannot be changed or deleted', function () {
    actingAsApi(['items:write', 'items:read'], actingAsManagerSolo());
    $hidden = hiddenItem(User::factory()->create(), ['title' => 'Secret task']);

    $this->postJson('/api/v1/items/mutate', [
        'mutate' => [['operation' => 'update', 'key' => $hidden->getKey(), 'attributes' => ['title' => 'Rewritten']]],
    ])->assertUnprocessable()->assertJsonValidationErrors('mutate.0.key');

    $this->deleteJson('/api/v1/items', ['resources' => [$hidden->getKey()]])
        ->assertUnprocessable()->assertJsonValidationErrors('resources.0');

    expect($hidden->fresh()->title)->toBe('Secret task')
        ->and($hidden->fresh()->trashed())->toBeFalse();
});

test('the reporter keeps access to the item through the api', function () {
    $reporter = actingAsManagerSolo();
    actingAsApi(['items:write', 'items:read'], $reporter);
    $item = hiddenItem($reporter, ['title' => 'Mine']);

    $this->postJson('/api/v1/items/mutate', [
        'mutate' => [['operation' => 'update', 'key' => $item->getKey(), 'attributes' => ['title' => 'Renamed']]],
    ])->assertOk();

    expect($item->fresh()->title)->toBe('Renamed');
});

test('the assignee may only write the workflow fields', function () {
    $user = actingAsManagerSolo();
    actingAsApi(['items:write', 'items:read'], $user);
    $item = hiddenItem(User::factory()->create(), ['assignee_id' => $user->getKey()]);

    $this->postJson('/api/v1/items/mutate', [
        'mutate' => [['operation' => 'update', 'key' => $item->getKey(), 'attributes' => ['title' => 'Rewritten']]],
    ])->assertUnprocessable()->assertJsonValidationErrors('mutate.0.attributes.title');

    $this->postJson('/api/v1/items/mutate', [
        'mutate' => [['operation' => 'update', 'key' => $item->getKey(), 'attributes' => ['status' => ItemStatus::Done->value]]],
    ])->assertOk();

    expect($item->fresh()->status)->toBe(ItemStatus::Done)
        ->and($item->fresh()->title)->not->toBe('Rewritten');
});

test('only the reporter changes the access fields through the api', function () {
    $auditor = actingAsManagerSolo();
    $project = Group::factory()->project()->create();
    $project->users()->syncWithoutDetaching([$auditor->getKey() => ['role' => GroupMemberRole::Manager->value]]);
    actingAsApi(['items:write', 'items:read'], $auditor);

    $item = hiddenItem(User::factory()->create(), ['project_group_id' => $project->getKey()]);

    $this->postJson('/api/v1/items/mutate', [
        'mutate' => [['operation' => 'update', 'key' => $item->getKey(), 'attributes' => ['visibility' => 'public']]],
    ])->assertStatus(403);

    expect($item->fresh()->visibility->value)->toBe('restricted');
});

test('the project of an item must be a project group', function () {
    $reporter = actingAsManagerSolo();
    actingAsApi(['items:write'], $reporter);
    $team = Group::factory()->team()->create();
    $project = Group::factory()->project()->create();

    $this->postJson('/api/v1/items/mutate', [
        'mutate' => [['operation' => 'create', 'attributes' => [
            'title' => 'With team',
            'type' => 'task',
            'project_group_id' => $team->getKey(),
        ]]],
    ])->assertUnprocessable()->assertJsonValidationErrors('mutate.0.attributes.project_group_id');

    $this->postJson('/api/v1/items/mutate', [
        'mutate' => [['operation' => 'create', 'attributes' => [
            'title' => 'With project',
            'type' => 'task',
            'project_group_id' => $project->getKey(),
        ]]],
    ])->assertOk();
});

test('an invisible item cannot become a parent', function () {
    $reporter = actingAsManagerSolo();
    actingAsApi(['items:write'], $reporter);
    $hidden = hiddenItem(User::factory()->create());

    $this->postJson('/api/v1/items/mutate', [
        'mutate' => [['operation' => 'create', 'attributes' => [
            'title' => 'Child of a secret',
            'type' => 'task',
            'parent_id' => $hidden->getKey(),
        ]]],
    ])->assertUnprocessable()->assertJsonValidationErrors('mutate.0.attributes.parent_id');
});

test('a token ability stays independent from the role', function () {
    $admin = User::factory()->create();
    $admin->syncRoles([RoleName::Admin->value]);
    actingAsApi([], $admin);

    $this->postJson('/api/v1/items/search', [])->assertStatus(403);
});

test('a token cannot read a restricted item its user cannot see', function () {
    actingAsApi(['items:read']);
    $hidden = hiddenItem(User::factory()->create());

    $response = $this->postJson('/api/v1/items/search', [
        'search' => ['filters' => [['field' => 'id', 'operator' => '=', 'value' => $hidden->getKey()]]],
    ])->assertOk();

    expect($response->json('total'))->toBe(0);
});
