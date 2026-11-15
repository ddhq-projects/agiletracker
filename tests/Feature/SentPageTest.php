<?php

declare(strict_types=1);

use App\Enums\ItemStatus;
use App\Filament\Pages\Sent;
use App\Models\Item;
use App\Models\Label;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    actingAsUser();
    Filament::setCurrentPanel(Filament::getPanel('main'));
});

test('the sent table no longer carries a due date column', function () {
    $columns = Livewire::test(Sent::class)->instance()->getTable()->getColumns();

    expect($columns)->not->toHaveKey('due_date');
});

test('labels render under the title instead of as their own column', function () {
    $columns = Livewire::test(Sent::class)->instance()->getTable()->getColumns();

    expect(array_keys($columns))->toBe(['title', 'assignee.name', 'cc.name']);

    $item = Item::factory()->create(['reporter_id' => auth()->id(), 'title' => 'Something else']);
    $item->labels()->attach(Label::create(['name' => 'urgent-label', 'color' => '#ef4444']));

    Livewire::test(Sent::class)->assertSeeHtml('urgent-label');
});

test('the sent row actions sit behind one menu with view edit and quick change', function () {
    $item = Item::factory()->create(['reporter_id' => auth()->id()]);

    Livewire::test(Sent::class)
        ->assertActionExists(TestAction::make('view')->table($item))
        ->assertActionExists(TestAction::make('edit')->table($item))
        ->assertActionExists(TestAction::make('quickChange')->table($item))
        ->assertActionExists(TestAction::make('delete')->table($item));
});

test('quick change saves the general fields and labels without leaving the list', function () {
    $assignee = User::factory()->create();
    $item = Item::factory()->create(['reporter_id' => auth()->id()]);

    $old = Label::create(['name' => 'Old', 'color' => '#6b7280']);
    $fresh = Label::create(['name' => 'Fresh', 'color' => '#0ea5e9']);

    $item->labels()->attach($old);

    Livewire::test(Sent::class)
        ->callAction(TestAction::make('quickChange')->table($item), [
            'status' => ItemStatus::InProgress->value,
            'assignee_id' => $assignee->id,
            'labels' => [$fresh->id],
            'due_date' => '2026-12-01',
        ])
        ->assertHasNoFormErrors();

    $item->refresh();

    expect($item->status)->toBe(ItemStatus::InProgress)
        ->and($item->assignee_id)->toBe($assignee->id)
        ->and($item->due_date?->toDateString())->toBe('2026-12-01')
        ->and($item->labels->modelKeys())->toBe([$fresh->id]);
});

test('quick change opens prefilled with the item s current values', function () {
    $assignee = User::factory()->create();
    $label = Label::create(['name' => 'Bug', 'color' => '#ef4444']);

    $item = Item::factory()->create([
        'reporter_id' => auth()->id(),
        'assignee_id' => $assignee->id,
        'status' => ItemStatus::Review,
        'due_date' => '2026-12-24',
    ]);

    $item->labels()->attach($label);

    Livewire::test(Sent::class)
        ->mountAction(TestAction::make('quickChange')->table($item))
        ->assertSchemaStateSet([
            'status' => ItemStatus::Review,
            'assignee_id' => $assignee->id,
            'labels' => [$label->id],
            'due_date' => '2026-12-24',
        ]);
});
