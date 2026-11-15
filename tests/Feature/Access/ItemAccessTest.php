<?php

declare(strict_types=1);

use App\Access\ItemAccess;
use App\Actions\AddItemsToBoard;
use App\Enums\GroupMemberRole;
use App\Enums\ItemStatus;
use App\Enums\RoleName;
use App\Filament\Resources\ItemResource;
use App\Filament\Resources\ItemResource\Pages\CreateItem;
use App\Filament\Resources\ItemResource\Pages\EditItem;
use App\Filament\Resources\ItemResource\Pages\ListItems;
use App\Models\Board;
use App\Models\Comment;
use App\Models\Group;
use App\Models\Item;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('main'));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function restrictedItem(User $reporter, array $attributes = []): Item
{
    return Item::factory()->restricted()->create([
        'reporter_id' => $reporter->getKey(),
        ...$attributes,
    ]);
}

function joinGroup(Group $group, User $user, GroupMemberRole $role = GroupMemberRole::Member): void
{
    $group->users()->syncWithoutDetaching([$user->getKey() => ['role' => $role->value]]);
}

// ─── Public items ────────────────────────────────────────────────────────

test('a public item is visible to every role and follows the global matrix', function (RoleName $role, bool $update, bool $delete) {
    $item = Item::factory()->create();
    actingAsRole($role);

    expect(Gate::allows('view', $item))->toBeTrue()
        ->and(Gate::allows('update', $item))->toBe($update)
        ->and(Gate::allows('delete', $item))->toBe($delete);
})->with([
    'admin' => [RoleName::Admin, true, true],
    'manager' => [RoleName::Manager, true, true],
    'user' => [RoleName::User, false, false],
]);

test('the reporter of a public item may update it with the user role', function () {
    $reporter = actingAsRole(RoleName::User);
    $item = Item::factory()->create(['reporter_id' => $reporter->getKey()]);

    expect(Gate::allows('update', $item))->toBeTrue()
        ->and(Gate::allows('delete', $item))->toBeFalse();
});

// ─── Restricted items: the Planfix matrix ────────────────────────────────

test('the reporter of a restricted item sees, changes, deletes and shares it', function () {
    $reporter = actingAsRole(RoleName::Manager);
    $item = restrictedItem($reporter);

    expect(Gate::allows('view', $item))->toBeTrue()
        ->and(Gate::allows('update', $item))->toBeTrue()
        ->and(Gate::allows('delete', $item))->toBeTrue()
        ->and(Gate::allows('manageAccess', $item))->toBeTrue()
        ->and(app(ItemAccess::class)->updatableAttributes($reporter, $item))->toBeNull();
});

test('the assignee only moves the workflow and hands the item on', function (RoleName $role) {
    $reporter = User::factory()->create();
    $assignee = actingAsRole($role);
    $item = restrictedItem($reporter, ['assignee_id' => $assignee->getKey()]);

    expect(Gate::allows('view', $item))->toBeTrue()
        ->and(Gate::allows('update', $item))->toBeTrue()
        ->and(app(ItemAccess::class)->updatableAttributes($assignee, $item))
        ->toBe(ItemAccess::LIMITED_UPDATE_ATTRIBUTES)
        ->and(Gate::allows('manageAccess', $item))->toBeFalse();
})->with([
    'user' => [RoleName::User],
    'manager' => [RoleName::Manager],
]);

test('the assignee cannot delete a restricted item', function () {
    $reporter = User::factory()->create();
    $assignee = actingAsRole(RoleName::Manager);
    $item = restrictedItem($reporter, ['assignee_id' => $assignee->getKey()]);

    expect(Gate::allows('delete', $item))->toBeFalse();
});

test('the recipients and observers read and comment but never change', function (string $relation) {
    $reporter = User::factory()->create();
    $reader = actingAsRole(RoleName::User);
    $item = restrictedItem($reporter);
    $item->{$relation}()->attach($reader);

    expect(Gate::allows('view', $item))->toBeTrue()
        ->and(Gate::allows('update', $item))->toBeFalse()
        ->and(Gate::allows('delete', $item))->toBeFalse()
        ->and(Gate::allows('manageAccess', $item))->toBeFalse();
})->with(['to', 'cc']);

test('a member of an attached group gains and loses access with the membership', function () {
    $reporter = User::factory()->create();
    $group = Group::factory()->team()->create();
    $member = actingAsRole(RoleName::User);

    $item = restrictedItem($reporter);
    $item->accessGroups()->attach($group);

    expect(Gate::allows('view', $item))->toBeFalse();

    joinGroup($group, $member);

    expect(Gate::allows('view', $item))->toBeTrue()
        ->and(Gate::allows('update', $item))->toBeFalse();

    $group->users()->detach($member->getKey());

    expect(Gate::allows('view', $item))->toBeFalse();
});

