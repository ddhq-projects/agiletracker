<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Filament\Settings\Pages\ManageSettings;
use App\Filament\Settings\Resources\ActivityResource\Pages\ListActivities;
use App\Filament\Settings\Resources\ActivityResource\Pages\ViewActivity;
use App\Models\Activity;
use App\Models\Board;
use App\Models\Comment;
use App\Models\Item;
use App\Models\Label;
use App\Models\User;
use App\Support\ActivityRedactor;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

// ─── Item timeline ───────────────────────────────────────────────────────

test('the item timeline renders its own history with the causer and the diff', function () {
    Filament::setCurrentPanel(Filament::getPanel('main'));
    $author = actingAsRole(RoleName::User);
    $item = Item::factory()->create(['reporter_id' => $author->getKey(), 'title' => 'First title']);

    $item->update(['title' => 'Second title']);

    $entries = $item->paginateTimeline(perPage: 10);

    expect($entries->total())->toBeGreaterThan(0);

    $entry = collect($entries->items())->firstWhere('event', 'updated');

    expect($entry)->not->toBeNull()
        ->and($entry->causer?->getKey())->toBe($author->getKey())
        ->and($entry->properties['attributes']['title'] ?? null)->toBe('Second title');
});

test('the timeline action is part of the item page', function () {
    Filament::setCurrentPanel(Filament::getPanel('main'));
    $author = actingAsRole(RoleName::User);
    $item = Item::factory()->create(['reporter_id' => $author->getKey()]);

    $this->get('/i/'.$item->getKey())
        ->assertOk()
        ->assertSee('Activity');
});

test('position only changes do not enter the timeline', function () {
    $item = Item::factory()->create(['reporter_id' => User::factory()->create()->getKey()]);
    $item->update(['position' => '999']);

    expect($item->activitiesAsSubject()->where('event', 'updated')->count())->toBe(0);
});

// ─── Administrative journal ──────────────────────────────────────────────

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('settings'));
});

test('the journal is readable by administrators only', function (RoleName $role, int $status) {
    actingAsRole($role);

    $this->get('/settings/activity-log')->assertStatus($status);
})->with([
    'admin' => [RoleName::Admin, 200],
    'manager' => [RoleName::Manager, 403],
    'user' => [RoleName::User, 403],
]);

test('the journal has no write routes', function () {
    actingAsRole(RoleName::Admin);

    $this->get('/settings/activity-log/create')->assertNotFound();
    $this->get('/settings/activity-log/1/edit')->assertNotFound();
});

test('the journal lists, filters and opens recorded entries', function () {
    $admin = actingAsRole(RoleName::Admin);
    $item = Item::factory()->create(['reporter_id' => $admin->getKey(), 'title' => 'Original']);
    $item->update(['title' => 'Changed']);

    $activity = Activity::query()->where('subject_type', $item->getMorphClass())->latest('id')->firstOrFail();

    Livewire::test(ListActivities::class)
        ->assertCanSeeTableRecords([$activity])
        ->filterTable('event', 'updated')
        ->assertCanSeeTableRecords([$activity])
        ->filterTable('causer_id', $admin->getKey())
        ->assertCanSeeTableRecords([$activity]);

    Livewire::test(ViewActivity::class, ['record' => $activity->getKey()])
        ->assertOk()
        ->assertSee('Changed');
});

test('a deleted subject still renders in the journal', function () {
    $admin = actingAsRole(RoleName::Admin);
    $label = Label::create(['name' => 'Temporary']);
    $label->delete();

    $activity = Activity::query()->where('subject_type', (new Label)->getMorphClass())->latest('id')->firstOrFail();

    Livewire::test(ViewActivity::class, ['record' => $activity->getKey()])
        ->assertOk()
        ->assertSee('Label');
});

// ─── Tracked models ──────────────────────────────────────────────────────

test('domain changes are recorded on their own subject', function () {
    $user = User::factory()->create();

    $label = Label::create(['name' => 'Billing', 'color' => '#111111']);
    $label->update(['color' => '#222222']);

    $board = Board::factory()->create(['name' => 'Delivery']);
    $column = $board->columns()->create(['name' => 'Doing']);
    $column->update(['wip_limit' => 5]);

    $item = Item::factory()->create(['reporter_id' => $user->getKey()]);
    Comment::create(['item_id' => $item->getKey(), 'user_id' => $user->getKey(), 'body' => 'Hello']);

    expect($label->activitiesAsSubject()->latest('id')->first()->attribute_changes->get('attributes')['color'])
        ->toBe('#222222')
        ->and($board->activitiesAsSubject()->count())->toBeGreaterThan(0)
        ->and($column->activitiesAsSubject()->latest('id')->first()->attribute_changes->get('attributes')['wip_limit'])
        ->toBe(5)
        ->and(Comment::query()->latest('id')->firstOrFail()->activitiesAsSubject()->count())->toBeGreaterThan(0);
});

test('user activity tracks identity only and never the password', function () {
    $user = User::factory()->create(['name' => 'Before']);
    $user->update(['name' => 'After', 'password' => 'a-very-secret-password']);

    $attributes = $user->activitiesAsSubject()->latest('id')->first()->attribute_changes->get('attributes');

    expect($attributes)->toBe(['name' => 'After'])
        ->and($attributes)->not->toHaveKey('password');
});

test('a setting change is recorded with the safe keys only', function () {
    actingAsRole(RoleName::Admin);

    Livewire::test(ManageSettings::class)
        ->fillForm(['site_name' => 'Renamed site'])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = Activity::query()->where('event', 'settings_updated')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->attribute_changes->get('attributes'))->toHaveKey('site_name')
        ->and(array_keys($activity->attribute_changes->get('attributes')));
});

// ─── Redaction ───────────────────────────────────────────────────────────

test('the activity model masks secrets on the way out', function () {
    $id = DB::table('activity_log')->insertGetId([
        'log_name' => 'default',
        'description' => 'legacy row',
        'event' => 'updated',
        'attribute_changes' => json_encode([
            'attributes' => ['name' => 'New', 'password' => 'hunter2', 'api_token' => 'abc'],
            'old' => ['name' => 'Old', 'password' => 'hunter1', 'remember_token' => 'def'],
        ]),
        'properties' => json_encode(['stripe_secret' => 'sk_live_123', 'note' => 'kept']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $activity = Activity::query()->findOrFail($id);

    expect($activity->attribute_changes->get('attributes'))
        ->toBe(['name' => 'New', 'password' => ActivityRedactor::MASK, 'api_token' => ActivityRedactor::MASK])
        ->and($activity->attribute_changes->get('old')['remember_token'])->toBe(ActivityRedactor::MASK)
        ->and($activity->properties->get('stripe_secret'))->toBe(ActivityRedactor::MASK)
        ->and($activity->properties->get('note'))->toBe('kept');
});

test('a masked row never renders its secret in the journal', function () {
    $admin = actingAsRole(RoleName::Admin);

    $id = DB::table('activity_log')->insertGetId([
        'log_name' => 'default',
        'description' => 'legacy row',
        'event' => 'updated',
        'subject_type' => $admin->getMorphClass(),
        'subject_id' => $admin->getKey(),
        'attribute_changes' => json_encode([
            'attributes' => ['password' => 'hunter2'],
            'old' => ['password' => 'hunter1'],
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(ViewActivity::class, ['record' => $id])
        ->assertOk()
        ->assertDontSee('hunter2')
        ->assertDontSee('hunter1')
        ->assertSee(ActivityRedactor::MASK);
});
