<?php

declare(strict_types=1);

use App\Actions\SyncGroupMembers;
use App\Enums\GroupMemberRole;
use App\Enums\GroupType;
use App\Enums\RoleName;
use App\Filament\Settings\Resources\GroupResource;
use App\Filament\Settings\Resources\GroupResource\Pages\CreateGroup;
use App\Filament\Settings\Resources\GroupResource\Pages\EditGroup;
use App\Models\Group;
use App\Models\Item;
use App\Models\User;
use App\Policies\GroupPolicy;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('settings'));
    actingAsRole(RoleName::Admin);
});

// ─── Access ──────────────────────────────────────────────────────────────

test('groups are an administrative area', function (RoleName $role, int $status) {
    actingAsRole($role);

    $this->get('/settings/groups')->assertStatus($status);
})->with([
    'admin' => [RoleName::Admin, 200],
    'manager' => [RoleName::Manager, 403],
    'user' => [RoleName::User, 403],
]);

test('a non-administrator cannot create a group', function () {
    $group = Group::factory()->create();

    expect(app(GroupPolicy::class)->create(User::factory()->create()))
        ->toBeFalse()
        ->and(app(GroupPolicy::class)->update(User::factory()->create(), $group))
        ->toBeFalse();
});

// ─── Composition ─────────────────────────────────────────────────────────

test('an administrator creates a typed group with members and managers', function () {
    $member = User::factory()->create();
    $manager = User::factory()->create();

    Livewire::test(CreateGroup::class)
        ->fillForm([
            'name' => 'Platform team',
            'type' => GroupType::Team->value,
            'description' => 'Owns the shared services',
            'member_ids' => [$member->getKey()],
            'manager_ids' => [$manager->getKey()],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $group = Group::query()->where('name', 'Platform team')->firstOrFail();

    expect($group->type)->toBe(GroupType::Team)
        ->and($group->members()->pluck('users.id')->all())->toBe([$member->getKey()])
        ->and($group->managers()->pluck('users.id')->all())->toBe([$manager->getKey()]);
});

test('a user listed as both member and manager ends up a manager', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create();

    app(SyncGroupMembers::class)($group, [$user->getKey()], [$user->getKey()]);

    expect($group->fresh()->users()->first()->pivot->role)->toBe(GroupMemberRole::Manager->value);
});

test('the composition difference is recorded as group_members_updated', function () {
    $group = Group::factory()->create();
    $stays = User::factory()->create();
    $leaves = User::factory()->create();
    $joins = User::factory()->create();

    app(SyncGroupMembers::class)($group, [$stays->getKey(), $leaves->getKey()], []);

    app(SyncGroupMembers::class)($group, [$stays->getKey(), $joins->getKey()], [$joins->getKey()]);

    $activity = $group->activitiesAsSubject()->where('event', 'group_members_updated')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->attribute_changes->get('attributes')['members_added'])->toBe([$joins->getKey()])
        ->and($activity->attribute_changes->get('attributes')['members_removed'])->toBe([$leaves->getKey()])
        ->and($activity->attribute_changes->get('attributes')['member_roles'])->toBe([$joins->getKey() => 'manager']);
});

test('editing a group replaces its composition', function () {
    $group = Group::factory()->create();
    $old = User::factory()->create();
    $new = User::factory()->create();
    app(SyncGroupMembers::class)($group, [$old->getKey()], []);

    Livewire::test(EditGroup::class, ['record' => $group->getKey()])
        ->fillForm(['member_ids' => [$new->getKey()], 'manager_ids' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($group->fresh()->members()->pluck('users.id')->all())->toBe([$new->getKey()]);
});

// ─── Integrity ───────────────────────────────────────────────────────────

test('a group cannot become its own parent or a descendant of itself', function () {
    $root = Group::factory()->create();
    $child = Group::factory()->childOf($root)->create();
    $grandchild = Group::factory()->childOf($child)->create();

    expect(fn () => $root->update(['parent_id' => $root->getKey()]))
        ->toThrow(ValidationException::class);

    expect(fn () => $root->update(['parent_id' => $grandchild->getKey()]))
        ->toThrow(ValidationException::class);

    expect($root->fresh()->parent_id)->toBeNull();
});

test('the parent options leave out the group and its descendants', function () {
    $root = Group::factory()->create();
    $child = Group::factory()->childOf($root)->create();
    $sibling = Group::factory()->create();

    $options = GroupResource::parentOptions($root);

    expect($options)->toHaveKey($sibling->getKey())
        ->and($options)->not->toHaveKey($child->getKey())
        ->and($options)->not->toHaveKey($root->getKey());
});

test('a project group that names existing items cannot be deleted', function () {
    $project = Group::factory()->project()->create();
    Item::factory()->create(['project_group_id' => $project->getKey()]);

    expect(fn () => $project->delete())->toThrow(ValidationException::class);

    expect(Group::query()->whereKey($project->getKey())->exists())->toBeTrue();
});

test('deleting a user removes only the membership', function () {
    $group = Group::factory()->team()->create();
    $user = User::factory()->create();
    app(SyncGroupMembers::class)($group, [$user->getKey()], []);

    $user->delete();

    expect($group->users()->count())->toBe(0)
        ->and(Group::query()->whereKey($group->getKey())->exists())->toBeTrue();
});

test('a group change is tracked on its own fields', function () {
    $group = Group::factory()->create(['name' => 'Old name']);

    $group->update(['name' => 'New name', 'description' => 'Now described']);

    $activity = $group->activitiesAsSubject()->latest('id')->first();

    expect($activity->attribute_changes->get('attributes'))->toMatchArray([
        'name' => 'New name',
        'description' => 'Now described',
    ]);
});