test('the manager of the project group audits the item without sharing it', function () {
    $reporter = User::factory()->create();
    $project = Group::factory()->project()->create();
    $auditor = actingAsRole(RoleName::Manager);
    joinGroup($project, $auditor, GroupMemberRole::Manager);

    $item = restrictedItem($reporter, ['project_group_id' => $project->getKey()]);

    expect(Gate::allows('view', $item))->toBeTrue()
        ->and(Gate::allows('update', $item))->toBeTrue()
        ->and(Gate::allows('delete', $item))->toBeTrue()
        ->and(Gate::allows('manageAccess', $item))->toBeFalse();
});

test('the leader of a participant inherits the rights of that participant', function () {
    $leader = actingAsRole(RoleName::User);
    $group = Group::factory()->team()->create();
    joinGroup($group, $leader, GroupMemberRole::Manager);

    $assignee = User::factory()->create();
    joinGroup($group, $assignee);

    $item = restrictedItem(User::factory()->create(), ['assignee_id' => $assignee->getKey()]);

    expect(Gate::allows('view', $item))->toBeTrue()
        ->and(app(ItemAccess::class)->updatableAttributes($leader, $item))
        ->toBe(ItemAccess::LIMITED_UPDATE_ATTRIBUTES)
        ->and(Gate::allows('delete', $item))->toBeFalse()
        ->and(Gate::allows('manageAccess', $item))->toBeFalse();
});

test('the leader of a group below the led group keeps the inherited right', function () {
    $leader = actingAsRole(RoleName::User);
    $parent = Group::factory()->team()->create();
    $child = Group::factory()->childOf($parent)->create();
    joinGroup($parent, $leader, GroupMemberRole::Manager);

    $reporter = User::factory()->create();
    joinGroup($child, $reporter);

    $item = restrictedItem($reporter);

    expect(Gate::allows('view', $item))->toBeTrue()
        ->and(Gate::allows('manageAccess', $item))->toBeFalse();
});

test('an access basis never grants more than the role allows', function () {
    $auditor = actingAsRole(RoleName::User);
    $project = Group::factory()->project()->create();
    joinGroup($project, $auditor, GroupMemberRole::Manager);

    $item = restrictedItem(User::factory()->create(), ['project_group_id' => $project->getKey()]);

    expect(Gate::allows('view', $item))->toBeTrue()
        ->and(Gate::allows('update', $item))->toBeFalse()
        ->and(Gate::allows('delete', $item))->toBeFalse();
});

test('an unrelated user does not see a restricted item', function () {
    $outsider = actingAsRole(RoleName::Manager);
    $item = restrictedItem(User::factory()->create());

    expect(Gate::allows('view', $item))->toBeFalse()
        ->and(Gate::allows('update', $item))->toBeFalse()
        ->and(Gate::allows('delete', $item))->toBeFalse()
        ->and(Gate::allows('manageAccess', $item))->toBeFalse();
});

test('an administrator reaches every restricted item', function () {
    $admin = actingAsRole(RoleName::Admin);
    $item = restrictedItem(User::factory()->create());

    expect(Gate::allows('view', $item))->toBeTrue()
        ->and(Gate::allows('update', $item))->toBeTrue()
        ->and(Gate::allows('delete', $item))->toBeTrue()
        ->and(Gate::allows('manageAccess', $item))->toBeTrue();
});

// ─── Hierarchy ───────────────────────────────────────────────────────────

test('a visible subtask does not reveal an invisible parent', function () {
    $user = actingAsRole(RoleName::User);
    $parent = restrictedItem(User::factory()->create());
    $child = restrictedItem(User::factory()->create(), [
        'parent_id' => $parent->getKey(),
        'assignee_id' => $user->getKey(),
    ]);

    expect(Gate::allows('view', $child))->toBeTrue()
        ->and(Gate::allows('view', $parent))->toBeFalse();
});

test('an invisible item is not offered as a parent', function () {
    $user = actingAsRole(RoleName::User);
    $hidden = restrictedItem(User::factory()->create());
    $visible = Item::factory()->create(['title' => 'Visible parent']);

    $options = ItemResource::parentOptions('', null);

    expect($options)->toHaveKey($visible->getKey())
        ->and($options)->not->toHaveKey($hidden->getKey());
});

// ─── Filament surfaces ───────────────────────────────────────────────────

test('the item list shows only the items inside the perimeter', function () {
    $user = actingAsRole(RoleName::User);
    $visible = Item::factory()->create(['title' => 'Public task']);
    $assigned = restrictedItem(User::factory()->create(), [
        'title' => 'Assigned task',
        'assignee_id' => $user->getKey(),
    ]);
    $hidden = restrictedItem(User::factory()->create(), ['title' => 'Secret task']);

    Livewire::test(ListItems::class)
        ->assertCanSeeTableRecords([$visible, $assigned])
        ->assertCanNotSeeTableRecords([$hidden]);
});

test('an invisible item answers 404 on its own url', function () {
    actingAsRole(RoleName::User);
    $hidden = restrictedItem(User::factory()->create());

    $this->get('/i/'.$hidden->getKey())->assertNotFound();
    $this->get('/i/'.$hidden->getKey().'/edit')->assertNotFound();
});

test('a visible item refuses an action the user may not perform', function () {
    actingAsRole(RoleName::User);
    $group = Group::factory()->team()->create();
    $item = restrictedItem(User::factory()->create());
    $item->accessGroups()->attach($group);
    joinGroup($group, auth()->user());

    $this->get('/i/'.$item->getKey())->assertOk();
    $this->get('/i/'.$item->getKey().'/edit')->assertForbidden();
});

test('the access section is reserved to whoever manages the item access', function () {
    $reporter = actingAsRole(RoleName::User);
    $item = restrictedItem($reporter);

    Livewire::test(EditItem::class, ['record' => $item->getKey()])
        ->assertFormFieldExists('visibility')
        ->assertFormFieldExists('project_group_id');
});

test('the form drops access fields for a reader of the item', function () {
    $item = restrictedItem(User::factory()->create());
    $group = Group::factory()->team()->create();
    $item->accessGroups()->attach($group);

    $reader = actingAsRole(RoleName::User);
    joinGroup($group, $reader);

    $this->get('/i/'.$item->getKey())->assertOk();

    expect(Gate::allows('manageAccess', $item))->toBeFalse()
        ->and(Gate::allows('update', $item))->toBeFalse();
});

test('the board hides the cards the user cannot see and refuses to add them', function () {
    $user = actingAsRole(RoleName::Manager);
    $board = Board::factory()->create();
    $column = $board->columns()->create(['name' => 'To Do']);

    $visible = Item::factory()->create(['title' => 'Visible card']);
    $hidden = restrictedItem(User::factory()->create(), ['title' => 'Hidden card']);

    $result = app(AddItemsToBoard::class)($board, [$visible->getKey(), $hidden->getKey()], $column);

    expect($result['added'])->toBe(1)
        ->and($board->boardItems()->count())->toBe(1);

    $boardItem = $board->boardItems()->firstOrFail();
    expect($boardItem->item_id)->toBe($visible->getKey());
});

test('a comment is only reachable through a visible item', function () {
    $item = restrictedItem(User::factory()->create());
    $comment = Comment::create([
        'item_id' => $item->getKey(),
        'user_id' => User::factory()->create()->getKey(),
        'body' => 'Secret note',
    ]);

    actingAsRole(RoleName::User);

    expect(Gate::allows('view', $comment))->toBeFalse()
        ->and(Gate::allows('create', Comment::class))->toBeTrue();
});

// ─── Writes through the form ─────────────────────────────────────────────

test('the assignee form narrows the writable fields to the workflow', function () {
    $reporter = User::factory()->create();
    $assignee = actingAsRole(RoleName::User);
    $item = restrictedItem($reporter, [
        'assignee_id' => $assignee->getKey(),
        'title' => 'Original title',
    ]);

    Livewire::test(EditItem::class, ['record' => $item->getKey()])
        ->fillForm([
            'title' => 'Rewritten title',
            'status' => ItemStatus::Done->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $item->fresh();

    expect($fresh->title)->toBe('Original title')
        ->and($fresh->status)->toBe(ItemStatus::Done);
});

test('a leader sees the item but not the access fields', function () {
    $project = Group::factory()->project()->create();
    $auditor = actingAsRole(RoleName::Manager);
    joinGroup($project, $auditor, GroupMemberRole::Manager);

    $item = restrictedItem(User::factory()->create(), ['project_group_id' => $project->getKey()]);

    // A project manager sees and changes the item, but only the reporter
    // decides who else may see it.
    expect(Gate::allows('update', $item))->toBeTrue()
        ->and(ItemResource::canManageAccess($item))->toBeFalse();

    $fields = array_keys(
        Livewire::test(EditItem::class, ['record' => $item->getKey()])
            ->instance()
            ->getSchema('form')
            ->getFlatComponents(withHidden: false),
    );

    expect($fields)
        ->not->toContain('visibility')
        ->not->toContain('access_group_ids')
        ->not->toContain('project_group_id')
        ->toContain('status');
});

test('the reporter shares the item with a group and the change is logged', function () {
    $reporter = actingAsRole(RoleName::User);
    $item = restrictedItem($reporter);
    $group = Group::factory()->team()->create();

    Livewire::test(EditItem::class, ['record' => $item->getKey()])
        ->fillForm([
            'visibility' => 'restricted',
            'access_group_ids' => [$group->getKey()],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->accessGroups->modelKeys())->toBe([$group->getKey()]);

    $activity = $item->activitiesAsSubject()->where('event', 'access_updated')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->attribute_changes->get('attributes')['groups_added'])->toBe([$group->getKey()]);
});

test('a new item starts restricted', function () {
    $user = actingAsRole(RoleName::User);

    Livewire::test(CreateItem::class)
        ->fillForm(['title' => 'Fresh task', 'status' => ItemStatus::Todo->value])
        ->call('create')
        ->assertHasNoFormErrors();

    $item = Item::query()->where('title', 'Fresh task')->firstOrFail();

    expect($item->visibility->value)->toBe('restricted')
        ->and($item->reporter_id)->toBe($user->getKey());
});
